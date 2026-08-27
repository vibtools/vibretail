<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
require __DIR__ . '/installer-lib.php';

if (PHP_SAPI === 'cli') {
    $options = getopt('', ['admin-phone:', 'admin-name:', 'admin-password:', 'demo', 'help']);
    if (isset($options['help'])) {
        echo "Cloud Core POS recovery installer / migration CLI\n";
        echo "Existing configured DB: php install.php\n";
        echo "Fresh configured DB: php install.php --admin-phone=... --admin-name=... --admin-password=... [--demo]\n";
        exit(0);
    }
    try {
        $pdo = db();
        $pdo->exec(installer_schema_sql());
        $migrations = run_schema_migrations($pdo, DB_NAME);
        installer_seed_core($pdo, ['name' => SOFTWARE_NAME]);
        if ((int) $pdo->query("SELECT COUNT(*) FROM users WHERE status=1 AND LOWER(role) IN ('admin','administrator')")->fetchColumn() === 0) {
            $password = (string) ($options['admin-password'] ?? (getenv('POS_BOOTSTRAP_ADMIN_PASSWORD') ?: ''));
            installer_create_admin($pdo, [
                'name' => (string) ($options['admin-name'] ?? 'System Administrator'),
                'phone' => (string) ($options['admin-phone'] ?? ''),
                'password' => $password,
            ]);
            if (isset($options['demo'])) installer_seed_demo($pdo);
        }
        $checks = installer_self_test($pdo, DB_NAME);
        if (in_array(false, $checks, true)) throw new RuntimeException('CLI self-test did not pass.');
        echo 'Installation/migration complete.' . PHP_EOL;
        echo 'Migrations applied: ' . count($migrations) . PHP_EOL;
        exit(0);
    } catch (Throwable $error) {
        app_log('error', 'CLI installation/migration failed', ['exception' => get_class($error), 'message' => $error->getMessage()]);
        fwrite(STDERR, 'Installation failed. Request ID: ' . request_id() . '. ' . $error->getMessage() . PHP_EOL);
        exit(1);
    }
}

if (empty($_SESSION['installer_csrf'])) {
    $_SESSION['installer_csrf'] = bin2hex(random_bytes(24));
}

// Never collect database or administrator credentials over cleartext HTTP on
// a non-local host. Localhost remains HTTP-friendly for XAMPP development.
$installerHost = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? 'localhost')));
$installerHost = preg_replace('/:\d+$/', '', $installerHost) ?? $installerHost;
$installerLocalHost = in_array($installerHost, ['localhost', '127.0.0.1', '::1'], true)
    || str_ends_with($installerHost, '.localhost')
    || str_ends_with($installerHost, '.test');
if (!$installerLocalHost && !request_is_https()) {
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'GET') {
        $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string) ($_SERVER['HTTP_HOST'] ?? ''));
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/install');
        if ($host !== '') {
            header('Location: https://' . $host . $uri, true, 302);
            exit;
        }
    }
    respond(['ok' => false, 'message' => 'Secure HTTPS is required to run the installer on a public host.'], 400, 'INSTALLER_HTTPS_REQUIRED');
}

function installer_require_csrf(): void
{
    $token = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!hash_equals((string) ($_SESSION['installer_csrf'] ?? ''), $token)) {
        respond(['ok' => false, 'message' => 'Installer session expired. Refresh the installer and try again.'], 419, 'INSTALLER_CSRF_FAILED');
    }
}

function installer_rate_limit(string $key, int $maxAttempts, int $windowSeconds): void
{
    $now = time();
    $attempts = array_values(array_filter((array) ($_SESSION[$key] ?? []), static fn($time): bool => $now - (int) $time < $windowSeconds));
    if (count($attempts) >= $maxAttempts) {
        header('Retry-After: ' . $windowSeconds);
        respond(['ok' => false, 'message' => 'Too many installer attempts. Wait a moment and try again.'], 429, 'INSTALLER_RATE_LIMITED');
    }
    $attempts[] = $now;
    $_SESSION[$key] = $attempts;
}

$state = installer_state();
$installerAction = clean_string($_GET['installer_action'] ?? '', 40);

