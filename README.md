# ssb_auth - central sign-in (identity provider)

One login for all SSB apps. This service only **authenticates** people. Each app keeps its own roles/scope
and decides what a signed-in person may do there.

## Flow (authorization code + PKCE, back-channel token exchange)
1. App -> browser redirect to `GET /authorize?response_type=code&client_id&redirect_uri&state&code_challenge&code_challenge_method=S256`
2. User signs in here (or is already signed in) -> redirect back to the app with `?code&state` (code: 60 s, single use).
3. App server -> `POST /token` (`grant_type=authorization_code, code, redirect_uri, code_verifier, client_id, client_secret`)
   -> JSON `{sub, login_id, name, email, auth_time}`.
4. App maps `sub` to its own user (`central_user_id`) and creates its own session with its own role.
Logout: app destroys its session, then `GET /logout?client_id=..&return_to=<registered logout URI>`.

## Setup on cPanel (main domain `ssbmanagement.lk`, PHP 8.1)
The service is the main domain's front door: `https://ssbmanagement.lk/` shows the sign-in form, and once signed in
the person's applications. The application code is kept **outside** `public_html`; only a small shim is inside it.

1. **PHP version**: cPanel > MultiPHP Manager > set `ssbmanagement.lk` to PHP 8.1 (extensions: pdo_mysql, curl, mbstring, openssl).
2. **Database**: create a database and user (e.g. `ssbmanag_central_auth`), import `database/schema.sql`.
3. **Upload the app** to `/home/<ACCOUNT>/ssb_auth/` (NOT inside public_html): `src/`, `views/`, `config/`, `storage/`, `database/`, `tools/`, `public/`.
4. `cp config/config.sample.php config/config.php` and edit: DB details, `base_url` = `https://ssbmanagement.lk`, `session.save_path`.
5. **Shim and rules in public_html**: copy `deploy/public_html/index.php` to `public_html/index.php` and set the path on its last line to
   `/home/<ACCOUNT>/ssb_auth/public/index.php`. Copy `deploy/public_html/.htaccess` to `public_html/.htaccess`.
   Remove the old portal files (`Index.php`, `.htaccess`, `assets/`) first.
6. **Assets**: make `public_html/assets` show `ssb_auth/public/assets` - in cPanel Terminal:
   `ln -s /home/<ACCOUNT>/ssb_auth/public/assets /home/<ACCOUNT>/public_html/assets` (or just copy the folder).
7. `php tools/create-admin.php <login> "<Name>"` (or set `AUTH_ADMIN_PASSWORD`), sign in at `https://ssbmanagement.lk/`,
   register each app under *Apps*, create users and tick the apps each may open.
8. Check from outside: `https://ssbmanagement.lk/config/config.php`, `/src/Db.php`, `/<subdomain-folder>/` must all answer 404, and
   `https://ssbmanagement.lk/assets/style.css` must load.

`.htaccess` files and `config/config.php` are never deployed from git - upload them by hand.

## Provisioning API (for registered apps)
Server-to-server JSON endpoints so an app (FundFlow, MPCS, ...) can create users and keep passwords in sync.
Authenticate with the app's `client_id` + `client_secret` (HTTP Basic). The app must have **Allow this application
to create users** ticked under *Admin > Apps* (`clients.can_provision`, off by default). The app a user is granted
is always the authenticated one. Failures are throttled and audited.

| Method + path | Body | Result |
|---|---|---|
| `POST /api/v1/users` | `login_id, full_name, email?, initial_password` | `201` created, or `200` already existed (grants this app only, password untouched) |
| `POST /api/v1/users/password` | `login_id, current_password, new_password` | user changes their own password (user must have this app) |
| `POST /api/v1/users/password-reset` | `login_id, new_password` | admin reset; only if this app is the user's only app, otherwise `403` |

Errors: `401` bad client, `403` provisioning off / not allowed, `409` email in use, `422` invalid or missing field
(`initial_password` is required; SSO never generates passwords), `429` throttled.
Existing installs: run `database/migration_client_provisioning.sql`.

## Configuration
Settings live in `config/config.php` (copy from `config/config.sample.php`; never committed). For tests/CI these
environment variables override it: `AUTH_DB_HOST`, `AUTH_DB_PORT`, `AUTH_DB_NAME`, `AUTH_DB_USER`, `AUTH_DB_PASS`,
`AUTH_BASE_URL`, `AUTH_PORTAL_URL`. `tools/create-admin.php` reads `AUTH_ADMIN_PASSWORD`.

## Local development
```
cp config/config.sample.php config/config.php        # point it at a local MySQL database
mysql -u root -p -e 'CREATE DATABASE ssb_central_auth CHARACTER SET utf8mb4'
mysql -u root -p ssb_central_auth < database/schema.sql
php tools/create-admin.php admin "Administrator"
php -S 127.0.0.1:8080 -t public                       # http://127.0.0.1:8080
```
Register each app with `php tools/create-client.php ...` or in the admin UI. Set `base_url` to the same host you browse
(127.0.0.1 and localhost have separate cookies).

## Tests
`AUTH_DB_NAME=... AUTH_DB_USER=... AUTH_DB_PASS=... php tests/flow_test.php` (needs a throw-away MySQL database; it applies the schema and wipes it).
