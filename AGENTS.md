# VibRetail AI & Developer Repository Rules

VibRetail follows the VibProject responsibility model.

```text
Internal project development/management → project/
Public user documentation               → docs/
Application implementation              → src/
Automated verification                  → tests/
Build/maintenance utilities             → scripts/
Repository structure explanation        → README.md
```

## Scope and baseline control

Detailed governance belongs under `project/`. Follow `project/README.md` and `project/PROJECT_UPDATE_WORKFLOW.md`. Do not implement unrelated changes outside an approved scope.

## Git behavior

Git initialization, commits, repository creation and push operations remain manual unless the user explicitly authorizes them. Never commit active environment files, credentials, runtime logs/backups, installation locks, or user-upload payloads.

## Branding/license constraint

The working repository name is VibRetail. The current bundled software license protects the legacy Cloud Core POS software name and developer credentials. Do not perform the public/runtime branding replacement until the license/rights transition is explicitly authorized.
