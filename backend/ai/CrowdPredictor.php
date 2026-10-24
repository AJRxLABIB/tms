<?php
/**
 * CrowdPredictor.php — Crowd Prediction & Best Visiting Time
 *
 * Like BudgetOptimizer::predictPackagePrice(), the core prediction here is
 * deterministic — real booking-date distribution from this platform's own
 * `tblbooking` history, not an AI guess. An AI narrative (which accounts for
 * things booking data alone can't, like climate and festival timing) is
 * layered on top only when there's enough real data to be worth grounding,
 * and the response always says plainly which mode it's in.
 */

require_once __DIR__ . '/AIClient.php';

class CrowdPredictor {

    private AIClient $ai;
    private PDO      $db;

    /** Below this many historical bookings for a destination, month-by-month
     *  counts are too sparse to mean anything (a single outlier booking would
     *  look like a "busy month") — fall back to pure AI seasonal knowledge
     *  instead of presenting noise as a real pattern. */
    private const MIN_BOOKINGS_FOR_REAL_DATA = 8;

    public function __construct(PDO $db) {
        $this->db = $db;
        $this->ai = new AIClient($db);
    }

    /**
     * Real month-by-month booking counts for a destination, from actual
     * booking history (join tblbooking -> tbltourpackages on location match).
     * Returns raw counts per calendar month (1-12), summed across all years
     * of data available — enough for a seasonality pattern, not a
     * year-specific forecast.
     */
    public function getBookingSeasonality(string $destination): array {
        try {
            $stmt = $this->db->prepare(
                "SELECT MONTH(b.BookingDate) AS m, COUNT(*) AS cnt
                 FROM tblbooking b
                 JOIN tbltourpackages p ON p.PackageId = b.PackageId
                 WHERE p.PackageLocation LIKE ? AND b.BookingDate IS NOT NULL AND b.status != 2
                 GROUP BY MONTH(b.BookingDate)"
            );
            $stmt->execute(['%' . $destination . '%']);
            $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR); // [month => count]

            $months = [];
            for ($m = 1; $m <= 12; $m++) {
                $months[$m] = (int)($rows[$m] ?? 0);
            }
            return $months;
        } catch (Exception $e) {
            error_log('[TourBuddy CrowdPredictor] getBookingSeasonality failed: ' . $e->getMessage());
            return array_fill(1, 12, 0);
        }
    }

    /**
     * Predicts the best (and worst) time to visit a destination.
     *
     * @return array{
     *   data_source: 'historical_bookings'|'ai_estimate',
     *   total_bookings_analysed: int,
     *   busiest_months: string[],
     *   quietest_months: string[],
     *   ai_narrative: string
     * }
     */
    public function predictBestVisitingTime(string $destination, string $email = 'guest'): array {
        $monthNames = [1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',
                       7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December'];

        $seasonality = $this->getBookingSeasonality($destination);
        $total = array_sum($seasonality);

        if ($total >= self::MIN_BOOKINGS_FOR_REAL_DATA) {
            // Real data mode: rank months by actual booking volume.
            arsort($seasonality); // highest count first, preserves month keys
            $ranked = array_keys($seasonality);
            $busiest  = array_slice($ranked, 0, 3);
            $quietest = array_slice(array_reverse($ranked), 0, 3);

            $busiestNames  = array_map(fn($m) => $monthNames[$m], $busiest);
            $quietestNames = array_map(fn($m) => $monthNames[$m], $quietest);

            $narrative = $this->getNarrative($destination, $busiestNames, $quietestNames, $email, true);

            return [
                'data_source'             => 'historical_bookings',
                'total_bookings_analysed' => $total,
                'busiest_months'          => $busiestNames,
                'quietest_months'         => $quietestNames,
                'ai_narrative'            => $narrative,
            ];
        }

        // Not enough real booking history yet — be upfront that this is a
        // general estimate, not a pattern observed in actual platform data.
        $narrative = $this->getNarrative($destination, [], [], $email, false);
        return [
            'data_source'             => 'ai_estimate',
            'total_bookings_analysed' => $total,
            'busiest_months'          => [],
            'quietest_months'         => [],
            'ai_narrative'            => $narrative,
        ];
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function getNarrative(string $destination, array $busiest, array $quietest, string $email, bool $hasRealData): string {
        $rawDestination = $destination;
        $destination = trim(mb_substr(str_replace(['{', '}', '<', '>', '`', '"'], ' ', preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $destination) ?? ''), 0, 100, 'UTF-8')) ?: 'this destination';
        $cacheKey = 'crowd_narrative_' . md5($destination . '_' . implode(',', $busiest) . '_' . implode(',', $quietest));

        if ($hasRealData) {
            $system = "You are a travel seasonality expert. Given REAL booking data for a destination, explain briefly WHY those months are busiest/quietest (weather, festivals, holidays, school breaks) and confirm or add nuance to the pattern. 2-3 sentences.";
            $userMsg = "Destination: {$destination}. Our actual booking history shows busiest months are: " . implode(', ', $busiest)
                     . ". Quietest months are: " . implode(', ', $quietest) . ". Briefly explain likely reasons and give one practical tip for a traveler choosing when to go.";
        } else {
            $system = "You are a travel seasonality expert. We don't have enough booking history for this destination yet, so give your best general-knowledge estimate of the best and worst times to visit. Be clear this is a general estimate. 2-3 sentences.";
            $userMsg = "Destination: {$destination}. Give a general best-time-to-visit estimate (weather, festivals, crowds) since we don't have platform booking history for this one yet.";
        }

        $result = $this->ai->ask($system, $userMsg, $cacheKey, $email, feature: 'crowd');
        if (!$result['success']) {
            return $hasRealData
                ? "Based on actual booking activity, {$busiest[0]} tends to be the busiest month and {$quietest[0]} the quietest for {$destination}."
                : "Seasonal guidance for {$destination} isn't available right now — check the Weather Guide closer to your travel date.";
        }
        return trim($result['content']);
    }
}