if ($installerAction !== '') {
    require_http_method('POST');
    installer_require_csrf();
    try {
        $data = json_input();
        if ($installerAction === 'test_database') {
            if ($state['mode'] !== 'fresh') respond(['ok' => false, 'message' => 'Database setup is already complete.'], 409, 'INSTALLER_ALREADY_CONFIGURED');
            installer_rate_limit('installer_db_attempts', 12, 60);
            $database = is_array($data['database'] ?? null) ? $data['database'] : [];
            $test = installer_test_database($database, !empty($data['create_database']));
            respond(['ok' => true, 'message' => 'Database connection successful.', 'server_version' => $test['version'], 'database' => $test['database']]);
        }
        if ($installerAction === 'install') {
            if ($state['mode'] !== 'fresh') respond(['ok' => false, 'message' => 'This application is already installed or requires an upgrade. Refresh the installer.'], 409, 'INSTALLER_STATE_CHANGED');
            installer_rate_limit('installer_install_attempts', 5, 300);
            $result = installer_perform_fresh_install($data);
            session_regenerate_id(true);
            unset($_SESSION['installer_csrf'], $_SESSION['installer_db_attempts'], $_SESSION['installer_install_attempts']);
            respond(['ok' => true, 'message' => 'Cloud Core POS installed successfully.', 'result' => $result]);
        }
        if ($installerAction === 'upgrade') {
            if ($state['mode'] !== 'upgrade' || !($state['pdo'] instanceof PDO)) respond(['ok' => false, 'message' => 'No database upgrade is pending.'], 409, 'NO_UPGRADE_PENDING');
            installer_rate_limit('installer_upgrade_attempts', 5, 900);
            $result = installer_perform_upgrade($state['pdo'], (string) ($data['phone'] ?? ''), (string) ($data['password'] ?? ''));
            session_regenerate_id(true);
            unset($_SESSION['installer_csrf'], $_SESSION['installer_upgrade_attempts']);
            respond(['ok' => true, 'message' => 'Database upgrade completed successfully.', 'result' => $result]);
        }
        respond(['ok' => false, 'message' => 'Unknown installer action.'], 404, 'UNKNOWN_INSTALLER_ACTION');
    } catch (MalformedJsonException $error) {
        respond(['ok' => false, 'message' => 'Installer request contained malformed JSON.'], 400, 'MALFORMED_JSON');
    } catch (InvalidArgumentException $error) {
        respond(['ok' => false, 'message' => $error->getMessage()], 422, 'INSTALLER_VALIDATION_ERROR');
    } catch (PDOException $error) {
        app_log('error', 'Installer database request failed', pdo_exception_log_context($error, $installerAction));
        respond(['ok' => false, 'message' => 'The database connection or setup could not be completed. Check the database details and permissions.', 'error_detail' => APP_ENV === 'local' ? (string) (($error->errorInfo[2] ?? '') ?: $error->getMessage()) : null], 500, 'INSTALLER_DATABASE_ERROR');
    } catch (Throwable $error) {
        app_log('error', 'Installer request failed', ['action' => $installerAction, 'exception' => get_class($error), 'message' => $error->getMessage()]);
        respond(['ok' => false, 'message' => APP_ENV === 'local' ? $error->getMessage() : 'Installation could not be completed. Check the requirements and try again.'], 500, 'INSTALLER_ERROR');
    }
}

