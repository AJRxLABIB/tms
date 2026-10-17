<?php
/**
 * One-off: encrypt existing plaintext 2FA secrets in tblusers and admin with TMS_2FA_KEY (set it in .env first).
 *   php scripts/encrypt_2fa_secrets.php            (dry run: counts only)
 *   php scripts/encrypt_2fa_secrets.php --apply
 * Idempotent; already-encrypted rows are skipped. KEEP THE KEY: without it those users cannot pass 2FA (plain-text rows keep working).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(__DIR__ . '/..');
require 'backend/config.php';
if (tms_2fa_key() === '') { fwrite(STDERR, "TMS_2FA_KEY is not set (needs 16+ characters). Nothing done.\n"); exit(1); }
$apply = in_array('--apply', $argv, true);
foreach (['tblusers' => 'id', 'admin' => 'id'] as $tbl => $pk) {
    $rows = $dbh->query("SELECT `$pk` AS k, TwoFASecret FROM `$tbl` WHERE TwoFASecret IS NOT NULL AND TwoFASecret <> '' AND TwoFASecret NOT LIKE 'enc1:%'")->fetchAll(PDO::FETCH_ASSOC);
    echo "$tbl: " . count($rows) . " plaintext secret(s)" . ($apply ? '' : ' (dry run)') . "\n";
    if (!$apply) continue;
    $up = $dbh->prepare("UPDATE `$tbl` SET TwoFASecret = ? WHERE `$pk` = ?");
    foreach ($rows as $r) { $sealed = tms_2fa_seal($r['TwoFASecret']); if (tms_2fa_open($sealed) === $r['TwoFASecret']) $up->execute([$sealed, $r['k']]); }
}
echo $apply ? "Done.\n" : "Add --apply to encrypt.\n";
