<?php
// DISABLED after use -- one-time session-simulation helper that
// live-verified the Trip Readiness Score renders correctly on
// my-trip.php: booking #BK4 (confirmed, unpaid, no document, no
// itinerary, 86 days out) correctly showed "20% ready" with "Complete
// payment" / "Upload a document" / "Generate itinerary" as the three
// missing actions. Kept only as a 404 stub for audit-trail purposes
// (never delete diagnostic scripts -- Rule 75).
http_response_code(404);
die('Not found.');
