<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

$repo = dirname(__DIR__, 2);
$paths = [
    'complete' => $repo . '/src/ui-complete.css',
    'style' => $repo . '/src/style.css',
    'shellCss' => $repo . '/src/ui-shell.css',
    'componentsCss' => $repo . '/src/ui-components.css',
    'shell' => $repo . '/src/ui/app-shell.php',
    'nav' => $repo . '/src/ui/app-navigation.php',
    'index' => $repo . '/src/index.php',
    'install' => $repo . '/src/install.php',
    'about' => $repo . '/src/about.php',
    'app' => $repo . '/src/app.js',
];

$failed = 0;
$checks = 0;

function ui_complete_check(bool $ok, string $name, string $detail = ''): void
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

function ui_complete_read(string $path): string
{
    return is_file($path) ? (string) file_get_contents($path) : '';
}

function ui_complete_has(string $text, string $needle): bool
{
    return str_contains(str_replace("\r\n", "\n", $text), $needle);
}

$files = [];
foreach ($paths as $key => $path) {
    $files[$key] = ui_complete_read($path);
    ui_complete_check($files[$key] !== '', 'Required final-UI source exists: ' . basename($path));
}

$css = $files['complete'];
$style = $files['style'];
$shellCss = $files['shellCss'];
$componentsCss = $files['componentsCss'];
$shell = $files['shell'];
$nav = $files['nav'];
$index = $files['index'];
$install = $files['install'];
$about = $files['about'];
$app = $files['app'];

$contracts = [
    '--vr-ui-control: 30px;' => '30px final control target',
    '--vr-ui-action: 28px;' => '28px final action target',
    '--vr-ui-row-action: 26px;' => '26px row-action target',
    '--vr-ui-table-cell-y: 6px;' => '6px table vertical padding target',
    '--vr-ui-table-cell-x: 12px;' => '12px table horizontal padding target',
    '.app-view .content {' => 'final content rhythm',
    '.app-view .data-table th {' => 'final table header contract',
    '.app-view .data-table td {' => 'final table cell contract',
    '.app-view .row-button {' => 'final row-action contract',
    '.app-view input[type="checkbox"],' => 'final checkbox/radio dimension contract',
    '.app-view input[type="file"]::file-selector-button {' => 'custom file input contract',
    '.app-view .image-upload {' => 'compact image upload contract',
    '.app-view .status-tabs,' => 'compact status/tab contract',
    '.app-view .modal-backdrop {' => 'modal backdrop contract',
    '.app-view .modal {' => 'flat modal contract',
    '.app-view .toast {' => 'flat toast contract',
    '.app-view .loading-state {' => 'loading-state contract',
    '.app-view .empty-state {' => 'empty/error-state contract',
    '.app-view .quick-actions {' => 'dashboard quick-action contract',
    '.app-view .stat-card {' => 'dashboard stat-card contract',
    '.app-view .mini-card {' => 'dashboard mini-card contract',
    '.app-view .filter-bar {' => 'dashboard/report filter contract',
    '.app-view .transaction-layout {' => 'transaction layout contract',
    '.app-view #product-search-results {' => 'transaction live-search dropdown contract',
    '.app-view .product-live-result {' => 'transaction live-search row contract',
    '.app-view .transaction-side {' => 'transaction summary contract',
    '.app-view .report-metric {' => 'report metric contract',
    '.app-view .settings-grid {' => 'settings layout contract',
    '.app-view .settings-nav button {' => 'settings navigation contract',
    '.app-view .rma-status {' => 'RMA metric contract',
    '.app-view .marketplace-hero {' => 'marketplace compact hero contract',
    '.app-view .sms-package {' => 'SMS package contract',
    '.login-view .login-stage {' => 'compact split login architecture',
    '.login-view .stack-form input {' => 'compact login field contract',
    '.installer-view .card {' => 'compact installer card contract',
    '.installer-view :where(input, select) {' => 'compact installer controls',
    '.license-view .license-card {' => 'About page replaces public license card contract',
    '@media (max-width: 1280px)' => 'desktop/narrow responsive breakpoint',
    '@media (max-width: 980px)' => 'tablet responsive breakpoint',
    '@media (max-width: 680px)' => 'mobile responsive breakpoint',
    '@media (prefers-reduced-motion: reduce)' => 'reduced-motion contract',
    '@media print {' => 'compact print contract',
];

foreach ($contracts as $needle => $name) {
    ui_complete_check(ui_complete_has($css, $needle), $name);
}

$exactContracts = [
    'min-height: var(--vr-ui-action);' => 'final compact actions use final action token',
    'height: var(--vr-ui-control);' => 'final compact fields use final control token',
    'padding: var(--vr-ui-table-cell-y) var(--vr-ui-table-cell-x);' => 'table cell spacing uses final table tokens',
    'grid-template-columns: minmax(0, 1fr) 292px;' => 'desktop transaction summary width is compact',
    'min-height: 158px;' => 'dashboard stat card height reduced',
    'height: 4px;' => 'compact progress/meter rule exists',
];

foreach ($exactContracts as $needle => $name) {
    ui_complete_check(ui_complete_has($css, $needle), $name);
}

