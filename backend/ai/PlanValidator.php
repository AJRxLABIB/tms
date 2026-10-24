<?php
/**
 * PlanValidator — deterministic checking, repair and costing of an itinerary AFTER the model wrote it, plus a
 * model-free fallback builder. Pure PHP: no database, no network, no model. Same input => same output.
 *
 * What the model is trusted for: ideas and wording. What it is NOT trusted for: arithmetic, prices, opening hours,
 * whether a place is bookable, or whether the day fits in 24 hours. Those are decided here, from the catalogue rows the
 * caller passes in (real rows from tblhotels / tblactivities / packages) and from the traveller's own constraints.
 *
 * Result status (plan['validation']['status']):
 *   ok          nothing needed changing and nothing is wrong
 *   adjusted    times were shifted / items reordered / prices replaced by catalogue prices; plan is feasible
 *   infeasible  the plan cannot work as asked (missing days, known costs above budget, visit when closed, day past midnight).
 *               Reasons are listed; nothing is invented to hide the problem.
 *
 * Catalogue row shape (all optional except name/kind):
 *   ['kind'=>'hotel'|'activity'|'package', 'id'=>int, 'name'=>string, 'price'=>int|null,
 *    'price_unit'=>'per_night'|'per_person'|'total', 'duration_min'=>int|null, 'sample'=>bool,
 *    'lat'=>?float, 'lng'=>?float, 'opens'=>?'HH:MM', 'closes'=>?'HH:MM', 'indoor'=>?bool]
 */
require_once __DIR__ . '/ItineraryChecker.php';
require_once __DIR__ . '/../services/RouteOptimizer.php';

final class PlanValidator
{
    public const MODES = ['standard', 'relaxed', 'family', 'accessible', 'budget'];
    private const TRANSFER_KMH = 30;      // average door-to-door speed assumed for transfers (stated, not measured)
    private const TRANSFER_FIXED_MIN = 10; // parking / walking / waiting
    private const DEFAULT_DURATION = ['sightseeing' => 120, 'activity' => 120, 'meal' => 60, 'transport' => 45, 'accommodation' => 0];
    private const MEAL_ESTIMATE = 300;     // BDT per person per meal when the plan has no catalogue price: labelled as an estimate

    /** Constraints that each travel mode changes. */
    public static function constraints(string $mode): array
    {
        $base = ['max_items' => 6, 'earliest' => 6 * 60, 'latest_end' => 22 * 60, 'max_activity_min' => 600, 'buffer' => 10, 'avoid' => [], 'slots' => [9 * 60, 15 * 60]];
        return match ($mode) {
            'relaxed'    => ['max_items' => 3, 'earliest' => 8 * 60 + 30, 'latest_end' => 20 * 60, 'max_activity_min' => 360, 'buffer' => 30, 'slots' => [10 * 60, 15 * 60 + 30]] + $base,
            'family'     => ['max_items' => 4, 'earliest' => 8 * 60, 'latest_end' => 20 * 60, 'max_activity_min' => 420, 'buffer' => 20] + $base,
            'accessible' => ['max_items' => 3, 'earliest' => 8 * 60 + 30, 'latest_end' => 19 * 60, 'max_activity_min' => 360, 'buffer' => 30,
                             'avoid' => ['hike', 'trek', 'climb', 'stairs', 'steps', 'cave', 'rafting', 'zipline', 'rappel'], 'slots' => [10 * 60, 15 * 60]] + $base,
            'budget'     => ['max_items' => 5] + $base,
            default      => $base,
        };
    }

    /** Explicit mode wins; otherwise a conservative keyword read of the traveller's own words. Never guesses beyond that. */
    public static function normalizeMode(array $trip): string
    {
        $m = strtolower(trim((string)($trip['mode'] ?? '')));
        if (in_array($m, self::MODES, true)) return $m;
        $text = strtolower(trim((string)($trip['travel_type'] ?? '') . ' ' . (string)($trip['special_requests'] ?? '')));
        if (preg_match('/wheelchair|accessible|mobility|disab/', $text)) return 'accessible';
        if (preg_match('/\b(family|kids?|children|child)\b/', $text)) return 'family';
        if (preg_match('/relax|slow pace|slow-paced|easy pace/', $text)) return 'relaxed';
        if (preg_match('/\bbudget\b|cheap|low cost|low-cost/', $text)) return 'budget';
        return 'standard';
    }

