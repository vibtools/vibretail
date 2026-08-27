# Backup / Restore / DR Contract

Phase 02 backup format is `cloud-core-pos-backup-v2`.

`backup.php` creates:

- `pos-<timestamp>.sql`;
- SQL `.sha256`;
- `pos-<timestamp>-uploads/` filesystem snapshot;
- `pos-<timestamp>.manifest.json` containing SQL and uploads hashes/inventory;
- manifest `.sha256`.

`restore.php` refuses execution without a checksum, Phase-02 manifest, explicit `--isolated-target=<POS_DB_NAME>`, `--confirm`, and a non-production `POS_APP_ENV`. It creates a safety backup first. Upload snapshots are verified but never overwritten automatically.

Initial deployment objectives: **RPO <= 24 hours** and **RTO <= 4 hours**, subject to business approval and actual hosting backup frequency.
