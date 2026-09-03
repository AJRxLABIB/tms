<?php
/**
 * tms_log_activity($dbh, $actor, $action, $details)
 * Call this after any important admin/user action.
 */
function tms_log_activity(PDO $dbh, string $actor, string $action, string $details = ''): void {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    try {
        $dbh->prepare("INSERT INTO tblactivity_log(Actor,Action,Details,IPAddress) VALUES(:a,:ac,:d,:ip)")
            ->execute([':a'=>$actor,':ac'=>$action,':d'=>$details,':ip'=>$ip]);
    } catch (Exception $e) { /* silently fail */ }
}
