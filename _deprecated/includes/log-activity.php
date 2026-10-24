<?php
/**
 * tms_log_activity($dbh, $actor, $action, $details, $objectType, $objectId, $oldValue, $newValue)
 * Call this after any important admin/user action.
 *
 * Round 96 (item 47 -- Audit Log): added four optional trailing params
 * ($objectType, $objectId, $oldValue, $newValue) plus automatic capture of
 * request method/URI/user-agent (no new param needed for those -- exactly
 * like IPAddress was already auto-captured before this round). Every one
 * of the ~65 existing call sites across the project keeps working
 * unchanged, since these are all optional and default to null -- this
 * only adds structure for the handful of sensitive/stateful call sites
 * this round retrofitted to actually pass real old/new values.
 *
 * $oldValue/$newValue are plain strings, truncated to 500 chars (matches
 * the column width) -- never pass a password, API key, token, or other
 * secret here (Rule 5 / the master prompt's own explicit instruction for
 * this feature). Every call site this round added was checked against
 * that before being wired up (role names, prices, statuses, dates,
 * refund amounts -- never a credential).
 */
function tms_log_activity(
    PDO $dbh, string $actor, string $action, string $details = '',
    ?string $objectType = null, $objectId = null, ?string $oldValue = null, ?string $newValue = null
): void {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    try {
        $dbh->prepare(
            "INSERT INTO tblactivity_log
                (Actor, Action, Details, IPAddress, ObjectType, ObjectId, OldValue, NewValue, RequestMethod, RequestUri, UserAgent)
             VALUES (:a, :ac, :d, :ip, :ot, :oid, :ov, :nv, :rm, :ru, :ua)"
        )->execute([
            ':a'  => $actor,
            ':ac' => $action,
            ':d'  => $details,
            ':ip' => $ip,
            ':ot' => $objectType,
            ':oid' => $objectId !== null ? (string)$objectId : null,
            ':ov' => $oldValue !== null ? mb_substr($oldValue, 0, 500) : null,
            ':nv' => $newValue !== null ? mb_substr($newValue, 0, 500) : null,
            ':rm' => $_SERVER['REQUEST_METHOD'] ?? null,
            ':ru' => isset($_SERVER['REQUEST_URI']) ? mb_substr($_SERVER['REQUEST_URI'], 0, 255) : null,
            ':ua' => isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : null,
        ]);
    } catch (Exception $e) { /* silently fail */ }
}
