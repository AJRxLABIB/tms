<?php
/**
 * AchievementEngine.php — Gamification with achievements and rewards
 *
 * Deliberately has ZERO AI/LLM involvement. Achievement criteria need to be
 * objective and fair — "did you complete 5 bookings" is either true or
 * false, and a user would rightly be upset if an AI's fuzzy judgment
 * decided whether they'd earned something. Every criterion here is an exact
 * SQL count against real activity (bookings, reviews, referrals, wishlist
 * size) already recorded elsewhere in this app — no new tracking needed for
 * this pass, this only reads.
 *
 * Achievements are evaluated lazily (called from loyalty.php on page view)
 * and persisted once earned in `tblachievements` — once unlocked, an
 * achievement stays unlocked even if the underlying activity later changes
 * (e.g. a booking gets cancelled after "Explorer" was earned). This matches
 * how virtually every real gamification system works and avoids the bad UX
 * of an achievement disappearing from someone's profile.
 */

class AchievementEngine {

    private PDO $db;

    /**
     * Achievement definitions. Each 'check' callable receives the PDO and
     * email, and returns true if the criterion is currently met. Kept as a
     * flat array (not a DB table) since these are code-defined rules, not
     * user data — the DB only stores WHO has unlocked WHICH of these.
     */
    private array $definitions;

    public function __construct(PDO $db) {
        $this->db = $db;
        $this->definitions = [
            'first_booking' => [
                'name' => 'First Steps', 'icon' => 'flag-checkered', 'color' => '#3b82f6',
                'description' => 'Complete your first booking',
                'check' => fn($email) => $this->countBookings($email) >= 1,
            ],
            'frequent_traveler' => [
                'name' => 'Frequent Traveler', 'icon' => 'plane', 'color' => '#8b5cf6',
                'description' => 'Complete 5 bookings',
                'check' => fn($email) => $this->countBookings($email) >= 5,
            ],
            'explorer' => [
                'name' => 'Explorer', 'icon' => 'compass', 'color' => '#10b981',
                'description' => 'Visit 3 different destinations',
                'check' => fn($email) => $this->countDistinctDestinations($email) >= 3,
            ],
            'globe_trotter' => [
                'name' => 'Globe Trotter', 'icon' => 'globe', 'color' => '#059669',
                'description' => 'Visit 6 different destinations',
                'check' => fn($email) => $this->countDistinctDestinations($email) >= 6,
            ],
            'first_review' => [
                'name' => 'Reviewer', 'icon' => 'star', 'color' => '#f59e0b',
                'description' => 'Post your first review',
                'check' => fn($email) => $this->countReviews($email) >= 1,
            ],
            'trusted_voice' => [
                'name' => 'Trusted Voice', 'icon' => 'star-o', 'color' => '#d97706',
                'description' => 'Post 5 reviews',
                'check' => fn($email) => $this->countReviews($email) >= 5,
            ],
            'social_butterfly' => [
                'name' => 'Social Butterfly', 'icon' => 'users', 'color' => '#ec4899',
                'description' => 'Refer 3 friends who sign up',
                'check' => fn($email) => $this->countReferrals($email) >= 3,
            ],
            'big_spender' => [
                'name' => 'Big Spender', 'icon' => 'money', 'color' => '#eab308',
                'description' => 'Spend 50,000 BDT or more on trips',
                'check' => fn($email) => $this->totalSpend($email) >= 50000,
            ],
            'planner' => [
                'name' => 'Planner', 'icon' => 'calendar-check-o', 'color' => '#0ea5e9',
                'description' => 'Save 10 packages to your wishlist',
                'check' => fn($email) => $this->countWishlist($email) >= 10,
            ],
            'early_bird' => [
                'name' => 'Early Bird', 'icon' => 'clock-o', 'color' => '#14b8a6',
                'description' => 'Book a trip 30+ days ahead of departure',
                'check' => fn($email) => $this->hasEarlyBooking($email),
            ],
        ];
    }

    public function allDefinitions(): array {
        return $this->definitions;
    }

