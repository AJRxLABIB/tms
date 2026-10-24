<?php
/**
 * AlertService.php — price / availability alerts (Rule 26/27).
 *
 * "Notify me when price <= X" and "Notify me when this package is
 * available again." Both are one-shot: once fired, IsActive flips to 0 so
 * the customer gets exactly one notification, not a repeat every time
 * scripts/check_alerts.php runs while the condition stays true.
 *
 * Uses the existing tms_notify() (see includes/config.php) so alerts show
 * up in the same notification center as everything else — this is
 * deliberately not a parallel/new notification channel.
 */

function tms_create_price_alert(PDO $dbh, string $email, int $packageId, float $targetPrice): bool
{
    if ($targetPrice <= 0) return false;
    try {
        $dbh->prepare(
            "INSERT INTO tbl_price_alerts(UserEmail, PackageId, TargetPrice) VALUES(:e, :p, :t)"
        )->execute([':e' => $email, ':p' => $packageId, ':t' => $targetPrice]);
        return true;
    } catch (Exception $e) {
        error_log('[TMS Alerts] create_price_alert failed: ' . $e->getMessage());
        return false;
    }
}

function tms_create_availability_alert(PDO $dbh, string $email, int $packageId): bool
{
    try {
        $dbh->prepare(
            "INSERT INTO tbl_availability_alerts(UserEmail, PackageId) VALUES(:e, :p)"
        )->execute([':e' => $email, ':p' => $packageId]);
        return true;
    } catch (Exception $e) {
        error_log('[TMS Alerts] create_availability_alert failed: ' . $e->getMessage());
        return false;
    }
}

/** @return array{price:int, availability:int} counts of alerts fired */
function tms_check_and_fire_alerts(PDO $dbh): array
{
    $firedPrice = 0;
    $firedAvailability = 0;

    // ── Price alerts ──────────────────────────────────────────────────
    $alerts = $dbh->query(
        "SELECT a.id, a.UserEmail, a.PackageId, a.TargetPrice, p.PackageName, p.PackagePrice
         FROM tbl_price_alerts a JOIN tbltourpackages p ON p.PackageId = a.PackageId
         WHERE a.IsActive = 1"
    )->fetchAll(PDO::FETCH_OBJ);

    foreach ($alerts as $a) {
        if ($a->PackagePrice === null) continue; // no price set yet -- nothing to compare
        // Uses the live dynamic price (same number a customer would actually
        // be charged today), not the static PackagePrice, so this can't
        // notify someone about a price they wouldn't actually get.
        $current = tms_dynamic_price($dbh, (int)$a->PackageId, (float)$a->PackagePrice)['final'];
        if ($current <= (float)$a->TargetPrice) {
            tms_notify(
                $dbh,
                $a->UserEmail,
                "Price drop! \"{$a->PackageName}\" is now " . tms_currency((float)$current) . " (your target was " . tms_currency((float)$a->TargetPrice) . ").",
                'package-details.php?pkgid=' . (int)$a->PackageId
            );
            $dbh->prepare("UPDATE tbl_price_alerts SET IsActive=0, NotifiedAt=NOW() WHERE id=:id")
                ->execute([':id' => $a->id]);
            $firedPrice++;
        }
    }

    // ── Availability alerts ──────────────────────────────────────────
    $availAlerts = $dbh->query(
        "SELECT a.id, a.UserEmail, a.PackageId, p.PackageName
         FROM tbl_availability_alerts a JOIN tbltourpackages p ON p.PackageId = a.PackageId
         WHERE a.IsActive = 1"
    )->fetchAll(PDO::FETCH_OBJ);

    foreach ($availAlerts as $a) {
        $seats = tms_remaining_seats($dbh, (int)$a->PackageId);
        // null = capacity not tracked for this package, meaning it was never
        // actually "unavailable" in a way this alert type applies to.
        if ($seats === null) continue;
        if ($seats > 0) {
            tms_notify(
                $dbh,
                $a->UserEmail,
                "\"{$a->PackageName}\" is available again ({$seats} seat" . ($seats === 1 ? '' : 's') . " left).",
                'package-details.php?pkgid=' . (int)$a->PackageId
            );
            $dbh->prepare("UPDATE tbl_availability_alerts SET IsActive=0, NotifiedAt=NOW() WHERE id=:id")
                ->execute([':id' => $a->id]);
            $firedAvailability++;
        }
    }

    return ['price' => $firedPrice, 'availability' => $firedAvailability];
}
