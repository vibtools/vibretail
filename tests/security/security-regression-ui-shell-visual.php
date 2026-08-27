<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

$repo = dirname(__DIR__, 2);
$cssPath = $repo . '/src/ui-shell.css';
$shellPath = $repo . '/src/ui/app-shell.php';
$stylePath = $repo . '/src/style.css';
$appPath = $repo . '/src/app.js';

$failed = 0;
$checks = 0;

function check_ui02b(bool $ok, string $name, string $detail = ''): void
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

function contains_ui02b(string $haystack, string $needle): bool
{
    return str_contains(str_replace("\r\n", "\n", $haystack), $needle);
}

$css = is_file($cssPath) ? (string) file_get_contents($cssPath) : '';
$shell = is_file($shellPath) ? (string) file_get_contents($shellPath) : '';
$style = is_file($stylePath) ? (string) file_get_contents($stylePath) : '';
$app = is_file($appPath) ? (string) file_get_contents($appPath) : '';

check_ui02b($css !== '', 'UI-02B shell stylesheet exists');
check_ui02b($shell !== '', 'Reusable app shell exists');
check_ui02b($style !== '', 'Primary style foundation exists');
check_ui02b($app !== '', 'Application JavaScript exists');

$cssContracts = [
    '--sidebar: var(--vr-sidebar-target);' => '196px sidebar token activated through UI-01 target',
    '--vr-shell-topbar: var(--vr-topbar-target);' => '44px topbar token activated',
    '--vr-shell-nav-row: 30px;' => '30px navigation row target',
    '--vr-shell-nav-icon: 18px;' => '18px navigation icon target',
    '--vr-shell-control: 28px;' => '28px shell control target',
    'height: 44px;' => '44px sidebar brand block',
    'width: 28px;' => '28px sidebar brand mark',
    'font-size: 12.5px;' => 'compact primary navigation text',
    'min-height: 26px;' => 'compact child navigation row',
    'height: var(--vr-shell-topbar);' => 'topbar uses compact target',
    'height: calc(100vh - var(--vr-shell-topbar));' => 'content viewport follows compact topbar',
    '.app-view .topbar-menu-anchor {' => 'dropdown positioning anchor',
    'box-shadow: none;' => 'flat shell elevation contract',
    '@media (max-width: 980px)' => 'tablet/mobile off-canvas shell override',
    '@media (max-width: 680px)' => 'small-screen compact topbar override',
    '@media (prefers-reduced-motion: reduce)' => 'reduced-motion shell behavior',
];

foreach ($cssContracts as $needle => $name) {
    check_ui02b(contains_ui02b($css, $needle), $name);
}

check_ui02b(
    substr_count($css, 'min-height: var(--vr-shell-nav-row);') === 1,
    'Primary nav row target has one canonical declaration'
);
check_ui02b(
    contains_ui02b($css, '.app-view .nav-link.active {')
    && contains_ui02b($css, 'background: var(--green);'),
    'Active navigation reuses VibRetail primary green'
);
check_ui02b(
    contains_ui02b($css, '.app-view .nav-link:hover,')
    && contains_ui02b($css, 'background: var(--green-soft);'),
    'Navigation hover reuses VibRetail soft green'
);
check_ui02b(
    !preg_match('/#[0-9a-fA-F]{3,8}\b/', $css),
    'UI-02B adds no standalone hex palette values'
);
check_ui02b(
    !str_contains(strtolower($css), 'linear-gradient'),
    'UI-02B shell adds no gradient hierarchy'
);
preg_match_all('/box-shadow\s*:\s*([^;]+);/i', $css, $shadowMatches);
$nonZeroShadows = array_filter(
    $shadowMatches[1] ?? [],
    static fn(string $value): bool => strtolower(trim($value)) !== 'none'
);
check_ui02b(
    count($nonZeroShadows) === 0,
    'UI-02B shell contains no non-zero box shadow'
);
check_ui02b(
    !preg_match('/transform\s*:\s*(?:translatey|scale)\s*\(/i', $css),
    'UI-02B shell contains no hover lift/scale transform'
);

$styleFrozen = [
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

foreach ($styleFrozen as $token) {
    check_ui02b(contains_ui02b($style, $token), 'Frozen VibRetail palette retained: ' . strtok($token, ':'));
}

$stylePos = strpos($shell, 'style.css?v=1.2.1');
$shellCssPos = strpos($shell, 'ui-shell.css?v=1.0.0');
check_ui02b(
    $stylePos !== false && $shellCssPos !== false && $shellCssPos > $stylePos,
    'Compact shell stylesheet loads after legacy style layer'
);
check_ui02b(
    substr_count($shell, 'class="topbar-menu-anchor"') >= 1
    && str_contains($shell, 'class="topbar-menu-anchor topbar-profile-anchor"'),
    'Quick Add and Profile menus have local positioning anchors'
);

foreach ([
    'id="sidebar"',
    'id="sidebar-scrim"',
    'id="sidebar-close"',
    'id="menu-toggle"',
    'id="quick-add"',
    'id="quick-menu"',
    'id="profile-button"',
    'id="profile-menu"',
    'id="logout-button"',
    'id="content"',
] as $domContract) {
    check_ui02b(str_contains($shell, $domContract), 'Shell DOM contract preserved: ' . $domContract);
}

check_ui02b(
    str_contains($app, "$('#quick-add')")
    && str_contains($app, "$('#profile-button')")
    && str_contains($app, "$('#menu-toggle')"),
    'Existing app.js shell interaction bindings remain available'
);

check_ui02b(
    !str_contains(strtolower($css), '#0d1117')
    && !str_contains(strtolower($css), '#22d3ee')
    && !str_contains(strtolower($css), '#0891b2'),
    'VibTools reference palette is not imported'
);

echo "UI-02B compact shell regression: {$checks} checks, {$failed} failed." . PHP_EOL;
exit($failed ? 1 : 0);