    /**
     * Checks all criteria for a user, persists any newly-met ones, and
     * returns just the ones unlocked BY THIS CALL (so the caller can show a
     * "you just unlocked X!" notification vs. ones already known).
     */
    public function evaluateAndUnlock(string $email): array {
        $existing = $this->getUnlockedCodes($email);
        $newlyUnlocked = [];

        foreach ($this->definitions as $code => $def) {
            if (isset($existing[$code])) continue; // already unlocked, don't re-check
            try {
                if (($def['check'])($email)) {
                    $this->db->prepare("INSERT INTO tblachievements (UserEmail, Code, UnlockedAt) VALUES (?, ?, NOW())")
                        ->execute([$email, $code]);
                    $newlyUnlocked[] = $code;
                }
            } catch (Exception $e) {
                error_log("[TMS Achievements] Check failed for '$code': " . $e->getMessage());
            }
        }

        return $newlyUnlocked;
    }

    /** Full picture for a profile/rewards page: every achievement with locked/unlocked state. */
    public function getUserAchievements(string $email): array {
        $unlocked = $this->getUnlockedCodes($email);
        $result = [];
        foreach ($this->definitions as $code => $def) {
            $result[$code] = array_merge($def, [
                'code'        => $code,
                'unlocked'    => isset($unlocked[$code]),
                'unlocked_at' => $unlocked[$code] ?? null,
            ]);
            unset($result[$code]['check']); // not serializable/displayable, internal only
        }
        return $result;
    }

    /** Real leaderboard — ranked by achievement count, ties broken by earliest most-recent unlock. */
    public function getLeaderboard(int $limit = 10): array {
        try {
            $stmt = $this->db->prepare(
                "SELECT a.UserEmail, COUNT(*) AS achievement_count, MAX(a.UnlockedAt) AS latest_unlock, u.FullName
                 FROM tblachievements a
                 LEFT JOIN tblusers u ON u.EmailId = a.UserEmail
                 GROUP BY a.UserEmail
                 ORDER BY achievement_count DESC, latest_unlock ASC
                 LIMIT ?"
            );
            $stmt->execute([$limit]);
            return $stmt->fetchAll(PDO::FETCH_OBJ);
        } catch (Exception $e) {
            return [];
        }
    }

    // ── Private: criteria checks (all real SQL, no AI) ─────────────────────────

    private function getUnlockedCodes(string $email): array {
        $stmt = $this->db->prepare("SELECT Code, UnlockedAt FROM tblachievements WHERE UserEmail = ?");
        $stmt->execute([$email]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_OBJ) as $row) $out[$row->Code] = $row->UnlockedAt;
        return $out;
    }

    private function countBookings(string $email): int {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM tblbooking WHERE UserEmail = ? AND status != 2");
        $stmt->execute([$email]);
        return (int)$stmt->fetchColumn();
    }

    private function countDistinctDestinations(string $email): int {
        $stmt = $this->db->prepare(
            "SELECT COUNT(DISTINCT p.PackageLocation) FROM tblbooking b
             JOIN tbltourpackages p ON p.PackageId = b.PackageId
             WHERE b.UserEmail = ? AND b.status != 2"
        );
        $stmt->execute([$email]);
        return (int)$stmt->fetchColumn();
    }

    private function countReviews(string $email): int {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM tblreviews WHERE UserEmail = ?");
        $stmt->execute([$email]);
        return (int)$stmt->fetchColumn();
    }

    private function countReferrals(string $email): int {
        $stmt = $this->db->prepare("SELECT ReferralCode FROM tblusers WHERE EmailId = ? LIMIT 1");
        $stmt->execute([$email]);
        $code = $stmt->fetchColumn();
        if (!$code) return 0;
        $stmt2 = $this->db->prepare("SELECT COUNT(*) FROM tblusers WHERE ReferredBy = ?");
        $stmt2->execute([$code]);
        return (int)$stmt2->fetchColumn();
    }

    private function totalSpend(string $email): int {
        $stmt = $this->db->prepare("SELECT COALESCE(SUM(TotalCost),0) FROM tblbooking WHERE UserEmail = ? AND status != 2");
        $stmt->execute([$email]);
        return (int)$stmt->fetchColumn();
    }

    private function countWishlist(string $email): int {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM tblwishlist WHERE UserEmail = ?");
        $stmt->execute([$email]);
        return (int)$stmt->fetchColumn();
    }

    private function hasEarlyBooking(string $email): bool {
        try {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM tblbooking WHERE UserEmail = ? AND status != 2
                 AND DATEDIFF(BookingDate, RegDate) >= 30"
            );
            $stmt->execute([$email]);
            return (int)$stmt->fetchColumn() >= 1;
        } catch (Exception $e) {
            return false;
        }
    }
}
