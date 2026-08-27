# VibRetail — Repository Structure

VibRetail is the locked working name for the modernization repository built from the current Cloud Core POS codebase. This root README intentionally documents repository structure only, following the Vib Tools VibProject standard.

> **Branding/license transition:** the bundled runtime still carries the existing Cloud Core POS software name and protected attribution under its current license. Public rebranding to VibRetail must not be completed until the applicable rights/license are explicitly resolved.

## Repository Structure

```text
.
├── .github/              GitHub repository configuration
├── assets/               Branding, icons, images and future UI assets
├── config/               Repository-level configuration notes
├── data/                 Non-secret project data resources
├── docs/                 Public/user documentation
├── examples/             Public examples
├── project/              Private/internal development workspace
├── scripts/              Build, release, maintenance and local utilities
├── src/                  Deployable PHP application source
├── tests/                Automated security/runtime/UAT tests
│
├── AGENTS.md
├── CHANGELOG.md
├── LICENSE
├── PROJECT_STRUCTURE.md
├── README.md
├── VERSIONING.md
├── setup-project.ps1
└── vibproject.ygit
```

## Responsibility Boundary

```text
project/  → INTERNAL project development and management
docs/     → PUBLIC user documentation
src/      → DEPLOYABLE application source
tests/    → AUTOMATED verification
scripts/  → PROJECT utilities
README.md → REPOSITORY STRUCTURE only
```
