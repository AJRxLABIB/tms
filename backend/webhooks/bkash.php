<?php
/**
 * backend/webhooks/bkash.php — inbound webhook/callback receiver for
 * bkash (master prompt item 43, "WEBHOOKS"). Reached via the root-level
 * shim webhook-bkash.php so the URL to hand bkash's merchant dashboard
 * stays short and stable: https://<your-domain>/webhook-bkash.php
 *
 * All the actual logging / dedup / verification logic is one shared
 * implementation in PaymentService::handleWebhook() (see that file) -- this
 * file's only job is: reject non-POST requests, read the request exactly as
 * sent (raw body + every header, before PHP's $_POST parsing can alter
 * anything a signature might cover), and hand it off.
 *
 * Implementation status: Implemented (receiver + logging + idempotency +
 *   fail-closed dispatch to bkash's provider class).
 * Testing status: BLOCKED, same reason as the rest of this project's
 *   payment code (see PAYMENTS.md) -- bkash is not configured (no real
 *   merchant account), so bkashProvider::verifyWebhook() always returns
 *   false and this endpoint can only be exercised with a synthetic request,
 *   never a real signed bkash delivery.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../payments/PaymentProviderInterface.php';
require_once __DIR__ . '/../payments/SandboxProvider.php';
require_once __DIR__ . '/../payments/SslcommerzProvider.php';
require_once __DIR__ . '/../payments/BkashProvider.php';
require_once __DIR__ . '/../payments/NagadProvider.php';
require_once __DIR__ . '/../payments/PaymentService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

// Raw bytes first -- some providers sign the exact body, which $_POST's
// re-parsing/re-encoding does not preserve.
$rawBody = file_get_contents('php://input');
$headers = function_exists('getallheaders') ? (getallheaders() ?: []) : [];

$result = (new PaymentService($dbh))->handleWebhook('bkash', $rawBody, $headers);

http_response_code($result['status']);
header('Content-Type: application/json');
echo json_encode($result['body']);