    private static function norm(string $s): string
    {
        $s = mb_strtolower(trim($s), 'UTF-8');
        $s = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $s) ?? $s;
        return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
    }

    /** Best catalogue row for a free-text reference, or null. Needs the row name (or most of it) to appear: no fuzzy guessing. */
    public static function matchCatalogue(string $text, array $catalogue, ?string $kind = null): ?array
    {
        $t = self::norm($text);
        if ($t === '') return null;
        $best = null; $bestScore = 0.0;
        foreach ($catalogue as $row) {
            if (!is_array($row) || empty($row['name'])) continue;
            if ($kind !== null && ($row['kind'] ?? '') !== $kind) continue;
            $n = self::norm((string)$row['name']);
            if ($n === '') continue;
            if (str_contains($t, $n)) { $score = 1.0; }
            else {
                $tok = array_values(array_filter(explode(' ', $n), fn($w) => mb_strlen($w, 'UTF-8') > 2));
                if (count($tok) < 2) continue;                       // single-word names only match exactly (above)
                $hit = 0; foreach ($tok as $w) if (str_contains(' ' . $t . ' ', ' ' . $w . ' ')) $hit++;
                $score = $hit / count($tok);
                if ($score < 0.8) continue;
            }
            if ($score > $bestScore) { $bestScore = $score; $best = $row; }
        }
        return $best;
    }

    private static function hhmm(int $m): string { return sprintf('%02d:%02d', intdiv(max(0, $m), 60) % 24, max(0, $m) % 60); }

    private static function isOutdoor(array $it, ?array $row): bool
    {
        if ($row !== null && isset($row['indoor'])) return !$row['indoor'];
        $type = $it['type'] ?? '';
        if (!in_array($type, ['sightseeing', 'activity'], true)) return false;
        return !preg_match('/museum|mall|aquarium|gallery|theatre|theater|cinema|indoor|spa|library|restaurant|cafe/i', (string)($it['activity'] ?? '') . ' ' . (string)($it['location'] ?? ''));
    }

    /**
     * Validate and repair $plan against the trip request. Returns the plan with 'validation', 'computed_cost' and
     * (when a budget was given) 'budget_check' added; 'estimated_cost' is replaced by the computed figures and the model's own
     * is kept as 'ai_estimated_cost'. Never throws on malformed model output.
     *
     * @param array $opts ['weather'=>[date=>['rain_pct'=>int]], 'geocoder'=>callable(string):?array{lat,lng}]
     */
    public static function finalize(array $plan, array $trip, array $catalogue = [], array $opts = []): array
    {
        $mode = self::normalizeMode($trip);
        $c = self::constraints($mode);
        $travellers = max(1, (int)($trip['travelers'] ?? $trip['travellers'] ?? 2));
        $rooms = (int)ceil($travellers / 2);
        $requested = (int)($trip['duration'] ?? 0);
        $budget = isset($trip['budget']) && is_numeric($trip['budget']) ? (float)$trip['budget'] : 0.0;
        $weather = is_array($opts['weather'] ?? null) ? $opts['weather'] : null;
        $geo = is_callable($opts['geocoder'] ?? null) ? $opts['geocoder'] : null;

        $reasons = []; $issues = []; $adjust = []; $checked = ['day_count', 'times', 'overlap', 'costs']; $skipped = [];
        $issue = function (string $sev, ?int $day, string $code, string $msg) use (&$issues) { $issues[] = ['severity' => $sev, 'day' => $day, 'code' => $code, 'message' => $msg]; };
        $note = function (?int $day, string $code, string $msg) use (&$adjust) { $adjust[] = ['day' => $day, 'code' => $code, 'message' => $msg]; };

        $days = is_array($plan['days'] ?? null) ? array_values($plan['days']) : [];
        if ($requested > 0) {
            if (count($days) > $requested) { $days = array_slice($days, 0, $requested); $note(null, 'extra_days_removed', 'The plan had more days than requested; extra days were removed.'); }
            if (count($days) < $requested) $reasons[] = ['code' => 'missing_days', 'message' => 'The plan covers ' . count($days) . ' of ' . $requested . ' requested day(s). Missing days are not invented.'];
        } else { $skipped[] = 'day_count (no requested duration)'; }

        $catMatched = 0; $catUnmatched = 0;
        $tot = ['accommodation' => 0.0, 'transport' => 0.0, 'food' => 0.0, 'activities' => 0.0];
        $catalogueTotal = 0.0; $estimateTotal = 0.0; $lineItems = [];
        $anyCoords = false; $openingChecked = false;

        foreach ($days as $di => &$day) {
            $dn = $di + 1;
            if (!is_array($day)) { $day = ['day' => $dn, 'schedule' => []]; }
            $day['day'] = $dn;
            $sched = is_array($day['schedule'] ?? null) ? array_values(array_filter($day['schedule'], 'is_array')) : [];

            // 1) normalise rows, match the catalogue, price in code
            $rows = [];
            foreach ($sched as $pos => $s) {
                $type = strtolower((string)($s['type'] ?? 'activity'));
                if (!isset(self::DEFAULT_DURATION[$type])) $type = 'activity';
                $text = (string)($s['activity'] ?? '') . ' ' . (string)($s['location'] ?? '');
                $kind = $type === 'accommodation' ? 'hotel' : (in_array($type, ['sightseeing', 'activity'], true) ? 'activity' : null);
                $row = $kind !== null ? self::matchCatalogue($text, $catalogue, $kind) : null;
                $t = ItineraryChecker::parseTime($s['time'] ?? null);
                $dur = ItineraryChecker::parseDuration($s['duration'] ?? null);
                if ($dur === null && $row && !empty($row['duration_min'])) $dur = (int)$row['duration_min'];
                if ($dur === null) $dur = self::DEFAULT_DURATION[$type];
                $modelCost = isset($s['cost']) && is_numeric($s['cost']) ? max(0.0, (float)$s['cost']) : 0.0;
                $line = 0.0; $basis = 'estimate'; $cat = $type === 'meal' ? 'food' : ($type === 'transport' ? 'transport' : ($type === 'accommodation' ? 'accommodation' : 'activities'));
                if ($row && isset($row['price']) && is_numeric($row['price'])) {
                    $unit = $row['price_unit'] ?? ($kind === 'hotel' ? 'per_night' : 'per_person');
                    $line = (float)$row['price'] * ($unit === 'per_person' ? $travellers : ($unit === 'per_night' ? $rooms : 1));
                    $basis = 'catalogue';
                    if ($modelCost > 0 && abs($modelCost - (float)$row['price']) > 0.01 * max(1.0, (float)$row['price']) && $unit === 'per_person')
                        $note($dn, 'price_replaced', '"' . mb_substr((string)($s['activity'] ?? ''), 0, 50) . '": the model said ' . number_format($modelCost) . ' per person; the catalogue price ' . number_format((float)$row['price']) . ' is used.');
                } elseif ($type === 'meal') {
                    $line = ($modelCost > 0 ? $modelCost : self::MEAL_ESTIMATE) * $travellers;
                } else {
                    $line = $modelCost * ($type === 'transport' || $type === 'accommodation' ? 1 : $travellers);
                    if ($type === 'transport' || $type === 'accommodation') { /* model figure is treated as a group total for these */ }
                }
                if ($kind !== null) { $row ? $catMatched++ : $catUnmatched++; }
                $lat = $row['lat'] ?? null; $lng = $row['lng'] ?? null;
                if (($lat === null || $lng === null) && $geo && ($s['location'] ?? '') !== '' && in_array($type, ['sightseeing', 'activity'], true)) {
                    $g = $geo((string)$s['location']); if (is_array($g) && isset($g['lat'], $g['lng'])) { $lat = (float)$g['lat']; $lng = (float)$g['lng']; }
                }
                if ($lat !== null && $lng !== null) $anyCoords = true;
                $rows[] = array_merge($s, [
                    'type' => $type, '_t' => $t, '_dur' => $dur, '_pos' => $pos, '_row' => $row, '_lat' => $lat, '_lng' => $lng,
                    'source' => $kind === null ? ($s['source'] ?? 'plan') : ($row ? 'catalogue' : 'suggestion (not bookable)'),
                    'catalogue_id' => $row['id'] ?? null, 'sample_data' => $row ? (bool)($row['sample'] ?? false) : null,
                    'cost_basis' => $basis, 'line_total' => (int)round($line), '_cat' => $cat,
                ]);
            }

            // 2) route order for located sightseeing/activity stops (slots keep their times; stops move between slots)
            $idx = []; foreach ($rows as $k => $r) if (in_array($r['type'], ['sightseeing', 'activity'], true) && $r['_lat'] !== null) $idx[] = $k;
            if (count($idx) >= 3) {
                usort($idx, fn($a, $b) => [$rows[$a]['_t'] ?? 9999, $a] <=> [$rows[$b]['_t'] ?? 9999, $b]);
                $stops = array_map(fn($k) => ['id' => $k, 'lat' => $rows[$k]['_lat'], 'lng' => $rows[$k]['_lng']], $idx);
                $opt = RouteOptimizer::optimize($stops, true, false);
                if (!empty($opt['changed'])) {
                    $slotTimes = array_map(fn($k) => $rows[$k]['_t'], $idx);
                    $new = []; foreach ($opt['order'] as $n => $k) { $r = $rows[$k]; $r['_t'] = $slotTimes[$n]; $new[$idx[$n]] = $r; }
                    foreach ($new as $pos => $r) $rows[$pos] = $r;
                    $note($dn, 'route_reordered', sprintf('Stops reordered to remove backtracking (about %.1f km less in a straight line).', $opt['saved_km']));
                }
            }

            // 3) time order, overlap / transfer repair, opening hours, mode limits
            $timed = []; $untimed = [];
            foreach ($rows as $k => $r) { if ($r['type'] === 'accommodation' && $r['_t'] === null) { $untimed[] = $r; continue; } $timed[] = $r + ['_k' => $k]; }
            $prevT = $c['earliest'];
            foreach ($timed as &$r) {                      // rows without a time are placed right after the previous item instead of dropped
                if ($r['_t'] === null) { $r['_t'] = $prevT; $note($dn, 'time_filled', 'No start time for "' . mb_substr((string)($r['activity'] ?? ''), 0, 40) . '"; placed at ' . self::hhmm($r['_t']) . '.'); }
                $prevT = $r['_t'] + $r['_dur'];
            }
            unset($r);
            usort($timed, fn($a, $b) => [$a['_t'], $a['_k']] <=> [$b['_t'], $b['_k']]);
            $prevEnd = null; $prev = null; $activityMin = 0; $itemsCount = 0;
            foreach ($timed as &$r) {
                $isCore = $r['type'] !== 'accommodation';
                if ($isCore && $r['_t'] < $c['earliest']) { $note($dn, 'moved_later', '"' . mb_substr((string)($r['activity'] ?? ''), 0, 40) . '" moved from ' . self::hhmm($r['_t']) . ' to ' . self::hhmm($c['earliest']) . ' (' . $mode . ' mode).'); $r['_t'] = $c['earliest']; }
                $need = $prevEnd === null ? null : $prevEnd + ($prev && $prev['_lat'] !== null && $r['_lat'] !== null
                    ? (int)round(RouteOptimizer::haversineKm($prev['_lat'], $prev['_lng'], $r['_lat'], $r['_lng']) / self::TRANSFER_KMH * 60) + self::TRANSFER_FIXED_MIN
                    : $c['buffer']);
                if ($prev && $prev['_lat'] !== null && $r['_lat'] !== null) { $r['transfer_min'] = $need - $prevEnd; $checked[] = 'transfer'; }
                if ($need !== null && $r['_t'] < $need && $isCore) { $note($dn, 'shifted', '"' . mb_substr((string)($r['activity'] ?? ''), 0, 40) . '" moved from ' . self::hhmm($r['_t']) . ' to ' . self::hhmm($need) . ' to fit the previous item and the transfer.'); $r['_shifted_from'] = $r['_t']; $r['_t'] = $need; }
                // opening hours (only where the catalogue states them)
                if ($r['_row'] && (!empty($r['_row']['opens']) || !empty($r['_row']['closes']))) {
                    $openingChecked = true;
                    $o = ItineraryChecker::parseTime($r['_row']['opens'] ?? null); $cl = ItineraryChecker::parseTime($r['_row']['closes'] ?? null);
                    if ($o !== null && $r['_t'] < $o) { $note($dn, 'opens_later', '"' . mb_substr((string)($r['activity'] ?? ''), 0, 40) . '" opens at ' . self::hhmm($o) . '; start moved.'); $r['_t'] = $o; }
                    if ($cl !== null && ($r['_t'] >= $cl || $r['_t'] + $r['_dur'] > $cl)) {
                        $issue('error', $dn, 'closed', '"' . mb_substr((string)($r['activity'] ?? ''), 0, 40) . '" closes at ' . self::hhmm($cl) . ' but is scheduled ' . self::hhmm($r['_t']) . '-' . self::hhmm($r['_t'] + $r['_dur']) . '.');
                        $reasons[] = ['code' => 'closed', 'message' => 'Day ' . $dn . ': "' . mb_substr((string)($r['activity'] ?? ''), 0, 40) . '" would be closed at the scheduled time.'];
                    }
                }
                if ($isCore) { $prevEnd = $r['_t'] + $r['_dur']; $prev = $r; }
                if ($isCore && !in_array($r['type'], ['meal', 'transport'], true)) { $activityMin += $r['_dur']; $itemsCount++; }
                if ($isCore && $r['_t'] + $r['_dur'] > 24 * 60) { $reasons[] = ['code' => 'past_midnight', 'message' => 'Day ' . $dn . ' cannot be scheduled before midnight even after shifting.']; }
                elseif ($isCore && $r['_t'] + $r['_dur'] > $c['latest_end']) $issue('warning', $dn, 'late_finish', 'Ends at ' . self::hhmm($r['_t'] + $r['_dur']) . ', later than ' . self::hhmm($c['latest_end']) . ' for ' . $mode . ' mode.');
                if ($c['avoid'] && preg_match('/' . implode('|', array_map('preg_quote', $c['avoid'])) . '/i', (string)($r['activity'] ?? '') . ' ' . (string)($r['notes'] ?? '')))
                    $issue('warning', $dn, 'accessibility', '"' . mb_substr((string)($r['activity'] ?? ''), 0, 40) . '" may not suit an accessible trip (mentions a physical-effort activity). Check access before booking.');
                if ($weather !== null && !empty($day['date']) && isset($weather[substr((string)$day['date'], 0, 10)]) && self::isOutdoor($r, $r['_row'])) {
                    $w = $weather[substr((string)$day['date'], 0, 10)]; $checked[] = 'weather';
                    if ((int)($w['rain_pct'] ?? 0) >= 60) $issue('warning', $dn, 'weather_outdoor', 'Outdoor item "' . mb_substr((string)($r['activity'] ?? ''), 0, 40) . '" on a day with ' . (int)$w['rain_pct'] . '% forecast rain. Consider an indoor alternative or another day.');
                }
            }
            unset($r);
            if ($itemsCount > $c['max_items']) $issue('warning', $dn, 'too_many_items', $itemsCount . ' activities in one day; ' . $mode . ' mode suggests at most ' . $c['max_items'] . '.');
            if ($activityMin > $c['max_activity_min']) $issue('warning', $dn, 'overloaded_day', sprintf('%.1f hours of activities; %s mode suggests at most %.1f.', $activityMin / 60, $mode, $c['max_activity_min'] / 60));

            // 4) write back clean rows (internal keys removed), compute totals in code
            $out = []; $dayTotal = 0;
            foreach (array_merge($timed, $untimed) as $r) {
                $final = array_filter($r, fn($key) => !is_string($key) || $key === '' || $key[0] !== '_', ARRAY_FILTER_USE_KEY);
                if ($r['_t'] !== null) $final['time'] = self::hhmm($r['_t']);
                $final['duration'] = $r['_dur'] > 0 ? ($r['_dur'] % 60 === 0 ? ($r['_dur'] / 60) . ' hours' : $r['_dur'] . ' min') : ($final['duration'] ?? '');
                $final['cost'] = $travellers > 0 ? (int)round($r['line_total'] / $travellers) : $r['line_total'];   // per person, computed
                if (isset($r['_shifted_from'])) $final['adjusted'] = true;
                $out[] = $final; $dayTotal += $r['line_total'];
                $tot[$r['_cat']] += $r['line_total'];
                if ($r['cost_basis'] === 'catalogue') $catalogueTotal += $r['line_total']; else $estimateTotal += $r['line_total'];
                $lineItems[] = ['day' => $dn, 'name' => (string)($r['activity'] ?? ''), 'total' => $r['line_total'], 'basis' => $r['cost_basis'], 'type' => $r['type']];
            }
            $day['schedule'] = $out;
            $day['daily_budget_ai'] = $day['daily_budget'] ?? null;
            $day['daily_budget'] = (int)$dayTotal;
        }
        unset($day);

        if (!$anyCoords) $skipped[] = 'transfer and route order (no coordinates for these places)';
        if (!$openingChecked) $skipped[] = 'opening hours (the catalogue has none for these places)';
        if ($weather === null) $skipped[] = 'weather (no forecast data supplied)'; else $checked[] = 'weather';

        // 5) totals, budget, trade-offs
        $total = (int)round($tot['accommodation'] + $tot['transport'] + $tot['food'] + $tot['activities']);
        $computed = [
            'accommodation' => (int)round($tot['accommodation']), 'transport' => (int)round($tot['transport']), 'food' => (int)round($tot['food']),
            'activities' => (int)round($tot['activities']), 'miscellaneous' => 0, 'total' => $total,
            'currency' => 'BDT', 'travellers' => $travellers,
            'verified_from_catalogue' => (int)round($catalogueTotal), 'estimated' => (int)round($estimateTotal),
            'note' => 'Computed in code. Only items matched to a catalogue row carry a catalogue price; the rest are estimates. Accommodation is counted only where a catalogue hotel is scheduled; miscellaneous is not estimated.',
        ];
        $budgetCheck = null;
        if ($budget > 0) {
            $status = $total <= $budget ? 'within_budget' : 'over_budget';
            $trade = [];
            if ($status === 'over_budget') {
                usort($lineItems, fn($a, $b) => $b['total'] <=> $a['total']);
                foreach (array_slice(array_filter($lineItems, fn($l) => $l['total'] > 0 && $l['type'] !== 'meal'), 0, 3) as $l)
                    $trade[] = 'Day ' . $l['day'] . ' "' . mb_substr($l['name'], 0, 50) . '" costs about ' . number_format($l['total']) . ' (' . $l['basis'] . '). Dropping or swapping it would save that much.';
                if ($mode !== 'budget') $trade[] = 'Switching to budget mode asks for cheaper options and fewer paid activities.';
            }
            $budgetCheck = ['budget' => (int)round($budget), 'computed_total' => $total, 'status' => $status, 'over_by' => max(0, $total - (int)round($budget)), 'trade_offs' => $trade];
            if ($catalogueTotal > $budget) $reasons[] = ['code' => 'known_costs_over_budget', 'message' => 'Items with real catalogue prices alone (' . number_format((int)$catalogueTotal) . ') already exceed the budget (' . number_format((int)$budget) . ').'];
            elseif ($status === 'over_budget') $issue('warning', null, 'over_budget', 'Computed total ' . number_format($total) . ' is above the budget ' . number_format((int)$budget) . '; see trade-offs.');
        } else { $skipped[] = 'budget (none given)'; }

        if ($catUnmatched > 0) $issue('info', null, 'unmatched_items', $catUnmatched . ' place(s) are suggestions, not found in our catalogue, so they are not bookable here and their prices are estimates.');

        $status = $reasons ? 'infeasible' : ($adjust ? 'adjusted' : 'ok');
        if (isset($plan['estimated_cost'])) $plan['ai_estimated_cost'] = $plan['estimated_cost'];
        $plan['estimated_cost'] = $computed;
        $plan['computed_cost'] = $computed;
        if ($budgetCheck) $plan['budget_check'] = $budgetCheck;
        $plan['days'] = $days;
        $plan['total_duration'] = count($days);
        $plan['validation'] = [
            'status' => $status, 'mode' => $mode, 'reasons' => $reasons, 'issues' => $issues, 'adjustments' => $adjust,
            'checked' => array_values(array_unique($checked)), 'skipped' => $skipped,
            'catalogue_matched' => $catMatched, 'catalogue_unmatched' => $catUnmatched,
            'note' => 'Checked by code, not by the model. "ok" or "adjusted" means no contradictions were found by these checks, not that the trip is guaranteed.',
        ];
        return $plan;
    }

    /**
     * Model-free itinerary built only from catalogue rows. Returns null when there is nothing real to build from
     * (the caller then says so instead of inventing a plan). The result is passed through finalize() so costs/times are
     * computed the same way as for a model plan.
     */
    public static function buildFallback(array $trip, array $catalogue, array $opts = []): ?array
    {
        $dest = (string)($trip['destination'] ?? 'Your destination');
        $duration = max(1, min(30, (int)($trip['duration'] ?? 3)));
        $mode = self::normalizeMode($trip); $c = self::constraints($mode);
        $acts = array_values(array_filter($catalogue, fn($r) => is_array($r) && ($r['kind'] ?? '') === 'activity' && !empty($r['name'])));
        if (!$acts) return null;
        $hotels = array_values(array_filter($catalogue, fn($r) => is_array($r) && ($r['kind'] ?? '') === 'hotel' && !empty($r['name']) && isset($r['price'])));
        usort($acts, fn($a, $b) => [-($a['rating'] ?? 0), $a['id'] ?? 0] <=> [-($b['rating'] ?? 0), $b['id'] ?? 0]);
        usort($hotels, fn($a, $b) => [$a['price'], $a['id'] ?? 0] <=> [$b['price'], $b['id'] ?? 0]);
        $hotel = null;
        $budget = isset($trip['budget']) && is_numeric($trip['budget']) ? (float)$trip['budget'] : 0.0;
        if ($hotels) {
            $hotel = $hotels[0];
            if ($mode !== 'budget') { foreach ($hotels as $h) { if ($budget <= 0 || $h['price'] * max(1, $duration - 1) <= $budget * 0.4) $hotel = $h; } }   // best hotel that stays within ~40% of the budget
        }
        $perDay = min(count($c['slots']), $c['max_items']);
        $start = !empty($trip['start_date']) && strtotime((string)$trip['start_date']) ? strtotime((string)$trip['start_date']) : null;
        $days = []; $cursor = 0;
        for ($d = 1; $d <= $duration; $d++) {
            $todays = [];
            for ($s = 0; $s < $perDay; $s++) { $todays[] = $acts[$cursor % count($acts)]; $cursor++; }
            if (count($todays) >= 3) {
                $stops = array_map(fn($r, $i) => ['id' => $i, 'lat' => $r['lat'] ?? null, 'lng' => $r['lng'] ?? null], $todays, array_keys($todays));
                $o = RouteOptimizer::optimize($stops, true, false);
                if ($o['changed']) { $re = []; foreach ($o['order'] as $i) $re[] = $todays[$i]; foreach ($o['unplaced'] as $i) $re[] = $todays[$i]; $todays = $re; }
            }
            $sched = [];
            foreach ($todays as $i => $r) {
                $sched[] = ['time' => self::hhmm($c['slots'][$i] ?? 9 * 60), 'activity' => $r['name'], 'location' => $r['name'], 'duration' => ($r['duration_min'] ?? 120) . ' min', 'cost' => 0, 'type' => 'activity', 'notes' => !empty($r['sample']) ? 'Demo data in our catalogue.' : ''];
                if ($i === 0) $sched[] = ['time' => '13:00', 'activity' => 'Lunch', 'location' => '', 'duration' => '1 hour', 'cost' => 0, 'type' => 'meal', 'notes' => 'Estimate only.'];
            }
            if ($hotel && $d < $duration) $sched[] = ['time' => '', 'activity' => 'Stay at ' . $hotel['name'], 'location' => $hotel['name'], 'duration' => '', 'cost' => 0, 'type' => 'accommodation', 'notes' => 'Hotel night'];
            $days[] = ['day' => $d, 'date' => $start ? date('Y-m-d', strtotime('+' . ($d - 1) . ' day', $start)) : '', 'title' => $d === 1 ? 'Arrival & first sights' : ($d === $duration ? 'Final day' : 'Exploration day ' . $d), 'schedule' => $sched];
        }
        $plan = [
            'is_fallback' => true, 'fallback_kind' => 'catalogue_template',
            'trip_title' => 'Draft plan for ' . $dest, 'destination' => $dest, 'total_duration' => $duration, 'days' => $days,
            'hotel_recommendations' => $hotel ? [['name' => $hotel['name'], 'tier' => 'From our catalogue', 'price_range' => 'BDT ' . number_format((int)$hotel['price']) . '/night', 'why' => 'Lowest-risk match to your budget', 'verified' => true]] : [],
            'restaurant_recommendations' => [], 'packing_essentials' => ['Passport/ID', 'Sunscreen', 'Comfortable shoes', 'First aid kit'],
            'ai_notes' => 'The AI planner was not available, so this draft was assembled by code from our own catalogue (places ranked by rating, ordered to reduce backtracking). It is not personalised. Prices are catalogue prices where shown; meals are estimates.',
        ];
        return self::finalize($plan, $trip, $catalogue, $opts);
    }
}
