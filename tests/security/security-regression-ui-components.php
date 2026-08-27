<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

$repo = dirname(__DIR__, 2);
$cssPath = $repo . '/src/ui-components.css';
$shellCssPath = $repo . '/src/ui-shell.css';
$shellPath = $repo . '/src/ui/app-shell.php';
$stylePath = $repo . '/src/style.css';

$failed = 0;
$checks = 0;

function check_ui03a(bool $ok, string $name, string $detail = ''): void
{
    global $failed, $checks;
    $checks++;
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $name;
    if ($detail !== '') {
        echo ' - ' . $detail;
    }
    echo PHP_EOL;
    if (!$ok) {
        $failed++;
    }
}

function has_ui03a(string $text, string $needle): bool
{
    return str_contains(str_replace("\r\n", "\n", $text), $needle);
}

$css = is_file($cssPath) ? (string) file_get_contents($cssPath) : '';
$shellCss = is_file($shellCssPath) ? (string) file_get_contents($shellCssPath) : '';
$shell = is_file($shellPath) ? (string) file_get_contents($shellPath) : '';
$style = is_file($stylePath) ? (string) file_get_contents($stylePath) : '';

check_ui03a($css !== '', 'UI-03A component stylesheet exists');
check_ui03a($shellCss !== '', 'UI-02B shell stylesheet remains present');
check_ui03a($shell !== '', 'Reusable app shell remains present');
check_ui03a($style !== '', 'Primary style foundation remains present');

$contracts = [
    '.app-view .page-header {' => 'compact page-header contract',
    'font-size: var(--vr-text-xl);' => '20px page-title target',
    'font-weight: var(--vr-weight-medium);' => 'medium typography hierarchy',
    '.app-view .panel {' => 'flat panel contract',
    'border-radius: var(--vr-radius-card);' => '12px panel radius',
    '.app-view .panel-pad,' => 'compact panel padding',
    '.app-view .button {' => 'compact generic button contract',
    'min-height: var(--vr-button-medium);' => '32px button target',
    '.app-view .form-grid {' => 'compact form-grid contract',
    '.app-view .form-field label {' => 'compact label contract',
    'min-height: var(--vr-input-medium);' => '34px field target',
    'min-height: var(--vr-textarea-min);' => '64px textarea target',
    '.app-view .form-actions {' => 'compact form-action contract',
    '.app-view .record-summary {' => 'compact record-summary contract',
    '.app-view .summary-item {' => 'compact summary-row contract',
    '@media (max-width: 680px)' => 'small-screen component density',
    '@media (prefers-reduced-motion: reduce)' => 'component reduced-motion behavior',
];

foreach ($contracts as $needle => $name) {
    check_ui03a(has_ui03a($css, $needle), $name);
}

check_ui03a(
    substr_count($css, 'min-height: var(--vr-input-medium);') === 1,
    'Field-height target has one canonical declaration'
);
check_ui03a(
    has_ui03a($css, 'background: var(--green);')
    && has_ui03a($css, 'background: var(--green-dark);'),
    'Primary button reuses VibRetail green semantics'
);
check_ui03a(
    !preg_match('/#[0-9a-fA-F]{3,8}\b/', $css),
    'UI-03A adds no standalone hex palette values'
);
check_ui03a(
    !str_contains(strtolower($css), 'linear-gradient'),
    'UI-03A adds no gradient hierarchy'
);

preg_match_all('/box-shadow\s*:\s*([^;]+);/i', $css, $shadowMatches);
$nonZeroShadows = array_filter(
    $shadowMatches[1] ?? [],
    static fn(string $value): bool => strtolower(trim($value)) !== 'none'
);
check_ui03a(
    count($nonZeroShadows) === 0,
    'UI-03A contains no non-zero box shadow'
);
check_ui03a(
    !preg_match('/transform\s*:\s*(?:translatey|scale)\s*\(/i', $css),
    'UI-03A contains no hover lift/scale transform'
);

foreach ([
    '--green: #2563EB;',
    '--green-dark: #1D4ED8;',
    '--green-soft: #EFF6FF;',
    '--blue: #3B82F6;',
    '--purple: #8B5CF6;',
    '--orange: #F59E0B;',
    '--red: #EF4444;',
    '--teal: #10B981;',
    '--ink: #1F2937;',
    '--muted: #6B7280;',
    '--line: #E5E7EB;',
    '--surface: #FFFFFF;',
    '--canvas: #F3F4F6;',
] as $token) {
    check_ui03a(has_ui03a($style, $token), 'Approved VibRetail palette retained: ' . strtok($token, ':'));
}

$stylePos = strpos($shell, 'style.css?v=1.2.1');
$shellPos = strpos($shell, 'ui-shell.css?v=1.0.0');
$componentPos = strpos($shell, 'ui-components.css?v=1.0.0');
check_ui03a(
    $stylePos !== false
    && $shellPos !== false
    && $componentPos !== false
    && $stylePos < $shellPos
    && $shellPos < $componentPos,
    'Component stylesheet loads after foundation and shell layers'
);

foreach ([
    '.data-table',
    '.table-toolbar',
    '.table-search',
    '.badge',
    '.row-actions',
    '.row-button',
    '.modal',
    '.toast',
    '.loading-state',
    '.empty-state',
    '.quick-action',
    '.filter-bar',
    '.stat-card',
    '.mini-card',
    '.transaction-layout',
    '.transaction-side',
] as $excludedSelector) {
    check_ui03a(
        !str_contains($css, $excludedSelector),
        'UI-03A scope excludes selector: ' . $excludedSelector
    );
}

check_ui03a(
    has_ui03a($shellCss, '--vr-shell-topbar: var(--vr-topbar-target);')
    && has_ui03a($shellCss, '--vr-shell-nav-row: 30px;'),
    'UI-02B compact shell contract remains intact'
);

check_ui03a(
    !str_contains(strtolower($css), '#0d1117')
    && !str_contains(strtolower($css), '#22d3ee')
    && !str_contains(strtolower($css), '#0891b2'),
    'VibTools reference palette is not imported'
);

echo "UI-03A core component regression: {$checks} checks, {$failed} failed." . PHP_EOL;
exit($failed ? 1 : 0);
