<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
$repo = dirname(__DIR__, 2);
$files = [
    'css' => $repo . '/src/ui-complete.css',
    'app' => $repo . '/src/app.js',
    'config' => $repo . '/src/config.php',
    'shell' => $repo . '/src/ui/app-shell.php',
    'index' => $repo . '/src/index.php',
    'install' => $repo . '/src/install.php',
    'about' => $repo . '/src/about.php',
    'licenseText' => $repo . '/src/LICENSE.md',
    'uat' => $repo . '/tests/uat/uat.php',
];
$failed = 0; $checks = 0;
function r2check(bool $ok, string $name): void { global $failed,$checks; $checks++; echo ($ok?'[PASS] ':'[FAIL] ').$name.PHP_EOL; if(!$ok)$failed++; }
$text=[]; foreach($files as $k=>$p){ $text[$k]=is_file($p)?(string)file_get_contents($p):''; r2check($text[$k] !== '', "R2 source exists: {$k}"); }

$app=$text['app']; $css=$text['css'];
foreach([
    'const paginationRegistry = new WeakMap();' => 'global pagination registry',
    'const PAGINATION_DEFAULT = 20;' => '20-row default pagination',
    'function enhanceTablePagination(table)' => 'universal table paginator',
    'function enhanceActivityPagination(list)' => 'activity/log paginator',
    'function finalizePageUi(root = document)' => 'final page cleanup hook',
    "row.dataset.searchMatch" => 'search-aware pagination',
    "finalizePageUi(content());" => 'pagination/cleanup runs after route render',
    "finalizePageUi($('#modal-root'));" => 'pagination/cleanup runs in modals',
] as $needle=>$name) r2check(str_contains($app,$needle),$name);

r2check(!str_contains($app, '<p>${esc(subtitle)}</p>'), 'pageHeader no longer renders decorative subtitle');
r2check(str_contains($app, ".panel-title p, .table-toolbar > div > p, .page-header p"), 'decorative section/table subtitles removed at runtime');
r2check(str_contains($app, 'dashboard-advanced'), 'advanced dashboard renderer active');
r2check(str_contains($app, 'dashboard-kpi-grid'), 'advanced dashboard KPI grid');
r2check(str_contains($app, 'dashboard-primary-grid'), 'advanced dashboard primary analysis grid');
r2check(str_contains($app, 'dashboard-secondary-grid'), 'advanced dashboard secondary operations grid');
r2check(str_contains($app, 'Cash Position'), 'advanced dashboard cash position');
r2check(str_contains($app, 'Recent Sales'), 'advanced dashboard recent sales');
r2check(str_contains($app, 'data-pagination="off"'), 'bounded dashboard latest table avoids redundant pager');

foreach([
    '.app-view .data-pager {' => 'pager visual contract',
    '.app-view .pager-button {' => 'pager button contract',
    '.app-view .nav-link.active {' => 'sidebar active contract',
    'border-left-color: var(--green);' => 'sidebar active accent uses VibRetail green',
    '.app-view .nav-link:hover,' => 'sidebar hover contract',
    '.app-view .dashboard-kpi-grid {' => 'dashboard KPI CSS',
    '.app-view .dashboard-primary-grid {' => 'dashboard primary CSS',
    '.app-view .dashboard-ledger {' => 'dashboard cash ledger CSS',
] as $needle=>$name) r2check(str_contains($css,$needle),$name);

preg_match_all('/box-shadow\s*:\s*([^;]+);/i', $css, $shadowMatches); $approvedShadow='0 1px 2px rgba(0, 0, 0, .02)'; $nonZero=array_filter($shadowMatches[1]??[], static fn(string $v): bool => !in_array(strtolower(trim($v)),['none','none !important',strtolower($approvedShadow)],true)); r2check(count($nonZero)===0, 'R2/R3 uses no elevation except approved KPI micro-shadow');
r2check(!preg_match('/transform\s*:\s*(?:translateY|scale)\s*\(/i',$css), 'R2 introduces no hover lift/scale');
preg_match_all('/#[0-9a-fA-F]{3,8}\b/',$css,$paletteMatches); $allowed=array_map('strtolower',['#F3F4F6','#FFFFFF','#1F2937','#6B7280','#E5E7EB','#2563EB','#1D4ED8','#10B981','#3B82F6','#F59E0B','#8B5CF6','#EF4444','#F9FAFB','#EFF6FF','#D1D5DB','#DEF7EC','#03543F','#FDE8E8','#9B1C1C']); $unexpected=array_values(array_filter(array_unique(array_map('strtolower',$paletteMatches[0]??[])),static fn(string $c):bool=>!in_array($c,$allowed,true))); r2check(count($unexpected)===0,'R2/R3 CSS uses only approved standalone palette values');

foreach([
    "define('SOFTWARE_NAME', 'VibRetail');" => 'runtime software name VibRetail',
    "define('DEVELOPER_NAME', 'Vib Tools');" => 'runtime developer attribution Vib Tools',
    "define('DEVELOPER_COMPANY', 'Vib Tools');" => 'runtime company Vib Tools',
    "https://github.com/vibtools/vibretail" => 'runtime GitHub points to VibRetail repository',
] as $needle=>$name) r2check(str_contains($text['config'],$needle),$name);

r2check(str_contains($text['shell'], 'DEVELOPER_LOGO_URL') || str_contains($text['shell'], '?>VR<?php endif; ?>'), 'sidebar fallback uses approved VibRetail/Vib Tools identity');
r2check(!str_contains($text['shell'], '<p><?= htmlspecialchars($pageSubtitle'), 'server shell decorative subtitle removed');
r2check(str_contains($text['index'], 'SOFTWARE_NAME') && str_contains($text['index'], 'if ($businessName === \'Cloud Core POS\')'), 'login uses authorized software name with legacy-default normalization');
r2check(str_contains($text['install'], 'VibRetail'), 'installer uses VibRetail branding');
r2check(str_contains($text['about'], "\$pageKey = 'about';"), 'About presentation replaces public license page');
r2check(str_contains($text['licenseText'], '# VibRetail Free Non-Commercial Attribution License 1.0'), 'license identity renamed without changing license model');
r2check(str_contains($text['licenseText'], 'Developer: Vib Tools'), 'license attribution names Vib Tools');
r2check(str_contains($text['uat'], "DEVELOPER_NAME === 'Vib Tools'"), 'UAT validates authorized Vib Tools attribution');
r2check(str_contains($text['uat'], "'VibRetail branding'"), 'UAT branding gate renamed');

foreach(['--green: #2563EB;','--green-dark: #1D4ED8;','--green-soft: #EFF6FF;','--ink: #1F2937;','--surface: #FFFFFF;'] as $token) {
    $style=(string)file_get_contents($repo.'/src/style.css'); r2check(str_contains($style,$token),'Approved palette retained: '.strtok($token,':'));
}

r2check(str_contains($app, "if (state.settings.business_name === 'Cloud Core POS') state.settings.business_name = 'VibRetail';"), 'legacy DB default brand normalized client-side');
r2check(str_contains($text['shell'], 'if (!empty($settings[\'logo_data\']))'), 'custom business logo remains supported');
r2check(str_contains((string)file_get_contents($repo.'/src/ui/app-context.php'), 'normalize_brand_settings('), 'legacy DB default brand normalized server-side');

echo "Final UI R2 regression: {$checks} checks, {$failed} failed.".PHP_EOL;
exit($failed ? 1 : 0);
