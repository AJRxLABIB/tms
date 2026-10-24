<?php
/**
 * PersonalizedHomepage.php — deterministic (no AI call) homepage
 * personalization, built the same way as this session's other new
 * services (TripReadinessScore, BudgetAutopilot): a pure function plus a
 * thin DB-gathering wrapper, real data only, no invented signal.
 *
 * WHY NOT THE EXISTING AI RECOMMENDER (backend/ai/TravelRecommender.php):
 * that class is built for the ai-recommend.php wizard, where the user
 * explicitly supplies preferences (budget, dates, travel type) and
 * expects to wait a moment for a real AI call. Calling it unprompted on
 * every homepage pageview for every logged-in user would mean a real,
 * paid AI API call on every page load -- a genuine cost/latency/
 * reliability concern (Rule 73), not just a style choice. This class
 * instead derives personalization from real signal already sitting in
 * the database (past bookings, wishlist, recently-viewed packages -- the
 * last one already tracked by tblrecentlyviewed, written on every
 * package-details.php view, but until now never read anywhere except
 * that same page) with plain SQL, no AI involved.
 *
 * CONFIRMED REAL GAP (checked before building, not assumed): every
 * logged-in user's homepage "Featured Packages" section
 * (frontend/index.php) has always shown the exact same
 * shuffled-random-8-of-newest-60 pool regardless of who they are --
 * zero personalization despite three real signals (booking history,
 * wishlist, recently-viewed) already existing elsewhere in the app.
 *
 * Signal priority (strongest intent first): a package TYPE the user has
 * actually booked > a type they've wishlisted > a type they've recently
 * viewed. Whichever tier has data picks the type(s); packages already
 * booked or wishlisted by the user are excluded from the results (no
 * point "recommending" something they already have). A user with no
 * signal at all (a brand-new account, or one that's only ever visited
 * without expressing any type preference) gets an empty array back, and
 * the caller keeps its existing fallback behavior unchanged -- this
 * class only ever ADDS a personalized section, it never removes or
 * replaces the working default.
 */
class PersonalizedHomepage
{
    /**
     * @return array{packages: object[], reason: string} reason is one of
     *   'booking_history' | 'wishlist' | 'recently_viewed' | 'none'
     */
    public static function forUser(PDO $dbh, string $email, int $limit = 8): array
    {
        $tiers = [
            'booking_history' => "SELECT DISTINCT p.PackageType FROM tblbooking b
                                   JOIN tbltourpackages p ON p.PackageId = b.PackageId
                                   WHERE b.UserEmail = :e AND b.status != 2 AND p.PackageType IS NOT NULL",
            'wishlist'         => "SELECT DISTINCT p.PackageType FROM tblwishlist w
                                   JOIN tbltourpackages p ON p.PackageId = w.PackageId
                                   WHERE w.UserEmail = :e AND p.PackageType IS NOT NULL",
            'recently_viewed'  => "SELECT DISTINCT p.PackageType FROM tblrecentlyviewed r
                                   JOIN tbltourpackages p ON p.PackageId = r.PackageId
                                   WHERE r.UserEmail = :e AND p.PackageType IS NOT NULL",
        ];

        $types = [];
        $reason = 'none';
        foreach ($tiers as $tierName => $sql) {
            try {
                $q = $dbh->prepare($sql);
                $q->execute([':e' => $email]);
                $found = $q->fetchAll(PDO::FETCH_COLUMN);
            } catch (Exception $e) {
                error_log("[TourBuddy] PersonalizedHomepage::forUser $tierName lookup failed: " . $e->getMessage());
                $found = [];
            }
            if ($found) {
                $types  = $found;
                $reason = $tierName;
                break;
            }
        }

        if (!$types) {
            return ['packages' => [], 'reason' => 'none'];
        }

        // Exclude packages the user already booked or wishlisted -- showing
        // those back as a "recommendation" would be pointless, not personal.
        try {
            $placeholders = implode(',', array_fill(0, count($types), '?'));
            $params = $types;
            $params[] = $email;
            $params[] = $email;
            $q = $dbh->prepare(
                "SELECT p.* FROM tbltourpackages p
                 WHERE p.Status = 1 AND p.PackageType IN ($placeholders)
                   AND p.PackageId NOT IN (SELECT PackageId FROM tblbooking WHERE UserEmail = ? AND status != 2)
                   AND p.PackageId NOT IN (SELECT PackageId FROM tblwishlist WHERE UserEmail = ?)
                 ORDER BY p.AvgRating DESC, p.PackageId DESC
                 LIMIT " . (int) $limit
            );
            $q->execute($params);
            $packages = $q->fetchAll(PDO::FETCH_OBJ);
        } catch (Exception $e) {
            error_log('[TourBuddy] PersonalizedHomepage::forUser package query failed: ' . $e->getMessage());
            return ['packages' => [], 'reason' => 'none'];
        }

        if (!$packages) {
            // Real signal existed, but nothing new to show (e.g. every
            // matching package was already booked/wishlisted) -- honest
            // empty result, not a forced fallback to something unrelated.
            return ['packages' => [], 'reason' => 'none'];
        }

        return ['packages' => $packages, 'reason' => $reason];
    }
}