$checks = installer_environment_checks();
$ready = !in_array(false, array_column($checks, 'ok'), true);
$mode = (string) $state['mode'];
if ($mode === 'installed') {
    set_http_status(403);
}
$pending = array_values((array) ($state['pending'] ?? []));
$csrf = (string) $_SESSION['installer_csrf'];
$nonce = security_nonce();
$defaultDbName = PHP_OS_FAMILY === 'Windows' ? 'cloudcorepos_local' : 'cloudcorepos';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow,noarchive">
<title><?= $mode === 'upgrade' ? 'Upgrade' : ($mode === 'installed' ? 'Installer Locked' : 'Install') ?> | Cloud Core POS</title>
<style nonce="<?= htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') ?>">
:root{font-family:Inter,system-ui,-apple-system,Segoe UI,sans-serif;color:#17211c;background:#f3f7f5;line-height:1.5}*{box-sizing:border-box}body{margin:0}.wrap{max-width:1040px;margin:0 auto;padding:36px 20px 60px}.brand{display:flex;align-items:center;gap:12px;margin-bottom:24px}.mark{display:grid;place-items:center;width:44px;height:44px;border-radius:13px;background:#00b86b;color:#fff;font-weight:800}.card{background:#fff;border:1px solid #dde7e1;border-radius:20px;box-shadow:0 12px 34px rgba(15,55,35,.08);padding:28px;margin-bottom:18px}.hero h1{margin:.2rem 0;font-size:2rem}.muted{color:#66756d}.steps{display:flex;gap:8px;flex-wrap:wrap;margin:18px 0}.step-pill{padding:8px 11px;border-radius:999px;background:#edf3f0;color:#64736b;font-size:13px}.step-pill.active{background:#dff8ec;color:#007e49;font-weight:700}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.field{display:flex;flex-direction:column;gap:6px}.field.full{grid-column:1/-1}label{font-weight:650;font-size:14px}input,select{width:100%;border:1px solid #cfdad4;border-radius:11px;padding:11px 12px;font:inherit;background:#fff}input:focus,select:focus{outline:2px solid #aee8cf;border-color:#00a861}.checks{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.check{padding:12px;border-radius:12px;background:#f7faf8;border:1px solid #e5ece8}.check.ok strong{color:#008b51}.check.bad{background:#fff5f5;border-color:#ffd4d4}.check.bad strong{color:#b42318}.actions{display:flex;gap:10px;justify-content:space-between;margin-top:22px}.button{border:0;border-radius:11px;padding:11px 17px;font:inherit;font-weight:700;cursor:pointer}.primary{background:#00a861;color:#fff}.secondary{background:#eaf1ed;color:#22332a}.button:disabled{opacity:.5;cursor:not-allowed}.wizard-panel{display:none}.wizard-panel.active{display:block}.notice{padding:13px 15px;border-radius:12px;background:#eef8f3;margin:12px 0}.notice.error{background:#fff0f0;color:#8d1a1a}.success{background:#ecfff6;border:1px solid #b7f0d3;padding:20px;border-radius:16px}.password-meter{height:7px;border-radius:99px;background:#e6ece8;overflow:hidden}.password-meter span{display:block;height:100%;width:0;background:#00a861;transition:.2s}.lock{text-align:center;padding:40px 20px}.tag{display:inline-block;padding:5px 9px;border-radius:999px;background:#e8f7ef;color:#087a49;font-size:12px;font-weight:700}.checkbox{display:flex;align-items:flex-start;gap:10px;padding:12px;border:1px solid #dce6e0;border-radius:12px}.checkbox input{width:auto;margin-top:4px}.summary{display:grid;grid-template-columns:1fr 1fr;gap:10px}.summary div{padding:12px;background:#f7faf8;border-radius:11px}.request{font:12px ui-monospace,monospace;color:#748078}@media(max-width:720px){.grid,.checks,.summary{grid-template-columns:1fr}.field.full{grid-column:auto}.card{padding:20px}.hero h1{font-size:1.6rem}}
</style>
</head>
<body>
<div class="wrap">
<div class="brand"><span class="mark">C</span><div><strong>Cloud Core POS</strong><div class="muted">Easy setup & secure installation</div></div></div>
<?php if ($mode === 'installed'): ?>
<section class="card lock"><span class="tag">INSTALLED</span><h1>Installer is locked</h1><p class="muted">Cloud Core POS is already installed and no database upgrade is pending.</p><p><a class="button primary" href="index.php" style="display:inline-block;text-decoration:none">Go to Login</a></p></section>
<?php elseif ($mode === 'upgrade'): ?>
<section class="card hero"><span class="tag">DATABASE UPGRADE</span><h1>Update Cloud Core POS</h1><p class="muted">The source code is newer than the database. Sign in as an Administrator to apply the pending safe migrations.</p></section>
<section class="card"><h2>Pending migrations</h2><ul><?php foreach ($pending as $migration): ?><li><code><?= htmlspecialchars($migration, ENT_QUOTES, 'UTF-8') ?></code></li><?php endforeach; ?></ul>
<div class="grid"><div class="field"><label>Administrator mobile/login<input id="upgrade-phone" autocomplete="username"></label></div><div class="field"><label>Administrator password<input id="upgrade-password" type="password" autocomplete="current-password"></label></div></div>
<div id="upgrade-message" class="notice" hidden></div><div class="actions"><a href="index.php" class="button secondary" style="text-decoration:none">Back</a><button id="upgrade-button" class="button primary">Upgrade Database</button></div></section>
<?php else: ?>
<section class="card hero"><span class="tag">NEW INSTALLATION</span><h1>Install Cloud Core POS</h1><p class="muted">No command line or environment-file editing required. The installer will check the server, configure the database, generate private keys and lock itself when finished.</p></section>
<div class="steps"><span class="step-pill active" data-pill="0">1 Environment</span><span class="step-pill" data-pill="1">2 Database</span><span class="step-pill" data-pill="2">3 Administrator</span><span class="step-pill" data-pill="3">4 Business</span><span class="step-pill" data-pill="4">5 Install</span></div>
<section class="card wizard-panel active" data-step="0"><h2>Environment check</h2><p class="muted">Required server components are detected automatically.</p><div class="checks"><?php foreach ($checks as $check): ?><div class="check <?= $check['ok'] ? 'ok' : 'bad' ?>"><strong><?= $check['ok'] ? 'Ready' : 'Required' ?></strong><div><?= htmlspecialchars($check['label'], ENT_QUOTES, 'UTF-8') ?></div><small class="muted"><?= htmlspecialchars((string) $check['detail'], ENT_QUOTES, 'UTF-8') ?></small></div><?php endforeach; ?></div><?php if (!$ready): ?><div class="notice error">Required server checks must be fixed before installation can continue.</div><?php endif; ?><div class="actions"><span></span><button class="button primary next" <?= $ready ? '' : 'disabled' ?>>Continue</button></div></section>
<section class="card wizard-panel" data-step="1"><h2>Database</h2><p class="muted">Enter the database details supplied by your hosting provider. Cloud Core POS never displays the password again.</p><div class="grid"><div class="field"><label>Database host<input id="db-host" value="127.0.0.1" autocomplete="off"></label></div><div class="field"><label>Port<input id="db-port" type="number" value="3306" min="1" max="65535"></label></div><div class="field"><label>Database name<input id="db-name" value="<?= htmlspecialchars($defaultDbName, ENT_QUOTES, 'UTF-8') ?>"></label></div><div class="field"><label>Database username<input id="db-user" value="<?= PHP_OS_FAMILY === 'Windows' ? 'root' : '' ?>" autocomplete="username"></label></div><div class="field full"><label>Database password<input id="db-password" type="password" autocomplete="new-password"></label></div><label class="checkbox full"><input id="db-create" type="checkbox"><span><strong>Create the database if permitted</strong><br><small class="muted">Enable this for local/XAMPP or hosting accounts where the DB user has CREATE DATABASE permission. Leave it off when cPanel already created the database.</small></span></label></div><div id="db-message" class="notice" hidden></div><div class="actions"><button class="button secondary back">Back</button><div><button id="db-test" class="button secondary">Test Connection</button> <button class="button primary next">Continue</button></div></div></section>
<section class="card wizard-panel" data-step="2"><h2>Administrator</h2><div class="grid"><div class="field"><label>Administrator name<input id="admin-name" value="Administrator" autocomplete="name"></label></div><div class="field"><label>Mobile / login<input id="admin-phone" autocomplete="username"></label></div><div class="field"><label>Password<input id="admin-password" type="password" autocomplete="new-password"></label><div class="password-meter"><span id="password-strength"></span></div><small class="muted">12+ characters with uppercase, lowercase, number and symbol.</small></div><div class="field"><label>Confirm password<input id="admin-confirm" type="password" autocomplete="new-password"></label></div></div><div class="actions"><button class="button secondary back">Back</button><button class="button primary next">Continue</button></div></section>
<section class="card wizard-panel" data-step="3"><h2>Business setup</h2><div class="grid"><div class="field"><label>Business name<input id="business-name" value="Cloud Core POS"></label></div><div class="field"><label>Business phone<input id="business-phone"></label></div><div class="field"><label>Email<input id="business-email" type="email"></label></div><div class="field"><label>Currency<input id="business-currency" value="BDT" maxlength="10"></label></div><div class="field full"><label>Address<input id="business-address"></label></div><div class="field"><label>Timezone<select id="business-timezone"><option>Asia/Dhaka</option><option>UTC</option><option>Asia/Kolkata</option><option>Asia/Dubai</option><option>Europe/London</option><option>America/New_York</option></select></label></div><label class="checkbox"><input id="demo-data" type="checkbox"><span><strong>Install demo data</strong><br><small class="muted">Adds clearly named demo customer, supplier and product records for quick exploration.</small></span></label></div><div class="actions"><button class="button secondary back">Back</button><button class="button primary next">Review</button></div></section>
<section class="card wizard-panel" data-step="4"><h2>Ready to install</h2><p class="muted">Cloud Core POS will configure the environment, schema, migrations, Administrator and optional demo data automatically.</p><div class="summary"><div><strong>Database</strong><br><span id="summary-db"></span></div><div><strong>Administrator</strong><br><span id="summary-admin"></span></div><div><strong>Business</strong><br><span id="summary-business"></span></div><div><strong>Demo data</strong><br><span id="summary-demo"></span></div></div><div id="install-message" class="notice" hidden></div><div id="install-success" class="success" hidden></div><div class="actions"><button class="button secondary back">Back</button><button id="install-button" class="button primary">Install Cloud Core POS</button></div></section>
<?php endif; ?>
<p class="request">Request ID: <?= htmlspecialchars(request_id(), ENT_QUOTES, 'UTF-8') ?></p>
</div>
<script nonce="<?= htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') ?>">
const csrf=<?= html_script_json_encode($csrf) ?>;
async function installerApi(action,body){const r=await fetch(`install.php?installer_action=${encodeURIComponent(action)}`,{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-Token':csrf},body:JSON.stringify(body)});let x={};try{x=await r.json()}catch{}if(!r.ok||x.ok===false){const e=new Error(x.message||'Installer request failed.');e.requestId=x.request_id||r.headers.get('X-Request-ID')||'';throw e}return x}
function value(id){return document.getElementById(id)?.value||''}function checked(id){return !!document.getElementById(id)?.checked}function showMessage(id,message,error=false){const n=document.getElementById(id);if(!n)return;n.hidden=false;n.className='notice'+(error?' error':'');n.textContent=message}
<?php if ($mode === 'upgrade'): ?>
document.getElementById('upgrade-button')?.addEventListener('click',async()=>{const b=document.getElementById('upgrade-button');b.disabled=true;try{const r=await installerApi('upgrade',{phone:value('upgrade-phone'),password:value('upgrade-password')});showMessage('upgrade-message',r.message);setTimeout(()=>location.href='index.php',900)}catch(e){showMessage('upgrade-message',e.message+(e.requestId?` Request: ${e.requestId}`:''),true);b.disabled=false}});
<?php elseif ($mode === 'fresh'): ?>
let step=0;const panels=[...document.querySelectorAll('.wizard-panel')],pills=[...document.querySelectorAll('.step-pill')];function go(n){step=Math.max(0,Math.min(panels.length-1,n));panels.forEach((p,i)=>p.classList.toggle('active',i===step));pills.forEach((p,i)=>p.classList.toggle('active',i===step));if(step===4){document.getElementById('summary-db').textContent=`${value('db-name')} @ ${value('db-host')}:${value('db-port')}`;document.getElementById('summary-admin').textContent=`${value('admin-name')} (${value('admin-phone')})`;document.getElementById('summary-business').textContent=value('business-name')||'Cloud Core POS';document.getElementById('summary-demo').textContent=checked('demo-data')?'Yes':'No'}}document.querySelectorAll('.next').forEach(b=>b.addEventListener('click',()=>go(step+1)));document.querySelectorAll('.back').forEach(b=>b.addEventListener('click',()=>go(step-1)));
function dbPayload(){return{host:value('db-host'),port:value('db-port'),name:value('db-name'),user:value('db-user'),password:value('db-password')}}
document.getElementById('db-test')?.addEventListener('click',async()=>{const b=document.getElementById('db-test');b.disabled=true;try{const r=await installerApi('test_database',{database:dbPayload(),create_database:checked('db-create')});showMessage('db-message',`${r.message} Server: ${r.server_version}`)}catch(e){showMessage('db-message',e.message+(e.requestId?` Request: ${e.requestId}`:''),true)}finally{b.disabled=false}});
document.getElementById('admin-password')?.addEventListener('input',e=>{const p=e.target.value;let score=[p.length>=12,/[a-z]/.test(p),/[A-Z]/.test(p),/\d/.test(p),/[^A-Za-z0-9]/.test(p)].filter(Boolean).length;document.getElementById('password-strength').style.width=`${score*20}%`});
document.getElementById('install-button')?.addEventListener('click',async()=>{const b=document.getElementById('install-button');if(value('admin-password')!==value('admin-confirm')){showMessage('install-message','Administrator passwords do not match.',true);return}b.disabled=true;b.textContent='Installing…';try{const r=await installerApi('install',{database:dbPayload(),create_database:checked('db-create'),admin:{name:value('admin-name'),phone:value('admin-phone'),password:value('admin-password'),password_confirm:value('admin-confirm')},business:{name:value('business-name'),phone:value('business-phone'),email:value('business-email'),address:value('business-address'),currency:value('business-currency'),timezone:value('business-timezone')},demo_data:checked('demo-data')});const s=document.getElementById('install-success');s.hidden=false;s.innerHTML=`<strong>Installation complete.</strong><p>Database and security configuration passed the post-install self-test.</p><p><a href="index.php">Go to Login</a></p>`;b.hidden=true;document.querySelectorAll('.back').forEach(x=>x.hidden=true)}catch(e){showMessage('install-message',e.message+(e.requestId?` Request: ${e.requestId}`:''),true);b.disabled=false;b.textContent='Install Cloud Core POS'}});
<?php endif; ?>
</script>
</body>
</html>
