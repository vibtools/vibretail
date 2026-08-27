<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
$repo = dirname(__DIR__, 2);
$style = (string) file_get_contents($repo . '/src/style.css');
$css = (string) file_get_contents($repo . '/src/ui-complete.css');
$app = (string) file_get_contents($repo . '/src/app.js');
$failed = 0; $checks = 0;
function color_check(bool $ok, string $name): void { global $failed,$checks; $checks++; echo ($ok?'[PASS] ':'[FAIL] ').$name.PHP_EOL; if(!$ok)$failed++; }
$tokens = [
    '--green: #2563EB;' => 'primary blue',
    '--green-dark: #1D4ED8;' => 'primary hover blue',
    '--green-soft: #EFF6FF;' => 'active soft blue',
    '--blue: #3B82F6;' => 'purchase blue',
    '--purple: #8B5CF6;' => 'account purple',
    '--orange: #F59E0B;' => 'cashflow amber',
    '--red: #EF4444;' => 'expense red',
    '--teal: #10B981;' => 'sales emerald',
    '--ink: #1F2937;' => 'primary text',
    '--muted: #6B7280;' => 'muted text',
    '--line: #E5E7EB;' => 'border neutral',
    '--surface: #FFFFFF;' => 'white surface',
    '--canvas: #F3F4F6;' => 'body gray',
    '--vr-color-nav-hover: #F9FAFB;' => 'nav/table hover neutral',
    '--vr-color-border-hover: #D1D5DB;' => 'hover border neutral',
    '--vr-color-paid-soft: #DEF7EC;' => 'paid badge surface',
    '--vr-color-paid-text: #03543F;' => 'paid badge text',
    '--vr-color-due-soft: #FDE8E8;' => 'due badge surface',
    '--vr-color-due-text: #9B1C1C;' => 'due badge text',
];
foreach($tokens as $needle=>$label) color_check(str_contains($style,$needle), 'Approved reference color: '.$label);
foreach([
    '--vr-approved-primary: #2563EB;',
    '--vr-approved-sales: #10B981;',
    '--vr-approved-purchase: #3B82F6;',
    '--vr-approved-cashflow: #F59E0B;',
    '--vr-approved-account: #8B5CF6;',
    '--vr-approved-expense: #EF4444;',
] as $needle) color_check(str_contains($css,$needle), 'Final CSS color contract: '.$needle);
color_check(str_contains($css,'.app-view .nav-link.active,') && str_contains($css,'border-left-color: var(--vr-approved-primary);'), 'Sidebar active uses approved blue accent');
color_check(str_contains($css,'background: var(--vr-approved-active);'), 'Sidebar active uses #EFF6FF surface');
color_check(str_contains($css,'.app-view .dashboard-commandbar .quick-action {') && str_contains($css,'background: var(--vr-approved-surface) !important;'), 'Dashboard quick actions neutralized');
color_check(str_contains($css,'.app-view .dashboard-commandbar .quick-action:first-child {') && str_contains($css,'background: var(--vr-approved-primary) !important;'), 'Dashboard has one primary CTA');
foreach(['sales','purchase','cashflow','account','expense','low-stock'] as $class) color_check(str_contains($css,'.app-view .kpi-card.'.$class), 'Dashboard KPI semantic accent: '.$class);
color_check(str_contains($css,'border-left-width: 4px;') && str_contains($css,'border-radius: 6px;') && str_contains($css,'padding: 14px;'), 'Dashboard KPI card matches approved card geometry');
color_check(str_contains($css,'box-shadow: 0 1px 2px rgba(0, 0, 0, .02);'), 'Dashboard KPI card uses approved subtle shadow');
color_check(str_contains($css,'.app-view .data-table thead th {') && str_contains($css,'background: var(--vr-approved-hover);'), 'Table header uses approved #F9FAFB neutral');
color_check(str_contains($app,'stroke="#10B981"') && str_contains($app,'stroke="#3B82F6"') && str_contains($app,'stroke="#EF4444"'), 'Dashboard trend uses exact semantic colors');
foreach(['kpi-card sales','kpi-card purchase','kpi-card cashflow','kpi-card expense','kpi-card account','kpi-card low-stock'] as $needle) color_check(str_contains($app,$needle),'Dashboard renderer semantic class: '.$needle);
foreach(['#00b96b','#008f55','#ddf9ec','#1769ef','#8d23e8','#ec8700','#f20d4f','#00a997','#15211d','#6b7973','#e7eeeb','#f5f8f7'] as $legacy) color_check(!str_contains(strtolower(substr($style,0,strpos($style,'--vr-font-sans'))), strtolower($legacy)), 'Legacy root palette removed: '.$legacy);
echo "Approved color-reference regression: {$checks} checks, {$failed} failed.".PHP_EOL;
exit($failed?1:0);
