# SmartLib Security Audit — 2026-10-29

Read-only review done in the Cowork planning session ahead of the Nov 13 go-live. Scope: `config/auth_security.php`, `config/csrf.php`, `config/db_connect.php`, `login_handler.php`, `verify_2fa.php`, `request_pin_reset.php`, `reset_pin.php`, `admin/manage_users.php`, `admin/admin_login.php`, both logout flows, `admin/layout_top.php`, `create_account.php`/`create_account_handler.php`, `config/admin_audit.php`.

## HIGH

1. **[FIXED 2026-10-29]** Legacy plaintext-password(PIN) fallback in `login_handler.php` — removed. Confirmed the only non-hashed rows were NULL PINs from card-only signups (user_number 20232245, 20232015), not real plaintext secrets left exposed.

## MEDIUM

2. **[FIXED 2026-10-29]** No CSRF token on `login_handler.php` / `verify_2fa.php` — added (`index.php` issues a sign-in token, `assets/javascript/filters.js` sends it, both endpoints reject a missing/invalid token with 403).
3. **[OPEN]** 2FA may not be a real second factor on a single-PC deployment: `auth_is_local_request()` in `auth_security.php` + `login_handler.php` (~lines 293-302) return the 2FA code directly in the response when the request resolves as local, instead of emailing it. If the librarian ever logs into the admin panel from the kiosk PC itself via `localhost`, the "second factor" shows on the same screen as the password/PIN. Decide before 11/13: confirm real email delivery works end-to-end, and confirm the librarian's admin login won't resolve as local.

## LOW

4. **[FIXED 2026-10-29]** `config/db_connect.php` hardcoded DB credentials (`root`/blank) in a file git tracks — moved to gitignored `config/db_config.php` (template committed as `config/db_config.example.php`, setup step added to README). `db_connect.php` logs and shows a generic 500 if the config file is missing. The old `root`/blank values remain in git history; they're the XAMPP defaults, not a secret, but set a real MySQL password on the production PC.
5. **[FIXED 2026-10-29]** `admin/manage_users.php` (~line 365): the user-search/role/status filter now uses `prepare()`/`bind_param()`. Verified that the results match the old query across search, role, status, combined filters and a quote-injection string.
6. **[FIXED 2026-10-29]** `admin/manage_users.php` (~line 338): the `edit_user` lookup interpolated `$editId` (already cast to int) straight into `$conn->query()` — now uses a prepared statement.

## Already solid (confirmed, no action needed)

Login rate-limiting/lockout (5 fails/5 min, by user_number and IP) · 2FA codes random/hashed/10-min TTL/5-attempt cap/single-use · PIN reset tokens `random_bytes(32)`+SHA-256/15-min TTL/single-use/no account-existence leak · `session_regenerate_id(true)` on every login and 2FA success · `manage_users.php`'s 3 state-changing actions were already CSRF-checked before this pass · `admin/layout_top.php` auth guard consistently requires `role` in `[librarian, admin]` · `create_account.php`/`create_account_handler.php` confirmed dead/404'd · every other query in these files uses prepared statements.

## Bonus fix found during remediation (not in the original audit)

`db_connect.php`: on PHP 8.2 a failed connection throws instead of running the old `die($conn->connect_error)` line, which could leak host/user details via an uncaught error — now logs the real error and shows a generic 500 page.

---

Also in this pass (not an audit item): `manage_users.php`'s three hand-rolled CSRF `hash_equals` checks now call the shared `smartlib_csrf_verify()` from `config/csrf.php`.

**Status after this pass: 0 critical, 0 high open, 1 medium open (#3), 0 low open.**

Original full audit (with more narrative detail) lives in the `november-launch-readiness.md` doc in the developer's claude.ai project — not reachable from this repo, which is why this file exists: a plain copy so a Claude Code session working in this repo can read it directly instead of guessing.
