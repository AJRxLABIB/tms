<?php
/**
 * Create or reset an admin account from the command line (never web-reachable).
 *   php tools/create-admin.php [username]
 * Prints a freshly generated random password once. Change it after first login.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(__DIR__ . '/..');
require 'backend/config.php';
$user = $argv[1] ?? 'admin';
if (!preg_match('/^[A-Za-z0-9_.-]{3,40}$/', $user)) { fwrite(STDERR, "Invalid username.\n"); exit(1); }
$pw = rtrim(strtr(base64_encode(random_bytes(15)), '+/', '-_'), '=');
$hash = password_hash($pw, PASSWORD_BCRYPT, ['cost' => 12]);
$st = $dbh->prepare('SELECT COUNT(*) FROM admin WHERE UserName = ?'); $st->execute([$user]);
if ((int)$st->fetchColumn() > 0) { $dbh->prepare('UPDATE admin SET Password = ? WHERE UserName = ?')->execute([$hash, $user]); echo "Password reset for '$user'.\n"; }
else { $dbh->prepare('INSERT INTO admin (UserName, Password) VALUES (?, ?)')->execute([$user, $hash]); echo "Created admin '$user'.\n"; }
echo "Password (shown once): $pw\n";
