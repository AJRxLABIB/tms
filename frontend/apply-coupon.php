<?php
// Retired: this legacy endpoint had no caller (coupons are validated and claimed inside the booking transaction in
// package-details.php), no CSRF check and a non-atomic claim. It now answers 410 Gone and does nothing.
http_response_code(410);
header('Content-Type: text/plain; charset=utf-8');
echo "This endpoint has been retired.\n";