preg_match_all('/box-shadow\s*:\s*([^;]+);/i', $css, $shadowMatches);
$approvedShadow = '0 1px 2px rgba(0, 0, 0, .02)';
$nonZeroShadows = array_values(array_filter(
    $shadowMatches[1] ?? [],
    static fn(string $value): bool => !in_array(strtolower(trim($value)), ['none !important', 'none', strtolower($approvedShadow)], true)
));
ui_complete_check(
    count($nonZeroShadows) === 0,
    'Final UI stylesheet uses no elevation except approved KPI micro-shadow',
    $nonZeroShadows ? implode(', ', array_slice($nonZeroShadows, 0, 3)) : ''
);

ui_complete_check(
    !preg_match('/transform\s*:\s*(?:translate[xy]?|scale)\s*\(/i', $css),
    'Final UI stylesheet introduces no hover lift/scale'
);

ui_complete_check(
    !str_contains(strtolower($css), 'linear-gradient')
    && !str_contains(strtolower($css), 'radial-gradient'),
    'Final UI stylesheet introduces no gradient hierarchy'
);

preg_match_all('/#[0-9a-fA-F]{3,8}\b/', $css, $paletteMatches);
$approvedPalette = array_map('strtolower', [
    '#F3F4F6', '#FFFFFF', '#1F2937', '#6B7280', '#E5E7EB', '#2563EB', '#1D4ED8',
    '#10B981', '#3B82F6', '#F59E0B', '#8B5CF6', '#EF4444', '#F9FAFB', '#EFF6FF',
    '#D1D5DB', '#DEF7EC', '#03543F', '#FDE8E8', '#9B1C1C',
]);
$unexpectedPalette = array_values(array_filter(array_unique(array_map('strtolower', $paletteMatches[0] ?? [])), static fn(string $color): bool => !in_array($color, $approvedPalette, true)));
ui_complete_check(
    count($unexpectedPalette) === 0,
    'Final UI stylesheet uses only approved standalone palette values',
    $unexpectedPalette ? implode(', ', $unexpectedPalette) : ''
);

foreach ([
    '#0d1117',
    '#22d3ee',
    '#0891b2',
    '#38bdf8',
] as $foreignColor) {
    ui_complete_check(
        !str_contains(strtolower($css), $foreignColor),
        'VibTools reference palette not imported: ' . $foreignColor
    );
}

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
    ui_complete_check(
        ui_complete_has($style, $token),
        'Approved VibRetail palette retained: ' . strtok($token, ':')
    );
}

ui_complete_check(
    ui_complete_has($shellCss, '--vr-shell-topbar: var(--vr-topbar-target);')
    && ui_complete_has($shellCss, '--vr-shell-nav-row: 30px;'),
    'Accepted UI-02B shell contract remains intact'
);

ui_complete_check(
    ui_complete_has($componentsCss, 'min-height: var(--vr-input-medium);')
    && ui_complete_has($componentsCss, '.app-view .page-header {'),
    'Accepted UI-03A component layer remains intact'
);

$stylePos = strpos($shell, 'style.css?v=1.2.1');
$shellPos = strpos($shell, 'ui-shell.css?v=1.0.0');
$componentPos = strpos($shell, 'ui-components.css?v=1.0.0');
$completePos = strpos($shell, 'ui-complete.css?v=1.0.2');
ui_complete_check(
    $stylePos !== false
    && $shellPos !== false
    && $componentPos !== false
    && $completePos !== false
    && $stylePos < $shellPos
    && $shellPos < $componentPos
    && $componentPos < $completePos,
    'Authenticated stylesheet load order is deterministic'
);

ui_complete_check(
    ui_complete_has($index, 'ui-complete.css?v=1.0.2'),
    'Login surface loads final UI layer'
);
ui_complete_check(
    ui_complete_has($install, 'class="installer-view"')
    && ui_complete_has($install, 'ui-complete.css?v=1.0.2'),
    'Installer surface loads scoped final UI layer'
);
ui_complete_check(
    ui_complete_has($about, "require __DIR__ . '/ui/app-shell.php';"),
    'About surface replaces public license page through reusable shell'
);
ui_complete_check(
    ui_complete_has($app, 'ui-complete.css?v=1.0.0')
    && ui_complete_has($app, 'function printDocument(html, title)'),
    'Printed report/document path loads final compact layer'
);

foreach ([
    "async function api(action, options = {})",
    "async function renderDashboard(period = 'today')",
    "async function renderTransaction(kind, vatMode = false)",
    "async function renderSettings()",
    "async function renderAttendance()",
    "async function renderRole()",
    "async function renderProfile()",
] as $behaviorContract) {
    ui_complete_check(
        ui_complete_has($app, $behaviorContract),
        'Application behavior contract retained: ' . $behaviorContract
    );
}

ui_complete_check(
    ui_complete_has($shell, "'api' => 'api.php'"),
    'Shared shell API route contract retained: api.php'
);
foreach ([
    'dashboard',
    'sale-new',
    'purchase-new',
] as $pageKeyContract) {
    ui_complete_check(
        ui_complete_has($nav, "'" . $pageKeyContract . "'"),
        'Canonical navigation page key retained: ' . $pageKeyContract
    );
}
ui_complete_check(
    ui_complete_has($shell, 'href="profile.php"'),
    'Profile route contract retained: profile.php'
);

echo "Final UI completion regression: {$checks} checks, {$failed} failed." . PHP_EOL;
exit($failed ? 1 : 0);
