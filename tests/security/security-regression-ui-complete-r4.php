<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
$repo = dirname(__DIR__, 2);
$files = [
    'css' => $repo . '/src/ui-complete.css',
    'app' => $repo . '/src/app.js',
    'shell' => $repo . '/src/ui/app-shell.php',
    'about' => $repo . '/src/about.php',
    'config' => $repo . '/src/config.php',
    'api' => $repo . '/src/api.php',
];
$failed=0;$checks=0;
function r4(bool $ok,string $name):void{global $failed,$checks;$checks++;echo($ok?'[PASS] ':'[FAIL] ').$name.PHP_EOL;if(!$ok)$failed++;}
function readr4(string $p):string{return is_file($p)?(string)file_get_contents($p):'';}
$src=[];foreach($files as $k=>$p){$src[$k]=readr4($p);r4($src[$k]!=='' ,'R4 source exists: '.$k);}
$css=$src['css'];$app=$src['app'];$shell=$src['shell'];$about=$src['about'];$config=$src['config'];$api=$src['api'];
foreach([
    'height: 44px;' => '44px compact topbar',
    '.app-view .topbar-left,' => 'compact header group spacing',
    '.app-view .settings-section[hidden]' => 'working settings section visibility contract',
    '.app-view .settings-backup-card {' => 'settings backup card',
    '.app-view .about-hero {' => 'About hero contract',
    '.app-view .about-grid {' => 'About capability grid',
] as $needle=>$name) r4(str_contains($css,$needle),$name);
foreach([
    "['profile', 'Business Profile']" => 'Business Profile tab',
    "['invoice', 'Invoice Setup']" => 'Invoice Setup tab',
    "['pos', 'POS Settings']" => 'POS Settings tab',
    "['backup', 'Backup']" => 'Backup tab',
    'data-settings-tab="${key}"' => 'settings tab markup contract',
    'const activateSettingsTab' => 'settings tab controller',
    'async function renderAbout()' => 'About renderer',
    'about: renderAbout' => 'About route',
    'Modern retail operations by Vib Tools' => 'legacy tagline normalized in UI',
] as $needle=>$name) r4(str_contains($app,$needle),$name);
r4(str_contains($shell,'href="settings.php" data-page="settings"'),'Header System control navigates to settings');
r4(str_contains($shell,'href="about.php" data-page="about"'),'Sidebar footer links About');
r4(!str_contains($shell,'href="license.php"'),'Public shell no longer links license.php');
r4(str_contains($about,"\$pageKey = 'about';"),'About physical page metadata');
r4(str_contains($config,'function normalize_brand_settings'),'Server brand-settings normalization helper');
r4(str_contains($config,"Modern retail operations by Vib Tools"),'Server legacy tagline normalization');
r4(str_contains($config,"cloudcoresoft.com"),'Server detects exact legacy website defaults');
r4(substr_count($api,'normalize_brand_settings(')>=2,'API normalizes bootstrap/document settings');
r4(!is_file($repo . '/src/license.php'),'Public license.php removed');
r4(str_contains($app,'VibRetail is a compact retail operations suite'),'About product marketing copy');
r4(str_contains($app,'Vib Tools builds practical software products'),'About Vib Tools company copy');
echo "Final UI R4 regression: {$checks} checks, {$failed} failed.".PHP_EOL;exit($failed?1:0);
