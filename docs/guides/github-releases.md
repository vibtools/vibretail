# Automated GitHub Releases

VibRetail release automation is designed for cPanel/shared-hosting deployment.

## Important: GitHub source archives are not deployment packages

Every GitHub Release automatically shows:

- `Source code (zip)`
- `Source code (tar.gz)`

Those files are repository snapshots. They contain the repository/VibProject layout and are **not** the supported cPanel package.

For cPanel, use the workflow-generated asset:

```text
VibRetail-<version>-cpanel.zip
```

and verify it with:

```text
VibRetail-<version>-cpanel.zip.sha256
```

The cPanel ZIP has `index.php` at the archive root.

## Release tag convention

Only tags beginning with `v` trigger the release workflow.

Examples:

```text
v0.1.0-dev.7
v0.1.0-rc.1
v0.1.0
v1.2.3
```

Baseline/archive tags such as `baseline-ui-complete-v0.1.0-dev.6` do not trigger automatic deployment releases.

## Release flow

```text
commit approved source to main
        ↓
annotated v* tag pushed
        ↓
GitHub Actions static gate
        ↓
MariaDB 10.4 integration/UAT gate
        ↓
clean release builder
        ↓
release validator
        ↓
root-level cPanel ZIP packaging
        ↓
SHA-256 verification
        ↓
GitHub Release created automatically
        ↓
cpanel.zip + sha256 attached
```

If any test, UAT, validation, package, or checksum step fails, the GitHub Release is not published.

## Tag and release

After the release-automation patch itself has been accepted and pushed to `main`, create an annotated release tag at that exact HEAD:

```bat
git tag -a v0.1.0-dev.7 HEAD -m "VibRetail 0.1.0-dev.7 cPanel validation candidate"
git push origin v0.1.0-dev.7
```

No manual release ZIP build is required.

## GitHub Actions output

The workflow uploads the same verified files as a temporary Actions artifact and then attaches them to the GitHub Release:

```text
VibRetail-0.1.0-dev.7-cpanel.zip
VibRetail-0.1.0-dev.7-cpanel.zip.sha256
```

GitHub's automatic Source code archives will still be visible. Ignore them for cPanel deployment.

## Pre-release behavior

A SemVer tag containing a suffix, such as:

```text
v0.1.0-dev.7
v0.1.0-rc.1
```

is published as a GitHub **Pre-release**.

A stable tag such as:

```text
v1.0.0
```

is published as a normal GitHub Release.

## CI database safety

The integration bootstrap:

- only accepts localhost/127.0.0.1/::1;
- only resets databases named `vibretail_ci...`;
- requires the explicit `CI-UAT-RESET` confirmation variable;
- uses an ephemeral administrator password generated inside the workflow;
- does not use production credentials.

## Deployment package guarantees

The release pipeline verifies that the deployable ZIP:

- contains `index.php` at root;
- contains the installer and schema;
- contains public installation/license metadata;
- contains no `src/`, `tests/`, `scripts/`, `project/`, `.github/`, or `.git/` wrapper;
- contains no active `.env*`;
- contains no installation lock/log/archive;
- contains no real product uploads;
- passes PHP lint and release secret-pattern checks;
- has a SHA-256 checksum.
