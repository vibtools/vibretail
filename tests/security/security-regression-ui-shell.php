<?php
declare(strict_types=1);

$repoRoot = realpath(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..');
if ($repoRoot === false) {
    fwrite(STDERR, "[FAIL] Repository root could not be resolved.\n");
    exit(1);
}

$failed = 0;
$checks = 0;

function shell_report(bool $ok, string $name, string $detail = ''): void
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

function shell_read(string $path): string
{
    $content = @file_get_contents($path);
    return $content === false ? '' : $content;
}

$expectedPages = [
    'dashboard.php' => ['dashboard', 'Dashboard', 'Business performance, cash flow and recent activity.', 'ERP'],
    'customer.php' => ['customer', 'Customer Management', 'Add and manage every customer account.', 'Customer & Supplier'],
    'supplier.php' => ['supplier', 'Supplier Management', 'Add and manage every supplier account.', 'Customer & Supplier'],
    'product-new.php' => ['product-new', 'Add New Product', 'Set prices, stock, barcode and product identity.', 'Product'],
    'product-list.php' => ['product-list', 'Product List', 'Search, price and monitor your complete inventory.', 'Product'],
    'brand.php' => ['brand', 'Brand Setup', 'Create and organize product brands.', 'Product'],
    'category.php' => ['category', 'Category Setup', 'Create and organize product categories.', 'Product'],
    'subcategory.php' => ['subcategory', 'Sub Category Setup', 'Create and organize product subcategories.', 'Product'],
    'unit.php' => ['unit', 'Unit Setup', 'Create and organize product units.', 'Product'],
    'purchase-new.php' => ['purchase-new', 'Create Purchase', 'Purchase products and update stock automatically.', 'Purchase'],
    'purchase-list.php' => ['purchase-list', 'Purchase List', 'Review purchase totals, payments and dues.', 'Purchase'],
    'purchase-return.php' => ['purchase-return', 'Purchase Return List', 'Review supplier returns and stock deductions.', 'Purchase'],
    'sale-new.php' => ['sale-new', 'Create Sale', 'Sell products and update stock automatically.', 'Sale'],
    'sale-vat.php' => ['sale-vat', 'Sale With VAT', 'Create a sale with VAT calculation.', 'Sale'],
    'sale-list.php' => ['sale-list', 'Sale List', 'Review sale totals, payments and dues.', 'Sale'],
    'sale-return.php' => ['sale-return', 'Sale Return List', 'Review customer refunds and restored stock.', 'Sale'],
    'serial-list.php' => ['serial-list', 'Serial List', 'Search serial numbers and monitor warranty status.', 'Warranty'],
    'rma.php' => ['rma', 'RMA', 'Track warranty products from reception to delivery.', 'Warranty'],
    'service-new.php' => ['service-new', 'Create Service', 'Register a device and track repair progress.', 'Service'],
    'service-list.php' => ['service-list', 'Service List', 'Monitor every service job and payment.', 'Service'],
    'service-report.php' => ['service-report', 'Service Report', 'Review service earnings, refunds and dues.', 'Service'],
    'quotation-new.php' => ['quotation-new', 'Create Quotation', 'Build a customer proposal without changing stock.', 'Quotation'],
    'quotation-list.php' => ['quotation-list', 'Quotation List', 'Review proposals sent to customers.', 'Quotation'],
    'damage.php' => ['damage', 'Add Damage', 'Record damaged inventory and reduce stock.', 'Damage'],
    'damage-list.php' => ['damage-list', 'Damage List', 'Review every damaged inventory record.', 'Damage'],
    'expense.php' => ['expense', 'Expense Management', 'Record and review operating expenses.', 'Expense'],
    'expense-type.php' => ['expense-type', 'Expense Type Setup', 'Create and organize expense categories.', 'Expense'],
    'expense-report.php' => ['expense-report', 'Expense By Type', 'Review expenses grouped by category.', 'Expense'],
    'barcode.php' => ['barcode', 'Multi Barcode', 'Generate printable labels for multiple products.', 'Barcode'],
    'barcode-single.php' => ['barcode-single', 'Single Barcode', 'Generate printable labels for one product.', 'Barcode'],
    'bank.php' => ['bank', 'Bank Accounts', 'Manage cash, mobile banking and bank balances.', 'Bank Accounts'],
    'transfer.php' => ['transfer', 'Balance Transfer', 'Move money safely between business accounts.', 'Bank Accounts'],
    'cheque.php' => ['cheque', 'Cheque Management', 'Monitor pending, deposited, bounced and cleared cheques.', 'Bank Accounts'],
    'cheque-new.php' => ['cheque-new', 'Add Cheque', 'Register a received or payment cheque.', 'Bank Accounts'],
    'transactions.php' => ['transactions', 'Transactions', 'Review every account payment and movement.', 'Bank Accounts'],
    'investor.php' => ['investor', 'Investor List', 'Manage business investors and invested amounts.', 'Investment'],
    'emi-new.php' => ['emi-new', 'Create EMI', 'Create an installment plan for a customer.', 'EMI'],
    'emi-list.php' => ['emi-list', 'EMI', 'Manage installment plans and payments.', 'EMI'],
    'installment-report.php' => ['installment-report', 'Installment Report', 'Track due and paid installment schedules.', 'EMI'],
    'team.php' => ['team', 'Team', 'Create and manage business team members.', 'HRM'],
    'sr-list.php' => ['sr-list', 'SR List', 'Manage sales representatives.', 'HRM'],
    'attendance.php' => ['attendance', 'Attendance Time Schedule', 'Manage schedules and daily attendance.', 'HRM'],
    'role.php' => ['role', 'Role Management', 'Create roles and assign module permissions.', 'HRM'],
    'business-report.php' => ['business-report', 'Business Report', 'Review complete business performance.', 'Report'],
    'sales-report.php' => ['sales-report', 'Sale Report', 'Review sales, payments, dues and profit.', 'Report'],
    'top-customer.php' => ['top-customer', 'Top Customer List', 'Rank customers by sales and profit.', 'Report'],
    'customer-report.php' => ['customer-report', 'Customer Report', 'Review customer sales and balances.', 'Report'],
    'receivable-report.php' => ['receivable-report', 'Receivable Report', 'Review outstanding customer balances.', 'Report'],
    'payable-report.php' => ['payable-report', 'Payable Report', 'Review outstanding supplier balances.', 'Report'],
    'low-stock.php' => ['low-stock', 'Low Stock Product List', 'Review products that need restocking.', 'Report'],
    'alert-product.php' => ['alert-product', 'Alert Product List', 'Review products at or below alert quantity.', 'Report'],
    'sale-product-report.php' => ['sale-product-report', 'Sale Product Report', 'Review product-level sales and profit.', 'Report'],
    'account-payment-report.php' => ['account-payment-report', 'Account Payment Report', 'Review account receipts and payments.', 'Report'],
    'full-expense-report.php' => ['full-expense-report', 'Expense Report', 'Review complete expense activity.', 'Report'],
    'transaction-report.php' => ['transaction-report', 'Transaction Report', 'Review account transaction history.', 'Report'],
    'daily-report.php' => ['daily-report', 'Daily Report', 'Review daily business movement.', 'Report'],
    'stock-report.php' => ['stock-report', 'Stock Report', 'Review detailed stock valuation.', 'Report'],
    'stock-list.php' => ['stock-list', 'Stock List', 'Review current product stock.', 'Report'],
    'admin.php' => ['admin', 'Admin', 'Manage system users and recent activity.', 'Administration'],
    'settings.php' => ['settings', 'Business Setting', 'Customize identity, VAT, invoices and POS behavior.', 'Settings'],
    'marketplace.php' => ['marketplace', 'Active Marketplace', 'Enable and manage marketplace features.', 'Marketplace'],
    'profile.php' => ['profile', 'Personal Information', 'Update your profile and preferred language.', 'Profile'],
    'payment-center.php' => ['payment-center', 'Account Payment', 'Receive customer dues and pay suppliers.', 'Payment'],
    'buy-sms.php' => ['buy-sms', 'Buy SMS', 'Purchase SMS credits for customer notifications.', 'SMS'],
];

shell_report(count($expectedPages) === 64, 'UI-02A wrapper inventory locked', 'count=' . count($expectedPages));

foreach ($expectedPages as $file => [$key, $title, $subtitle, $section]) {
    $path = $repoRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . $file;
    $source = shell_read($path);
    $required = [
        "declare(strict_types=1);",
        "\$pageKey = " . var_export($key, true) . ";",
        "\$pageTitle = " . var_export($title, true) . ";",
        "\$pageSubtitle = " . var_export($subtitle, true) . ";",
        "\$pageSection = " . var_export($section, true) . ";",
        "require __DIR__ . '/ui/app-shell.php';",
    ];
    $ok = $source !== '';
    foreach ($required as $needle) {
        $ok = $ok && str_contains($source, $needle);
    }
    $ok = $ok
        && !str_contains($source, "require_once __DIR__ . '/config.php';")
        && !str_contains($source, '$navigation = [')
        && !str_contains($source, '<aside id="sidebar"')
        && !str_contains($source, 'window.POS_CONFIG');

    $ok = $ok && !str_contains($source, '$shellShowDeveloperCredit');

    shell_report($ok, 'Reusable wrapper ' . $file);
}

$contextPath = $repoRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'ui' . DIRECTORY_SEPARATOR . 'app-context.php';
$navigationPath = $repoRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'ui' . DIRECTORY_SEPARATOR . 'app-navigation.php';
$shellPath = $repoRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'ui' . DIRECTORY_SEPARATOR . 'app-shell.php';

$context = shell_read($contextPath);
$contextNeedles = [
    "declare(strict_types=1);",
    "require_once dirname(__DIR__) . '/config.php';",
    "throw new RuntimeException('Page metadata is required.');",
    '$pdo = db();',
    "header('Location: install.php');",
    "\$_SESSION['csrf'] = bin2hex(random_bytes(24));",
    "auth_version FROM users",
    "\$_SESSION['auth_version']",
    "session_unset();",
    "\$_SERVER['PHP_SELF']",
    "header('Location: index.php?return=' . rawurlencode(\$returnPage));",
    "normalize_brand_settings(\$pdo->query('SELECT * FROM settings WHERE id = 1')->fetch() ?: [])",
    "\$businessName = (string) (\$settings['business_name'] ?? SOFTWARE_NAME);",
];
$contextOk = $context !== '';
foreach ($contextNeedles as $needle) {
    $contextOk = $contextOk && str_contains($context, $needle);
}
shell_report($contextOk, 'Shared authenticated context preserves session/auth/CSRF contract');

$navigation = is_file($navigationPath) ? require $navigationPath : null;
$flattened = [];
if (is_array($navigation)) {
    foreach ($navigation as $item) {
        if (($item['type'] ?? '') === 'link') {
            $flattened[] = (string) ($item['key'] ?? '');
            continue;
        }
        foreach (($item['items'] ?? []) as $child) {
            $flattened[] = (string) ($child[0] ?? '');
        }
    }
}
$expectedNavigation = ['dashboard', 'customer', 'supplier', 'product-new', 'product-list', 'brand', 'category', 'subcategory', 'unit', 'purchase-new', 'purchase-list', 'purchase-return', 'sale-new', 'sale-vat', 'sale-list', 'sale-return', 'serial-list', 'rma', 'service-new', 'service-list', 'service-report', 'quotation-new', 'quotation-list', 'damage', 'damage-list', 'expense', 'expense-type', 'expense-report', 'barcode', 'barcode-single', 'bank', 'transfer', 'cheque', 'transactions', 'investor', 'emi-new', 'emi-list', 'installment-report', 'team', 'sr-list', 'attendance', 'role', 'business-report', 'sales-report', 'top-customer', 'customer-report', 'receivable-report', 'payable-report', 'low-stock', 'alert-product', 'sale-product-report', 'account-payment-report', 'full-expense-report', 'transaction-report', 'daily-report', 'stock-report', 'stock-list', 'admin', 'settings', 'marketplace'];
shell_report($flattened === $expectedNavigation, 'Canonical navigation route order preserved', 'routes=' . count($flattened));

$shell = shell_read($shellPath);
$shellNeedles = [
    "require __DIR__ . '/app-context.php';",
    "require __DIR__ . '/app-navigation.php';",
    '<aside id="sidebar" class="sidebar">',
    'id="sidebar-close"',
    'id="sidebar-scrim"',
    'id="menu-toggle"',
    'id="quick-add"',
    'id="quick-menu"',
    'id="profile-button"',
    'id="profile-menu"',
    'id="logout-button"',
    '<main id="content" class="content" tabindex="-1">',
    'id="toast-root"',
    'id="modal-root"',
    'window.POS_CONFIG =',
    "'api' => 'api.php'",
    "'csrf' => \$_SESSION['csrf']",
    "'loggedIn' => true",
    "'businessName' => \$businessName",
    "'currency' => \$settings['currency'] ?? 'BDT'",
    "'user' => \$user",
    "'initialPage' => \$pageKey",
    "'multiPage' => true",
    'style.css?v=1.2.1',
    'app.js?v=1.5.1',
    'DEVELOPER_COMPANY_URL',
    'DEVELOPER_GITHUB_ORG_URL',
    'DEVELOPER_LOGO_URL',
    'DEVELOPER_WHATSAPP_URL',
    'DEVELOPER_WHATSAPP_NUMBER',
    'class="company-note"',
    '<a href="about.php" data-page="about">About</a>',
];
$shellOk = $shell !== '';
foreach ($shellNeedles as $needle) {
    $shellOk = $shellOk && str_contains($shell, $needle);
}
shell_report($shellOk, 'Shared shell preserves DOM/POS_CONFIG/attribution contract');
shell_report(
    !str_contains($shell, '$shellShowDeveloperCredit') && !str_contains($shell, '<?php if ($shellShowDeveloperCredit): ?>'),
    'Shared sidebar company/About footer is unconditional'
);

$noVisualCompaction = !str_contains($shell, '196px')
    && !str_contains($shell, '44px')
    && !str_contains($shell, '--vr-sidebar-target')
    && !str_contains($shell, '--vr-topbar-target');
shell_report($noVisualCompaction, 'Gate A contains no intentional shell compaction');

$htaccess = shell_read($repoRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . '.htaccess');
$nginx = shell_read($repoRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'aapanel-nginx.conf');
shell_report(
    str_contains($htaccess, 'storage/private|ui') && str_contains($htaccess, '- [F,L,NC]'),
    'Apache blocks internal ui source'
);
shell_report(
    str_contains($nginx, 'storage/private|ui') && str_contains($nginx, 'deny all;'),
    'Nginx blocks internal ui source'
);

$runtimeProbe = shell_read($repoRoot . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'runtime-security-check.php');
$runtimeUiPaths = ['/ui/app-context.php', '/ui/app-navigation.php', '/ui/app-shell.php'];
$runtimeOk = true;
foreach ($runtimeUiPaths as $path) {
    $runtimeOk = $runtimeOk && str_contains($runtimeProbe, $path);
}
shell_report($runtimeOk, 'Runtime security probe covers shared ui source');

$staticRunner = shell_read($repoRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'test' . DIRECTORY_SEPARATOR . 'run-static.cmd');
shell_report(
    str_contains($staticRunner, 'tests\\security\\security-regression-ui-shell.php'),
    'Static runner executes UI-02A shell regression'
);

$appJs = shell_read($repoRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'app.js');
$jsContractNeedles = [
    "$$('a[data-page]')",
    "$$('.nav-parent')",
    "$('#menu-toggle')",
    "$('#sidebar')",
    "$('#sidebar-scrim')",
    "$('#sidebar-close')",
    "$('#quick-add')",
    "$('#quick-menu')",
    "$('#profile-button')",
    "$('#profile-menu')",
    "$('#logout-button')",
    "config.initialPage",
    "config.multiPage",
];
$jsOk = $appJs !== '';
foreach ($jsContractNeedles as $needle) {
    $jsOk = $jsOk && str_contains($appJs, $needle);
}
shell_report($jsOk, 'Existing app.js shell DOM contract remains available');

$frameworkFree = !str_contains(strtolower($shell . $context . shell_read($navigationPath)), 'tailwind')
    && !str_contains(strtolower($shell . $context . shell_read($navigationPath)), 'bootstrap.min')
    && !str_contains(strtolower($shell . $context . shell_read($navigationPath)), 'cdnjs');
shell_report($frameworkFree, 'No UI framework/CDN introduced by reusable shell');

echo "UI-02A reusable shell regression: {$checks} checks, {$failed} failed." . PHP_EOL;
exit($failed ? 1 : 0);
