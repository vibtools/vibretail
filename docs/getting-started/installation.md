# VibRetail - Easy Web Installer & Upgrade Runbook

## Product goal

A normal new customer should only need to:

1. Upload the release ZIP.
2. Extract it into the website root.
3. Visit `/install`.
4. Enter database, Administrator and optional business details.
5. Optionally enable demo data.
6. Click **Install VibRetail**.
7. Go to Login.

No command line, manual `schema.sql` import, `.env` editing, encryption-key generation or temporary Administrator password is required for a fresh installation.

## Fresh install behavior

The installer checks PHP/runtime requirements, tests the database, optionally creates the database when the supplied DB user has permission, generates the canonical `.env`, generates `POS_SERVICE_CREDENTIAL_KEY`, creates schema, applies versioned migrations, seeds roles/settings, creates the Administrator, optionally adds demo records, runs a self-test and creates an installation lock.

After completion, `/install` and `/install.php` are locked by application state.

## Existing installation upgrade

After a source update, if the database has pending `schema_migrations`, visiting `/install` shows **Database Upgrade** instead of the fresh installer. The current Administrator must authenticate before migrations can execute.

For `CCPOS-EZ-D001-R1`, the upgrade adds the missing `contacts.advance_balance` column and records all compatibility migrations.

If no migration is pending, the installer returns a locked state.

## Environment compatibility

New installs use `.env` as the canonical environment file. Existing `.env.windows` / `.env.server` files remain supported as fallback when `.env` does not exist.

The release ZIP never contains an active `.env` or installation lock.

## Demo data

The optional demo toggle adds clearly named demo master data only:

- Demo Customer
- Demo Supplier
- Demo Product
- required General lookups

It does not contain credentials or external service data.

## Security model

- installer POST requests require installer CSRF;
- installer attempts are rate limited;
- DB password is never returned in API responses;
- DB password and service key are never intentionally logged;
- no default Administrator password exists;
- Administrator password is chosen during setup and hashed immediately;
- environment file is written atomically;
- hidden `.env` files are denied over HTTP;
- `installer-lib.php`, `migrations.php`, private storage and tools are denied over HTTP;
- existing installations cannot silently re-run fresh setup;
- pending upgrades require Administrator authentication.
