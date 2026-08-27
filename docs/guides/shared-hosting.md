# cPanel Release Runbook

## Clean release

Build/deploy only the validated release tree. Active `.env`, local runtime state, installation lock, logs, DB dumps and user-upload snapshots must never be shipped inside the source release.

## Fresh hosting account

1. Upload and extract the clean release into the domain/subdomain document root.
2. Create a MySQL/MariaDB database and user in cPanel when the hosting account does not permit database creation from PHP.
3. Visit `https://your-domain/install`.
4. Complete the environment, database, Administrator and optional demo-data steps.
5. Confirm the installer self-test succeeds and redirects to Login.
6. Revisit `/install`; it must be locked.
7. Run runtime security/UAT acceptance before production sign-off.

No shell/CLI access is required for normal fresh installation.

## Existing installation upgrade

1. Take DB/files/uploads backup first.
2. Overlay the approved release files without overwriting the active environment file.
3. Visit `/install`. When migrations are pending, authenticate as a current Administrator and apply the upgrade.
4. Confirm `/install` locks again after the upgrade.
5. Run UAT/security checks.

## Environment/security

New installations use generated `.env`. Existing `.env.server` deployments remain supported as a compatibility fallback. Environment files must be HTTP-denied and excluded from distributable artifacts. `POS_ALLOW_WEB_INSTALL=false` remains a legacy defense flag; installer availability is controlled by installation state and the protected lock/migration model.
