<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

$repo = dirname(__DIR__, 2);
$failed = 0;
$checks = 0;

function r6_report(bool $ok, string $name, string $detail = ''): void
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

function r6_read(string $path): string
{
    return is_file($path) ? (string) file_get_contents($path) : '';
}

$shellPath = $repo . '/src/ui/app-shell.php';
$dashboardPath = $repo . '/src/dashboard.php';
$cssPath = $repo . '/src/ui-complete.css';
$shell = r6_read($shellPath);
$dashboard = r6_read($dashboardPath);
$css = r6_read($cssPath);

r6_report($shell !== '', 'Shared app shell exists');
r6_report($dashboard !== '', 'Dashboard wrapper exists');
r6_report($css !== '', 'Final UI stylesheet exists');

r6_report(str_contains($shell, '<div class="sidebar-foot developer-credit">'), 'Sidebar footer markup exists');
r6_report(str_contains($shell, 'class="company-note">Retail operations, simplified by Vib Tools.</span>'), 'Company line is rendered');
r6_report(str_contains($shell, '<a href="about.php" data-page="about">About</a>'), 'About link is rendered');
r6_report(!str_contains($shell, '$shellShowDeveloperCredit'), 'Shared shell has no footer visibility flag');
r6_report(!str_contains($shell, '<?php if ($shellShowDeveloperCredit): ?>'), 'Shared footer has no conditional wrapper');
r6_report(!str_contains($dashboard, '$shellShowDeveloperCredit'), 'Dashboard no longer suppresses company/About footer');

$wrappers = [];
$suppressors = [];
foreach (glob($repo . '/src/*.php') ?: [] as $file) {
    $source = r6_read($file);
    if (!str_contains($source, "require __DIR__ . '/ui/app-shell.php';")) {
        continue;
    }
    $name = basename($file);
    $wrappers[] = $name;
    if (str_contains($source, '$shellShowDeveloperCredit')) {
        $suppressors[] = $name;
    }
}
sort($wrappers);
sort($suppressors);

r6_report(count($wrappers) >= 65, 'Authenticated wrapper inventory scanned', 'wrappers=' . count($wrappers));
r6_report($suppressors === [], 'No authenticated wrapper can suppress the sidebar footer', $suppressors ? implode(',', $suppressors) : 'none');

foreach ([
    '.app-view .sidebar {' => 'sidebar structural rule',
    'height: 100vh;' => 'sidebar viewport height',
    'overflow: hidden;' => 'sidebar outer overflow lock',
    '.app-view .sidebar-nav {' => 'independent navigation scroll region',
    'flex: 1 1 auto;' => 'navigation flex sizing',
    'min-height: 0;' => 'navigation shrink invariant',
    'overflow-y: auto;' => 'navigation scroll invariant',
    '.app-view .sidebar-foot {' => 'persistent footer style',
    'flex: 0 0 auto;' => 'footer fixed flex sizing',
] as $needle => $name) {
    r6_report(str_contains($css, $needle), $name);
}

r6_report(!preg_match('/\.app-view\s+\.sidebar-foot\s*\{[^}]*display\s*:\s*none/si', $css), 'No final CSS rule hides the sidebar footer');
r6_report(!preg_match('/\.sidebar-foot\s*\{[^}]*display\s*:\s*none/si', $css), 'No generic CSS rule hides the sidebar footer');

echo "Final UI R6 footer-invariant regression: {$checks} checks, {$failed} failed." . PHP_EOL;
exit($failed ? 1 : 0);
