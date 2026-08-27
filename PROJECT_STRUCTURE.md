# VibRetail Project Structure Standard

This repository follows the Vib Tools VibProject structure.

## Standard Repository Structure

```text
.
├── .github/
├── assets/
├── config/
├── data/
├── docs/
├── examples/
├── project/
├── scripts/
├── src/
├── tests/
├── AGENTS.md
├── CHANGELOG.md
├── LICENSE
├── PROJECT_STRUCTURE.md
├── README.md
├── VERSIONING.md
├── setup-project.ps1
└── vibproject.ygit
```

### `src/`
Deployable PHP application root. Local XAMPP should map its web alias/junction directly to this directory. GitHub release packaging flattens this directory into the downloadable release root. Active environment files and runtime/user data are never committed.

### `tests/`
Automated static, security, runtime HTTP and DB-backed UAT checks. Tests are not shipped in normal production release artifacts.

### `scripts/`
Build/release, local environment and maintenance utilities. These are repository tools and are not web-accessible because the web root is `src/`.

### `assets/`
Repository-level branding/UI assets. Runtime assets that must ship with the application remain under `src/` until the dedicated UI/UX redesign moves them deliberately.

### `config/` and `data/`
Repository-level non-secret configuration and data resources. Runtime secrets remain outside Git tracking.

### `docs/`
Public/user-facing documentation only.

### `project/`
Private/internal planning, architecture, audit, scope locks, update records and specifications. `setup-project.ps1` adds `/project/*` to `.gitignore` before Git initialization.

### `.github/`
GitHub community/workflow configuration. Release workflows will be added in a later approved scope.

## Deployment Model

Repository layout and release layout are intentionally different:

```text
Repository: src/index.php
Release ZIP: index.php
```

`scripts/release/build-release.php` builds a clean deployable tree from `src/`.
