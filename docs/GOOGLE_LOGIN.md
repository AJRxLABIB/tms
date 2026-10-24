# Google sign-in

"Continue with Google" appears on the sign-in page, the sign-up page and both header modals **only when** `GOOGLE_CLIENT_ID` and `GOOGLE_CLIENT_SECRET` are set in `.env`. Without them nothing changes and `google-login.php` sends visitors back to the normal login page.

## One-time setup (Google Cloud Console)

1. Open <https://console.cloud.google.com/> -> your project -> **APIs & Services -> OAuth consent screen**. Choose *External*, fill in the app name, support e-mail and developer e-mail. Scopes needed: `openid`, `email`, `profile` (all non-sensitive, no Google review). While the app is in *Testing*, add the Google accounts that may sign in under **Test users**; click **Publish app** to allow everyone.
2. **APIs & Services -> Credentials -> Create credentials -> OAuth client ID -> Web application.**
3. **Authorized redirect URIs** - add exactly the value printed by `php -r "require 'backend/config.php'; require 'backend/auth/GoogleAuth.php'; echo GoogleAuth::redirectUri();"`, for example `http://localhost/tourbuddy/google-callback.php` (XAMPP) and `https://your-domain/google-callback.php` (production). It must match character for character (scheme, host, port, path). Google only allows `http://` for `localhost`.
4. Copy the client ID and secret into `.env`:

```
GOOGLE_CLIENT_ID=1234567890-abc.apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=GOCSPX-...
# Optional: force the callback URL (reverse proxy, different public host). Must also be registered in Google.
# GOOGLE_REDIRECT_URI=https://your-domain/google-callback.php
# Recommended in production so the callback URL never depends on the Host header:
# APP_URL=https://your-domain
```

Never commit `.env`. The secret is only used server-to-server.

## How it behaves

- First Google sign-in with a new e-mail creates an account (name and verified e-mail from Google, random unusable password, loyalty row, referral code). They can set a password later via *Forgot password*.
- If a site account with that e-mail already exists, Google is **not** attached automatically: the visitor must enter that account's TourBuddy password once (`google-link.php`), because someone could have registered the address here before its real owner. After that, Google signs straight in.
- Accounts are matched by Google's permanent user id (`tbl_user_identities`), so a changed Google e-mail still reaches the same account.
- Accounts with TourBuddy two-factor still get the authenticator prompt after Google sign-in. Deleted accounts cannot sign in.
- Not stored: Google access/refresh tokens.

## Known limits

- No "unlink Google" button yet (remove the row from `tbl_user_identities`).
- Referral-link bonuses are not applied to Google sign-ups (the e-mail form's referral logic was deliberately not duplicated).
- The mobile/REST API (`api/index.php`) does not accept Google tokens.
- Verified only against a local stand-in for Google (`tests/auth/google_test.php`); the first real sign-in on your machine is the real test.
- Behind a reverse proxy without `APP_URL`/`GOOGLE_REDIRECT_URI`, the callback URL is built from the Host header and may not match what you registered.
