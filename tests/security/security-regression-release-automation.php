<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

$root = dirname(__DIR__, 2);
$paths = [
    'workflow' => $root . '/.github/workflows/release.yml',
    'release_config' => $root . '/.github/release.yml',
    'static' => $root . '/scripts/test/run-static.php',
    'static_cmd' => $root . '/scripts/test/run-static.cmd',
    'bootstrap' => $root . '/scripts/ci/bootstrap-uat.php',
    'builder' => $root . '/scripts/release/build-release.php',
    'validator' => $root . '/scripts/release/validate-release.php',
    'packager' => $root . '/scripts/release/package-release.php',
];

$text = [];
$checks = 0;
$failed = 0;

$check = static function (bool $ok, string $name) use (&$checks, &$failed): void {
    $checks++;
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
    }
};

foreach ($paths as $name => $path) {
    $text[$name] = is_file($path) ? (string) file_get_contents($path) : '';
    $check($text[$name] !== '', 'Release automation source exists: ' . $name);
}

$workflow = $text['workflow'];
foreach ([
    "tags:\n      - 'v*'" => 'release is tag-triggered',
    'jobs:' => 'workflow defines jobs',
    'Static quality gate' => 'static quality job',
    'MariaDB integration UAT' => 'database UAT job',
    'mariadb:10.4' => 'MariaDB 10.4 service',
    "php-version: '8.2'" => 'PHP 8.2 baseline',
    'php scripts/test/run-static.php' => 'cross-platform static gate executes',
    'php tests/uat/uat.php' => 'database UAT executes',
    'needs: [quality, uat]' => 'packaging waits for quality and UAT',
    'build-release.php' => 'release builder executes',
    'validate-release.php' => 'release validator executes',
    'package-release.php' => 'verified packager executes',
    'actions/upload-artifact@v4' => 'verified package artifact uploaded',
    'actions/download-artifact@v5' => 'verified package artifact downloaded',
    'contents: write' => 'publish job receives release write permission',
    'gh release create' => 'GitHub Release is created',
    '--verify-tag' => 'release verifies pushed tag',
    '--generate-notes' => 'GitHub generated notes enabled',
    '--prerelease' => 'prerelease flag supported',
    '-cpanel.zip' => 'deployable asset is explicitly cPanel named',
    'sha256sum -c' => 'checksum is independently verified',
] as $needle => $name) {
    $check(str_contains($workflow, $needle), $name);
}

$check(!str_contains($workflow, 'pull_request_target'), 'release workflow does not use pull_request_target');
$check(!preg_match('/permissions:\s*write-all/i', $workflow), 'workflow does not grant write-all permissions');
$check(str_contains($workflow, 'gh release view'), 'existing release replacement is refused');
$check(str_contains($workflow, 'test ! -e "${TARGET}/tests"'), 'repository tests excluded from deployment tree');
$check(str_contains($workflow, "grep -Fxq 'index.php'"), 'ZIP root entry is verified');

$validator = $text['validator'];
$check(str_contains($validator, "Runtime product upload:"), 'release validator rejects real product uploads');
$check(str_contains($validator, "Active environment artifact:"), 'release validator rejects arbitrary active env variants');
$check(str_contains($validator, "Private runtime artifact:"), 'release validator rejects private runtime files');
$check(str_contains($validator, 'Possible embedded private key:'), 'release validator scans PHP for private-key material');

$packager = $text['packager'];
$check(str_contains($packager, 'RELEASE-MANIFEST.txt'), 'package includes release manifest');
$check(str_contains($packager, "hash_file('sha256'"), 'package writes SHA-256 checksum');
$check(str_contains($packager, 'Repository-only path entered release package'), 'packager rejects repository-only directories');
$check(str_contains($packager, 'Runtime product upload entered release package'), 'packager independently rejects real uploads');
$check(str_contains($packager, "['index.php', 'install.php', 'schema.sql'"), 'packager requires application root files');

$bootstrap = $text['bootstrap'];
$check(str_contains($bootstrap, "VIBRETAIL_CI_CONFIRM") && str_contains($bootstrap, 'CI-UAT-RESET'), 'CI database reset requires explicit confirmation');
$check(str_contains($bootstrap, "^vibretail_ci"), 'CI bootstrap is restricted to CI-named databases');
$check(str_contains($bootstrap, "['127.0.0.1', 'localhost', '::1']"), 'CI bootstrap is restricted to local database hosts');
$check(str_contains($bootstrap, 'installer_create_admin'), 'CI bootstrap creates ephemeral administrator');
$check(str_contains($bootstrap, 'run_schema_migrations'), 'CI bootstrap applies migrations');

$check(str_contains($text['static_cmd'], 'scripts\\test\\run-static.php'), 'Windows static wrapper delegates to cross-platform runner');
$check(str_contains($text['static'], 'tests/security/security-regression-ui-shell.php'), 'cross-platform runner includes UI-02A shell regression');

$static = $text['static'];
$check(str_contains($static, 'security-regression-release-automation.php'), 'static gate protects release automation');
$check(str_contains($static, "['src', 'scripts', 'tests']"), 'cross-platform runner lints all PHP source families');
$check(str_contains($static, "['node', '--check', 'src/app.js']"), 'cross-platform runner checks JavaScript');

echo "Release automation regression: {$checks} checks, {$failed} failed." . PHP_EOL;
exit($failed ? 1 : 0);
