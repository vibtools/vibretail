# Versioning

VibRetail uses Semantic Versioning for release tags.

## Current frozen baseline

The completed UI baseline is:

```text
0.1.0-dev.6
baseline-ui-complete-v0.1.0-dev.6
```

That baseline tag is an archival/development milestone, not an automated deployment-release trigger.

## Automated release tags

Deployable GitHub Releases use annotated tags beginning with `v`:

```text
vMAJOR.MINOR.PATCH
vMAJOR.MINOR.PATCH-prerelease
```

Examples:

```text
v0.1.0-dev.7
v0.1.0-rc.1
v1.0.0
```

A `v*` tag triggers the GitHub Actions release pipeline. The pipeline must pass static regressions, MariaDB integration UAT, release-tree validation, packaging, and SHA-256 verification before it publishes a GitHub Release.

Tags containing a prerelease suffix are published as GitHub Pre-releases. Stable SemVer tags are published as normal releases.

The workflow-generated `VibRetail-<version>-cpanel.zip` asset is the supported shared-hosting/cPanel package. GitHub's automatically generated Source code archives are repository snapshots and are not deployment packages.
