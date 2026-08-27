<?php
declare(strict_types=1);

$repoRoot = realpath(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..');
if ($repoRoot === false) {
    fwrite(STDERR, "[FAIL] Unable to resolve repository root.\n");
    exit(2);
}
$cssPath = $repoRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'style.css';
$css = @file_get_contents($cssPath);
if (!is_string($css)) {
    fwrite(STDERR, "[FAIL] Unable to read src/style.css.\n");
    exit(2);
}

$checks = [];
$pass = static function (string $label, bool $condition) use (&$checks): void {
    $checks[] = [$label, $condition];
    echo ($condition ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
};

$frozen = [
    '--green: #00b96b;',
    '--green-dark: #008f55;',
    '--green-soft: #ddf9ec;',
    '--blue: #1769ef;',
    '--purple: #8d23e8;',
    '--orange: #ec8700;',
    '--red: #f20d4f;',
    '--teal: #00a997;',
    '--ink: #15211d;',
    '--muted: #6b7973;',
    '--line: #e7eeeb;',
    '--surface: #ffffff;',
    '--canvas: #f5f8f7;',
];
foreach ($frozen as $token) {
    $pass('Frozen theme token retained: ' . strtok($token, ':'), str_contains($css, $token));
}

$required = [
    '--vr-font-sans: Inter,',
    '--vr-font-mono: "JetBrains Mono",',
    '--vr-text-xs: 11px;',
    '--vr-text-sm: 12px;',
    '--vr-text-base: 13px;',
    '--vr-text-md: 14px;',
    '--vr-text-lg: 16px;',
    '--vr-text-xl: 20px;',
    '--vr-weight-regular: 400;',
    '--vr-weight-medium: 500;',
    '--vr-weight-semibold: 600;',
    '--vr-sidebar-target: 196px;',
    '--vr-topbar-target: 44px;',
    '--vr-button-compact: 28px;',
    '--vr-button-medium: 32px;',
    '--vr-input-compact: 30px;',
    '--vr-input-medium: 34px;',
    '--vr-textarea-min: 64px;',
    '--vr-radius-small: 6px;',
    '--vr-radius-control: 8px;',
    '--vr-radius-card: 12px;',
    '--vr-content-max: 1280px;',
    '--vr-content-gutter: 16px;',
    '--vr-motion-fast: 100ms ease;',
    '--vr-motion-normal: 120ms ease;',
    '--vr-motion-slow: 150ms ease;',
    '--font: var(--vr-font-sans);',
    'font-size: var(--vr-text-base);',
    '.vr-card {',
    '.vr-btn {',
    '.vr-input,',
    '.vr-table {',
    '@media (prefers-reduced-motion: reduce)',
];
foreach ($required as $needle) {
    $pass('UI-01 foundation contract: ' . $needle, str_contains($css, $needle));
}

$lower = strtolower($css);
foreach (['#0d1117', '#22d3ee', '#0891b2'] as $vibToolsPalette) {
    $pass('VibTools palette not imported: ' . $vibToolsPalette, !str_contains($lower, $vibToolsPalette));
}

$pass('Legacy sidebar size remains active until UI-02', str_contains($css, '--sidebar: 254px;'));
$pass('Legacy shadow token remains active until UI-03', str_contains($css, '--shadow: 0 12px 30px rgba(29, 62, 49, .08);'));
$pass('Foundation controls have no elevation', str_contains($css, '--vr-elevation: none;') && str_contains($css, 'box-shadow: none;'));
$pass('Foundation hover contract has no lift', str_contains($css, '.vr-btn:hover { transform: none; box-shadow: none; }'));

$failed = count(array_filter($checks, static fn(array $c): bool => !$c[1]));
echo sprintf("UI-01 foundation regression: %d checks, %d failed.%s", count($checks), $failed, PHP_EOL);
exit($failed === 0 ? 0 : 1);
