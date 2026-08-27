<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
$repoRoot = dirname(__DIR__, 2);
$root = $repoRoot . '/src';
$fail = 0;
$check = function (bool $ok, string $name) use (&$fail): void {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $name . PHP_EOL;
    if (!$ok) $fail++;
};
$config = (string) file_get_contents($root . '/config.php');
$schema = (string) file_get_contents($root . '/schema.sql');
$migrations = (string) file_get_contents($root . '/migrations.php');
$installer = (string) file_get_contents($root . '/install.php');
$installerLib = (string) file_get_contents($root . '/installer-lib.php');
$api = (string) file_get_contents($root . '/api.php');
$uat = (string) file_get_contents($repoRoot . '/tests/uat/uat.php');
$runtime = (string) file_get_contents($repoRoot . '/tests/runtime/runtime-security-check.php');
$apache = (string) file_get_contents($root . '/.htaccess');
$nginx = (string) file_get_contents($root . '/aapanel-nginx.conf');
$builder = (string) file_get_contents($repoRoot . '/scripts/release/build-release.php');

$check(str_contains($schema, 'advance_balance DECIMAL(14,2) NOT NULL DEFAULT 0'), 'Canonical contacts schema includes advance balance');
$check(str_contains($schema, 'CREATE TABLE IF NOT EXISTS schema_migrations'), 'Canonical schema includes migration ledger');
$check(str_contains($migrations, '20260827_002_contacts_advance_balance') && str_contains($migrations, "'contacts', 'advance_balance'"), 'Versioned migration fixes existing contacts schema');
$check(str_contains($installer, 'Easy setup & secure installation') && str_contains($installer, 'installer_perform_fresh_install') && str_contains($installer, 'install-button'), 'User-friendly web installer exists');
$check(str_contains($installerLib, 'installer_environment_checks') && str_contains($installerLib, "'pdo_mysql'") && str_contains($installerLib, "'gd'") && str_contains($installerLib, "'exif'"), 'Installer auto-detects required environment');
$check(str_contains($installerLib, 'installer_test_database') && str_contains($installer, 'Test Connection'), 'Installer tests database details');
$check(str_contains($installerLib, 'installer_write_environment') && str_contains($installerLib, 'POS_SERVICE_CREDENTIAL_KEY=') && str_contains($installerLib, 'random_bytes(32)'), 'Installer generates canonical env and service key');
$check(str_contains($installerLib, 'installer_write_lock') && str_contains($installer, 'Installer is locked'), 'Installer locks after successful setup');
$check(str_contains($installerLib, 'installer_seed_demo') && str_contains($installer, 'Install demo data'), 'Optional demo data is supported');
$check(str_contains($config, 'is_file($canonicalEnvFile)') && str_contains($config, 'Legacy platform-specific env files remain a'), 'Canonical .env is preferred with legacy fallback');
$check(str_contains($config, "header(\$protocol . ' 419 Authentication Timeout'") && str_contains($runtime, '=== 419'), 'CSRF 419 has portable explicit status handling');
$check(str_contains($config, "if (!isset(\$_SESSION['last_regenerated']))") && !str_contains($config, "if (!isset(\$_SESSION['last_regenerated']) ||"), 'Initial anonymous session no longer regenerates twice');
$check(str_contains($runtime, 'function last_cookie') && str_contains($runtime, 'single authoritative session cookie'), 'Runtime probe uses authoritative session cookie and checks duplication');
$check(str_contains($runtime, "header_count(\$login['headers'], 'X-Powered-By') === 0"), 'Runtime probe verifies PHP technology header is hidden');
$check(!str_contains($apache, 'Header always set X-Frame-Options') && !str_contains($nginx, 'add_header X-Frame-Options'), 'Server config no longer duplicates application security headers');
$check(str_contains($apache, 'RewriteRule ^install/?$ install.php') && !preg_match('/FilesMatch[^>]+install\\.php/s', $apache), 'Apache exposes only guarded installer entrypoint');
$check(str_contains($nginx, 'location = /install') && !preg_match('/config\\.php[^\n]+install\\.php/', $nginx), 'Nginx exposes only guarded installer entrypoint');
$check(str_contains($uat, 'Contact advance balance schema') && str_contains($uat, 'Contact CRUD transaction') && str_contains($uat, 'Schema migrations current'), 'UAT catches contact schema drift and exercises CRUD');
$check(str_contains($api, 'pdo_exception_log_context($error, $action)'), 'Database failures log sanitized driver diagnostics');
$check(is_file($repoRoot . '/tests/runtime/runtime-contact-crud-check.php'), 'Runtime Customer/Supplier CRUD checker exists');
$check(!str_contains($builder, "'install.php'"), 'Release builder does not exclude web installer');
$check(str_contains($installer, 'INSTALLER_HTTPS_REQUIRED') && str_contains($installer, "Location: https://"), 'Public web installer enforces HTTPS');
$check(str_contains($installerLib, 'Database servers are commonly `localhost` even on public/shared hosting') && str_contains($installerLib, "str_ends_with(\$httpHost, '.test')"), 'Installer environment derives from web host instead of DB host');
exit($fail ? 1 : 0);
