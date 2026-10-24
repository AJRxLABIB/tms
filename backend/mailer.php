<?php
/**
 * includes/mailer.php — thin wrapper around the vendored PHPMailer library.
 *
 * This project has no Composer/vendor setup, so PHPMailer's three plain
 * source files are vendored directly under includes/phpmailer/ (no autoloader
 * needed — they're require'd explicitly below).
 *
 * Email sending is entirely OPTIONAL: if SMTP_HOST is left blank in .env,
 * tms_send_email() just logs and returns false instead of throwing — every
 * other feature (in-app notifications, bookings, etc.) keeps working with
 * zero configuration. Fill in the SMTP_* values in .env to enable real email.
 */

require_once __DIR__ . '/phpmailer/Exception.php';
require_once __DIR__ . '/phpmailer/PHPMailer.php';
require_once __DIR__ . '/phpmailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * Sends an HTML email via the configured SMTP server. Silently no-ops (and
 * logs) if SMTP isn't configured, so callers never need to check first.
 *
 * @return bool true if the email was actually sent
 */
function tms_send_email(string $toEmail, string $toName, string $subject, string $htmlBody): bool {
    // Master prompt item 56 (monitoring): "Email" is one of the seven
    // systems this item explicitly asks to track, and until now a send
    // failure only ever reached error_log -- no admin page could show
    // "how many emails failed this week". $dbh is read as a global here
    // (rather than adding a parameter to every one of this function's ~6
    // call sites across register.php/forgot-password.php/manage-bookings.php/
    // etc.) because config.php -- which defines $dbh at file/global scope --
    // is always loaded before this file (see the require order in
    // config.php itself), and logging is wrapped in its own try/catch
    // inside tms_log_system_event() so it can never turn a send failure (or
    // success) into a fatal error.
    global $dbh;
    $host = tms_env('SMTP_HOST', '');
    if (!$host) {
        error_log("[TourBuddy Mailer] SMTP not configured — skipped email to $toEmail: $subject");
        if ($dbh instanceof PDO) tms_log_system_event($dbh, 'email', 'not_configured', false, 'SMTP_HOST not set');
        return false;
    }

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = $host;
        $mail->Port       = (int)tms_env('SMTP_PORT', 587);
        $mail->SMTPAuth   = true;
        $mail->Username   = tms_env('SMTP_USER', '');
        $mail->Password   = tms_env('SMTP_PASS', '');
        $encryption       = strtolower((string)tms_env('SMTP_ENCRYPTION', 'tls'));
        $mail->SMTPSecure = $encryption === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom(tms_env('SMTP_FROM_EMAIL', 'noreply@tms.local'), tms_env('SMTP_FROM_NAME', 'TourBuddy Travel'));
        $mail->addAddress($toEmail, $toName ?: $toEmail);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlBody)));

        $mail->send();
        if ($dbh instanceof PDO) tms_log_system_event($dbh, 'email', 'send', true);
        return true;
    } catch (PHPMailerException $e) {
        error_log('[TourBuddy Mailer] Send failed to ' . $toEmail . ': ' . $mail->ErrorInfo);
        if ($dbh instanceof PDO) tms_log_system_event($dbh, 'email', 'send', false, $mail->ErrorInfo);
        return false;
    } catch (Exception $e) {
        error_log('[TourBuddy Mailer] Unexpected error sending to ' . $toEmail . ': ' . $e->getMessage());
        if ($dbh instanceof PDO) tms_log_system_event($dbh, 'email', 'send', false, $e->getMessage());
        return false;
    }
}

/** Wraps a message in TourBuddy's simple branded HTML email shell. */
function tms_email_template(string $title, string $bodyHtml): string {
    return '
    <div style="font-family:Segoe UI,Arial,sans-serif;max-width:520px;margin:0 auto;background:#f4f6f9;padding:24px;">
      <div style="background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 16px rgba(0,0,0,0.06);">
        <div style="background:linear-gradient(135deg,#3F84B1,#2c6089);color:#fff;padding:22px 28px;">
          <div style="font-size:19px;font-weight:800;">✈ TourBuddy Travel</div>
        </div>
        <div style="padding:26px 28px;color:#1e293b;font-size:14px;line-height:1.6;">
          <h2 style="font-size:17px;margin:0 0 14px;">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h2>
          ' . $bodyHtml . '
        </div>
        <div style="padding:16px 28px;font-size:11.5px;color:#94a3b8;border-top:1px solid #eef1f6;">
          You are receiving this because of activity on your TourBuddy account. If this wasn\'t you, please contact support.
        </div>
      </div>
    </div>';
}
