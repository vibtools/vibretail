<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

$repoRoot = realpath(dirname(__DIR__, 2));
if ($repoRoot === false) {
    fwrite(STDERR, "[FAIL] Unable to resolve repository root.\n");
    exit(2);
}
chdir($repoRoot);

function static_command(array $parts): string
{
    return implode(' ', array_map('escapeshellarg', $parts));
}

function static_run(array $parts, string $label): void
{
    $command = static_command($parts);
    passthru($command, $code);
    if ($code !== 0) {
        fwrite(STDERR, "[FAIL] {$label} exited with code {$code}.\n");
        exit($code ?: 1);
    }
}

echo '[INFO] Repo root: ' . $repoRoot . PHP_EOL;
echo '[INFO] PHP: ' . PHP_BINARY . PHP_EOL;
static_run([PHP_BINARY, '-v'], 'PHP runtime');

$phpFiles = [];
foreach (['src', 'scripts', 'tests'] as $directory) {
    $root = $repoRoot . DIRECTORY_SEPARATOR . $directory;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
            $phpFiles[] = $file->getPathname();
        }
    }
}
sort($phpFiles, SORT_STRING);

foreach ($phpFiles as $file) {
    $output = [];
    $code = 0;
    exec(static_command([PHP_BINARY, '-l', $file]) . ' 2>&1', $output, $code);
    if ($code !== 0) {
        fwrite(STDERR, '[FAIL] PHP syntax: ' . $file . PHP_EOL . implode(PHP_EOL, $output) . PHP_EOL);
        exit(1);
    }
}
echo '[PASS] PHP syntax: ' . count($phpFiles) . ' files.' . PHP_EOL;

$regressions = [
    'tests/security/security-regression.php',
    'tests/security/security-regression-phase02.php',
    'tests/security/security-regression-local-xampp.php',
    'tests/security/security-regression-easy-setup.php',
    'tests/security/security-regression-ui-foundation.php',
    'tests/security/security-regression-ui-shell.php',
    'tests/security/security-regression-ui-shell-visual.php',
    'tests/security/security-regression-ui-components.php',
    'tests/security/security-regression-ui-complete.php',
    'tests/security/security-regression-ui-complete-r2.php',
    'tests/security/security-regression-ui-color-reference.php',
    'tests/security/security-regression-ui-complete-r4.php',
    'tests/security/security-regression-ui-complete-r5.php',
    'tests/security/security-regression-ui-complete-r6.php',
    'tests/security/security-regression-release-automation.php',
];

foreach ($regressions as $regression) {
    if (!is_file($repoRoot . DIRECTORY_SEPARATOR . $regression)) {
        fwrite(STDERR, "[FAIL] Missing regression: {$regression}\n");
        exit(1);
    }
    static_run([PHP_BINARY, $regression], $regression);
}

exec('node --version 2>&1', $nodeVersion, $nodeCode);
if ($nodeCode === 0) {
    static_run(['node', '--check', 'src/app.js'], 'JavaScript syntax');
    echo '[PASS] Node syntax check (' . trim(implode(' ', $nodeVersion)) . ').' . PHP_EOL;
} else {
    echo '[INFO] Node unavailable; JS syntax check skipped.' . PHP_EOL;
}

echo '[PASS] VibRetail repository static checks complete.' . PHP_EOL;
