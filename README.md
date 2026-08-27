# VibRetail — Repository Structure

VibRetail is the official product identity for this retail operations repository. This root README intentionally documents repository structure only, following the Vib Tools VibProject standard.

> **Branding:** VibRetail by Vib Tools is the authorized product identity. The bundled non-commercial attribution license terms remain in force.

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
