<?php
// Disabled: temporary session-simulation diagnostic used to live-verify
// cart.php's i18n wiring (Round 118) -- set $_SESSION['login'] to a real
// test user and ensured one real cart row existed, per this engagement's
// standing rule that session simulation via $_SESSION is the only
// permitted technique for testing authenticated flows (never a real
// login-form submission). Verification is complete; disabled rather than
// left live.
http_response_code(404); die('Not found.');
