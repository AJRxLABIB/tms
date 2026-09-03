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
    $host = tms_env('SMTP_HOST', '');
    if (!$host) {
        error_log("[TMS Mailer] SMTP not configured — skipped email to $toEmail: $subject");
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

        $mail->setFrom(tms_env('SMTP_FROM_EMAIL', 'noreply@tms.local'), tms_env('SMTP_FROM_NAME', 'TMS Travel'));
        $mail->addAddress($toEmail, $toName ?: $toEmail);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlBody)));

        $mail->send();
        return true;
    } catch (PHPMailerException $e) {
        error_log('[TMS Mailer] Send failed to ' . $toEmail . ': ' . $mail->ErrorInfo);
        return false;
    } catch (Exception $e) {
        error_log('[TMS Mailer] Unexpected error sending to ' . $toEmail . ': ' . $e->getMessage());
        return false;
    }
}

/** Wraps a message in TMS's simple branded HTML email shell. */
function tms_email_template(string $title, string $bodyHtml): string {
    return '
    <div style="font-family:Segoe UI,Arial,sans-serif;max-width:520px;margin:0 auto;background:#f4f6f9;padding:24px;">
      <div style="background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 16px rgba(0,0,0,0.06);">
        <div style="background:linear-gradient(135deg,#3F84B1,#2c6089);color:#fff;padding:22px 28px;">
          <div style="font-size:19px;font-weight:800;">✈ TMS Travel</div>
        </div>
        <div style="padding:26px 28px;color:#1e293b;font-size:14px;line-height:1.6;">
          <h2 style="font-size:17px;margin:0 0 14px;">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h2>
          ' . $bodyHtml . '
        </div>
        <div style="padding:16px 28px;font-size:11.5px;color:#94a3b8;border-top:1px solid #eef1f6;">
          You are receiving this because of activity on your TMS account. If this wasn\'t you, please contact support.
        </div>
      </div>
    </div>';
}
