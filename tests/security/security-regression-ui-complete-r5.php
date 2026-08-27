<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

$repo = dirname(__DIR__, 2);
$paths = [
    'config' => $repo . '/src/config.php',
    'shell' => $repo . '/src/ui/app-shell.php',
    'css' => $repo . '/src/ui-complete.css',
    'app' => $repo . '/src/app.js',
    'index' => $repo . '/src/index.php',
    'install' => $repo . '/src/install.php',
    'installer' => $repo . '/src/installer-lib.php',
    'uat' => $repo . '/tests/uat/uat.php',
];
$failed = 0; $checks = 0;
function r5(bool $ok, string $name): void { global $failed,$checks; $checks++; echo ($ok?'[PASS] ':'[FAIL] ').$name.PHP_EOL; if(!$ok)$failed++; }
function rr5(string $p): string { return is_file($p) ? (string) file_get_contents($p) : ''; }
$src=[]; foreach($paths as $k=>$p){ $src[$k]=rr5($p); r5($src[$k] !== '', 'R5 source exists: '.$k); }

$config=$src['config'];$shell=$src['shell'];$css=$src['css'];$app=$src['app'];$index=$src['index'];$install=$src['install'];$installer=$src['installer'];$uat=$src['uat'];

foreach([
    "define('DEVELOPER_COMPANY_URL', 'https://vib.tools/');" => 'official Vib Tools website',
    "define('DEVELOPER_CONTACT_URL', 'https://vib.tools/contact');" => 'official contact page',
    "define('DEVELOPER_LOGO_URL', 'https://vibtools.github.io/vibtools-brand-assets/logos/icon-512.png');" => 'official brand icon',
    "define('DEVELOPER_WHATSAPP_NUMBER', '+880 1795-470603');" => 'WhatsApp display number',
    "define('DEVELOPER_WHATSAPP_URL', 'https://wa.me/8801795470603');" => 'WhatsApp deep link',
    "define('DEVELOPER_EMAIL', 'hello@vib.tools');" => 'company email',
    "define('DEVELOPER_SUPPORT_EMAIL', 'support@vib.tools');" => 'support email',
    "define('DEVELOPER_FACEBOOK_URL', 'https://www.facebook.com/vib.tools');" => 'Facebook link',
    "define('DEVELOPER_X_URL', 'https://x.com/vibtools');" => 'X link',
    "define('DEVELOPER_INSTAGRAM_URL', 'https://www.instagram.com/vib.tools');" => 'Instagram link',
    "define('DEVELOPER_REDDIT_URL', 'https://www.reddit.com/user/VibTools/');" => 'Reddit link',
] as $needle=>$name) r5(str_contains($config,$needle),$name);

r5(str_contains($config,"img-src 'self' data: blob: https://vibtools.github.io;"),'CSP permits only official external brand-image origin');
r5(!str_contains($config,"img-src *"),'CSP does not broaden images to wildcard');

foreach([
    'class="company-note">Retail operations, simplified by Vib Tools.' => 'user-facing persistent company footer',
    'class="company-links"' => 'company footer links',
    'class="whatsapp-link"' => 'header WhatsApp control',
    'DEVELOPER_WHATSAPP_NUMBER' => 'header WhatsApp number',
    'aria-label="Open profile menu"' => 'icon-only profile control remains accessible',
    'DEVELOPER_LOGO_URL' => 'shell uses official icon URL',
    'rel="icon"' => 'shell favicon',
    "'companyContact' => DEVELOPER_CONTACT_URL" => 'About config contact payload',
] as $needle=>$name) r5(str_contains($shell,$needle),$name);

r5(!str_contains($shell,'Database connected'),'developer-facing database status removed from sidebar');
r5(!preg_match('/id="profile-button"[^>]*>.*?<i>/s',$shell),'profile name removed from header button');
r5(!str_contains($shell,'Support:'),'Support label removed from header');

foreach([
    '.app-view .sidebar-nav {' => 'sidebar nav sizing contract',
    'min-height: 0;' => 'sidebar scroll region can shrink',
    '.app-view .sidebar-foot {' => 'persistent footer contract',
    'flex: 0 0 auto;' => 'footer cannot be pushed out by nav',
    'grid-template-columns: minmax(0, 1fr) auto;' => 'stable header left/right layout',
    '.app-view .outline-pill {' => 'header button geometry',
    'white-space: nowrap;' => 'header button text stays intact',
    '.app-view .profile-button {' => 'icon-only profile sizing',
    '.app-view .whatsapp-link {' => 'WhatsApp visual contract',
    '.app-view .about-contact {' => 'About contact section',
    '.app-view .about-socials {' => 'About social links',
] as $needle=>$name) r5(str_contains($css,$needle),$name);

foreach([
    'vib.tools/contact' => 'About official contact link',
    'hello@vib.tools' => 'About company email',
    'support@vib.tools' => 'About support email',
    'facebook.com/vib.tools' => 'About Facebook',
    'x.com/vibtools' => 'About X',
    'instagram.com/vib.tools' => 'About Instagram',
    'reddit.com/user/VibTools' => 'About Reddit',
    'Open tools for focused teams.' => 'About company marketing heading',
    'open-source developer ecosystem' => 'About official company positioning',
] as $needle=>$name) r5(str_contains($app,$needle),$name);

r5(str_contains($index,'DEVELOPER_LOGO_URL'),'login uses official Vib Tools icon');
r5(str_contains($index,'DEVELOPER_CONTACT_URL'),'login links official contact page');
r5(!str_contains($index,'license.php'),'login contains no retired public license link');
r5(str_contains($install,'DEVELOPER_LOGO_URL'),'installer uses official Vib Tools icon');
r5(str_contains($installer,'Retail operations, simplified by Vib Tools.'),'fresh installer uses current tagline');
r5(str_contains($uat,"License files and About route"),'UAT follows retired public license route');
r5(str_contains($uat,"!is_file(dirname(__DIR__, 2) . '/src/license.php')"),'UAT explicitly requires retired public license.php to remain absent');

echo "Final UI R5 regression: {$checks} checks, {$failed} failed.".PHP_EOL;
exit($failed ? 1 : 0);
