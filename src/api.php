<?php
declare(strict_types=1);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/config.php';
require __DIR__ . '/product-images.php';
require_once __DIR__ . '/migrations.php';

header('Cache-Control: no-store, no-cache, must-revalidate');
$action = clean_string($_GET['action'] ?? '', 60);
$writeActions = [
    'login', 'logout', 'contact_save', 'lookup_save', 'product_save', 'sale_save', 'purchase_save',
    'sale_return_save', 'purchase_return_save', 'serial_save', 'rma_save', 'rma_status', 'expense_save',
    'account_save', 'transfer_save', 'cheque_save', 'cheque_status', 'contact_payment_save', 'sms_purchase',
    'service_save', 'service_status', 'quotation_save', 'damage_save', 'investor_save', 'emi_save',
    'emi_payment', 'employee_save', 'attendance_save', 'attendance_schedule_save', 'role_save',
    'profile_save', 'password_change', 'marketplace_request', 'settings_save', 'backup', 'user_save',
    'service_credential_reveal',
];
if (in_array($action, $writeActions, true)) {
    require_http_method('POST');
}

function required(array $data, string $key, string $label): string
{
    $value = clean_string($data[$key] ?? '');
    if ($value === '') {
        throw new InvalidArgumentException($label . ' is required.');
    }
    return $value;
}

function date_value(mixed $value): string
{
    $date = clean_string($value, 10);
    if ($date === '') {
        return date('Y-m-d');
    }
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$parsed || ($errors !== false && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) || $parsed->format('Y-m-d') !== $date) {
        throw new InvalidArgumentException('Date must use YYYY-MM-DD and be a real calendar date.');
    }
    return $date;
}

function assert_unique_product_items(array $items, string $label): void
{
    $seen = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            throw new InvalidArgumentException($label . ' items are malformed.');
        }
        $productId = positive_id($item['product_id'] ?? null, $label . ' product ID');
        if (isset($seen[$productId])) {
            throw new InvalidArgumentException($label . ' cannot contain the same product more than once.');
        }
        $seen[$productId] = true;
    }
}

function image_value(mixed $value): string
{
    $image = trim((string) $value);
    if ($image === '') return '';
    if (strlen($image) > 4_200_000 || !preg_match('#^data:image/(?:png|jpe?g|webp|bmp);base64,[A-Za-z0-9+/=\r\n]+$#', $image)) {
        throw new InvalidArgumentException('Image must be PNG, JPG, WEBP or BMP and no larger than 3 MB.');
    }
    return $image;
}

function next_number(PDO $pdo, string $table, string $prefix): string
{
    $allowed = ['sales', 'purchases', 'services', 'quotations', 'sale_returns', 'purchase_returns', 'damages', 'transfers', 'rmas'];
    if (!in_array($table, $allowed, true)) {
        throw new InvalidArgumentException('Invalid document type.');
    }
    $stmt = $pdo->prepare('INSERT INTO document_sequences (document_type,sequence_no) VALUES (?,LAST_INSERT_ID(1)) ON DUPLICATE KEY UPDATE sequence_no=LAST_INSERT_ID(sequence_no+1)');
    $stmt->execute([$table]);
    $next = (int) $pdo->query('SELECT LAST_INSERT_ID()')->fetchColumn();
    return strtoupper($prefix) . '-' . date('ymd') . '-' . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
}

function period_range(string $period): array
{
    $today = new DateTimeImmutable('today');
    return match ($period) {
        'week' => [$today->modify('monday this week'), $today],
        'last_week' => [$today->modify('monday last week'), $today->modify('sunday last week')],
        'month' => [$today->modify('first day of this month'), $today],
        'last_month' => [$today->modify('first day of last month'), $today->modify('last day of last month')],
        'year' => [$today->setDate((int) $today->format('Y'), 1, 1), $today],
        'last_30' => [$today->modify('-29 days'), $today],
        default => [$today, $today],
    };
}

function rows(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function scalar(PDO $pdo, string $sql, array $params = []): float
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (float) $stmt->fetchColumn();
}

try {
    $pdo = db();
    $pendingMigrations = migration_pending_ids($pdo, DB_NAME);
    if ($pendingMigrations !== []) {
        respond(['ok' => false, 'message' => 'A database upgrade is required before Cloud Core POS can continue.', 'upgrade_url' => 'install'], 503, 'UPGRADE_REQUIRED');
    }

    if ($action === 'login') {
        require_csrf();
        $data = json_input();
        $phone = required($data, 'phone', 'Mobile number');
        $password = (string) ($data['password'] ?? '');
        $loginKey = hash('sha256', strtolower($phone) . '|' . client_ip());
        $pdo->exec("DELETE FROM login_attempts WHERE attempted_at < NOW() - INTERVAL 1 DAY");
        $attempt = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE login_key=? AND success=0 AND attempted_at >= NOW() - INTERVAL 15 MINUTE");
        $attempt->execute([$loginKey]);
        if ((int) $attempt->fetchColumn() >= 5) {
            header('Retry-After: 900');
            respond(['ok' => false, 'message' => 'Too many sign-in attempts. Try again in 15 minutes.'], 429);
        }
        $stmt = $pdo->prepare('SELECT id, name, phone, password, role, must_change_password, auth_version FROM users WHERE phone = ? AND status = 1 LIMIT 1');
        $stmt->execute([$phone]);
        $user = $stmt->fetch();
        $verificationHash = $user['password'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
        if (!$user || !password_verify($password, $verificationHash)) {
            $pdo->prepare('INSERT INTO login_attempts (login_key,success) VALUES (?,0)')->execute([$loginKey]);
            respond(['ok' => false, 'message' => 'Mobile number or password is incorrect.'], 422);
        }
        if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
            $pdo->prepare('UPDATE users SET password=? WHERE id=?')->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
        }
        $pdo->prepare('DELETE FROM login_attempts WHERE login_key=?')->execute([$loginKey]);
        $pdo->prepare('UPDATE users SET last_login_at=NOW() WHERE id=?')->execute([$user['id']]);
        session_regenerate_id(true);
        $_SESSION['last_regenerated'] = time();
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['must_change_password'] = (bool) $user['must_change_password'];
        $_SESSION['auth_version'] = (int) $user['auth_version'];
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
        record_activity($pdo, (int) $user['id'], 'Signed in', 'Local POS login');
        unset($user['password']);
        respond(['ok' => true, 'message' => 'Welcome back.', 'user' => $user, 'password_change_required' => (bool) $user['must_change_password']]);
    }

    $userId = require_auth();

    if (!empty($_SESSION['must_change_password']) && !in_array($action, ['bootstrap', 'profile', 'password_change', 'logout'], true)) {
        respond(['ok' => false, 'message' => 'Change your temporary password before continuing.'], 428, 'PASSWORD_CHANGE_REQUIRED');
    }

    $userPermissions = require_action_permission($pdo, $userId, $action);

    if ($action === 'logout') {
        require_csrf();
        record_activity($pdo, $userId, 'Signed out');
        session_unset();
        session_destroy();
        respond(['ok' => true]);
    }

    if ($action === 'bootstrap') {
        $settings = $pdo->query('SELECT * FROM settings WHERE id = 1')->fetch();
        $can = static fn(string $permission): bool => user_has_permission($userPermissions, $permission);
        $needsCustomers = $can('customer') || $can('sale') || $can('service') || $can('quotation') || $can('emi') || $can('report');
        $needsSuppliers = $can('supplier') || $can('purchase') || $can('report');
        $needsProducts = $can('product') || $can('sale') || $can('purchase') || $can('warranty') || $can('quotation') || $can('damage') || $can('report');
        $needsAccounts = $can('bank') || $can('bank.accounts_manage') || $can('bank.transfer') || $can('sale') || $can('purchase') || $can('expense') || $can('service') || $can('emi');
        $needsEmployees = $can('hrm') || $can('service');
        $needsRoles = $can('hrm') || $can('admin.users') || $can('admin.roles');
        $needsSales = $can('sale') || $can('emi') || $can('report');

        $customers = $needsCustomers ? rows($pdo, "SELECT id, name, mobile, address FROM contacts WHERE type IN ('customer','both') ORDER BY name") : [];
        $suppliers = $needsSuppliers ? rows($pdo, "SELECT id, name, mobile, address FROM contacts WHERE type IN ('supplier','both') ORDER BY name") : [];
        $products = $needsProducts ? rows($pdo, "
            SELECT p.id,p.name,p.sku,p.barcode,p.stock,p.cost_price,p.sale_price,p.dealer_price,p.warranty_months,p.manage_stock,
                   COALESCE(b.name, '') AS brand_name,COALESCE(c.name, '') AS category_name,COALESCE(u.short_name, 'pcs') AS unit_name
            FROM products p
            LEFT JOIN brands b ON b.id = p.brand_id
            LEFT JOIN categories c ON c.id = p.category_id
            LEFT JOIN units u ON u.id = p.unit_id
            ORDER BY p.name
        ") : [];
        $accounts = $needsAccounts
            ? rows($pdo, $can('bank') || $can('bank.accounts_manage') || $can('bank.transfer')
                ? 'SELECT id, name, account_no, bank_name, balance FROM bank_accounts ORDER BY id'
                : 'SELECT id, name, account_no, bank_name FROM bank_accounts ORDER BY id')
            : [];
        $employees = $needsEmployees ? rows($pdo, "SELECT id, name, designation FROM employees WHERE status = 'active' ORDER BY name") : [];
        $roles = $needsRoles ? rows($pdo, 'SELECT id, name FROM roles ORDER BY name') : [];
        $sales = $needsSales ? rows($pdo, "SELECT s.id,s.invoice_no,s.customer_id,s.total,s.paid,s.due,COALESCE(c.name,'Walk-in Customer') customer FROM sales s LEFT JOIN contacts c ON c.id=s.customer_id WHERE s.status='completed' ORDER BY s.id DESC LIMIT 500") : [];
        respond([
            'ok' => true,
            'settings' => $settings,
            'customers' => $customers,
            'suppliers' => $suppliers,
            'products' => $products,
            'accounts' => $accounts,
            'employees' => $employees,
            'roles' => $roles,
            'sales' => $sales,
            'permissions' => $userPermissions,
        ]);
    }

    if ($action === 'dashboard') {
        [$from, $to] = period_range(clean_string($_GET['period'] ?? 'today', 20));
        $range = [$from->format('Y-m-d'), $to->format('Y-m-d')];
        $sales = rows($pdo, "SELECT COUNT(*) count, COALESCE(SUM(total),0) total, COALESCE(SUM(paid),0) paid, COALESCE(SUM(due),0) due FROM sales WHERE status='completed' AND sale_date BETWEEN ? AND ?", $range)[0];
        $purchases = rows($pdo, "SELECT COUNT(*) count, COALESCE(SUM(total),0) total, COALESCE(SUM(paid),0) paid, COALESCE(SUM(due),0) due FROM purchases WHERE status='completed' AND purchase_date BETWEEN ? AND ?", $range)[0];
        $expenses = scalar($pdo, 'SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date BETWEEN ? AND ?', $range);
        $servicePaid = scalar($pdo, 'SELECT COALESCE(SUM(paid),0) FROM services WHERE received_date BETWEEN ? AND ?', $range);
        $accountBalance = scalar($pdo, 'SELECT COALESCE(SUM(balance),0) FROM bank_accounts');
        $cashIn = scalar($pdo, "SELECT COALESCE(SUM(amount),0) FROM transactions WHERE type IN ('in','transfer_in') AND transaction_date BETWEEN ? AND ?", $range);
        $cashOut = scalar($pdo, "SELECT COALESCE(SUM(amount),0) FROM transactions WHERE type IN ('out','transfer_out') AND transaction_date BETWEEN ? AND ?", $range);
        $lowStock = (int) scalar($pdo, 'SELECT COUNT(*) FROM products WHERE manage_stock = 1 AND stock <= alert_qty');

        $trend = rows($pdo, "
            SELECT d.day,
                COALESCE((SELECT SUM(total) FROM sales s WHERE s.sale_date=d.day AND s.status='completed'),0) sales,
                COALESCE((SELECT SUM(total) FROM purchases p WHERE p.purchase_date=d.day AND p.status='completed'),0) purchases,
                COALESCE((SELECT SUM(amount) FROM expenses e WHERE e.expense_date=d.day),0) expenses
            FROM (
                SELECT CURDATE() - INTERVAL seq DAY day FROM (
                    SELECT 0 seq UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9
                    UNION ALL SELECT 10 UNION ALL SELECT 11 UNION ALL SELECT 12 UNION ALL SELECT 13 UNION ALL SELECT 14 UNION ALL SELECT 15 UNION ALL SELECT 16 UNION ALL SELECT 17 UNION ALL SELECT 18 UNION ALL SELECT 19
                    UNION ALL SELECT 20 UNION ALL SELECT 21 UNION ALL SELECT 22 UNION ALL SELECT 23 UNION ALL SELECT 24 UNION ALL SELECT 25 UNION ALL SELECT 26 UNION ALL SELECT 27 UNION ALL SELECT 28 UNION ALL SELECT 29
                ) numbers
            ) d ORDER BY d.day
        ");
        $latest = rows($pdo, "SELECT s.id, s.invoice_no, s.sale_date, s.total, s.paid, s.due, COALESCE(c.name,'Walk-in Customer') customer FROM sales s LEFT JOIN contacts c ON c.id=s.customer_id ORDER BY s.id DESC LIMIT 6");
        respond(['ok' => true, 'period' => ['from' => $range[0], 'to' => $range[1]], 'sales' => $sales, 'purchases' => $purchases, 'expenses' => $expenses, 'service_paid' => $servicePaid, 'account_balance' => $accountBalance, 'cash_in' => $cashIn, 'cash_out' => $cashOut, 'low_stock' => $lowStock, 'trend' => $trend, 'latest' => $latest]);
    }

if ($action === 'contacts') {
    $type = clean_string($_GET['type'] ?? 'customer', 20);

    if (!in_array($type, ['customer', 'supplier'], true)) {
        throw new InvalidArgumentException('Invalid contact type.');
    }

    if ($type === 'supplier') {
        $where = "c.type IN ('supplier', 'both')";

        $balanceJoin = "
            LEFT JOIN (
                SELECT
                    supplier_id AS contact_id,
                    COALESCE(SUM(due), 0) AS balance
                FROM purchases
                WHERE status = 'completed'
                  AND supplier_id IS NOT NULL
                GROUP BY supplier_id
            ) b ON b.contact_id = c.id
        ";
    } else {
        $where = "c.type IN ('customer', 'both')";

        $balanceJoin = "
            LEFT JOIN (
                SELECT
                    customer_id AS contact_id,
                    COALESCE(SUM(due), 0) AS balance
                FROM sales
                WHERE status = 'completed'
                  AND customer_id IS NOT NULL
                GROUP BY customer_id
            ) b ON b.contact_id = c.id
        ";
    }

    $data = rows($pdo, "
        SELECT
            c.*,
            (
                COALESCE(c.opening_balance, 0)
                + COALESCE(b.balance, 0)
            ) AS balance,
            COALESCE(c.advance_balance, 0) AS advance_balance
        FROM contacts c
        {$balanceJoin}
        WHERE {$where}
        ORDER BY c.id DESC
    ");

    respond([
        'ok' => true,
        'data' => $data
    ]);
}

    if ($action === 'contact_save') {
    require_csrf();

    $data = json_input();

    $id = positive_id($data['id'] ?? null, 'Contact ID', true) ?? 0;

    $type = in_array(
        $data['type'] ?? '',
        ['customer', 'supplier', 'both'],
        true
    ) ? $data['type'] : 'customer';

    if ($type === 'both' && !user_has_permission($userPermissions, 'supplier')) {
        respond(['ok' => false, 'message' => 'Supplier permission is also required for a combined contact.'], 403, 'FORBIDDEN');
    }

    $values = [
        required($data, 'name', 'Name'),
        required($data, 'mobile', 'Mobile'),
        clean_string($data['email'] ?? '', 120),
        clean_string($data['address'] ?? ''),
        clean_string($data['contact_person'] ?? '', 120),
        strict_money($data['opening_balance'] ?? 0, 'Opening balance'),
        strict_money($data['advance_balance'] ?? 0, 'Advance balance'),
        $type
    ];


    if ($id > 0) {

        $stmt = $pdo->prepare(
            'UPDATE contacts 
             SET name=?,
                 mobile=?,
                 email=?,
                 address=?,
                 contact_person=?,
                 opening_balance=?,
                 advance_balance=?,
                 type=?
             WHERE id=?'
        );

        $values[] = $id;

        $stmt->execute($values);


    } else {

        $stmt = $pdo->prepare(
            'INSERT INTO contacts
            (
                name,
                mobile,
                email,
                address,
                contact_person,
                opening_balance,
                advance_balance,
                type
            )
            VALUES (?,?,?,?,?,?,?,?)'
        );

        $stmt->execute($values);

        $id = (int) $pdo->lastInsertId();
    }


    record_activity(
        $pdo,
        $userId,
        'Saved contact',
        $values[0]
    );


    respond([
        'ok' => true,
        'id' => $id,
        'message' => 'Contact saved successfully.'
    ]);
}

    if ($action === 'lookups') {
        $type = clean_string($_GET['type'] ?? 'brand', 20);
        $tables = ['brand' => 'brands', 'category' => 'categories', 'subcategory' => 'subcategories', 'unit' => 'units', 'expense_type' => 'expense_types'];
        if (!isset($tables[$type])) {
            throw new InvalidArgumentException('Invalid lookup type.');
        }
        if ($type === 'subcategory') {
            $data = rows($pdo, 'SELECT s.*, c.name category_name FROM subcategories s LEFT JOIN categories c ON c.id=s.category_id ORDER BY s.id DESC');
        } else {
            $data = rows($pdo, 'SELECT * FROM ' . $tables[$type] . ' ORDER BY id DESC');
        }
        respond(['ok' => true, 'data' => $data]);
    }

    if ($action === 'lookup_save') {
    require_csrf();

    $data = json_input();

    $type = clean_string($data['type'] ?? '', 20);
    $id = (int) ($data['id'] ?? 0);
    $operation = clean_string($data['operation'] ?? 'save', 20);

    $tables = [
        'brand' => 'brands',
        'category' => 'categories',
        'subcategory' => 'subcategories',
        'unit' => 'units',
        'expense_type' => 'expense_types',
    ];

    if (!isset($tables[$type])) {
        throw new InvalidArgumentException('Invalid lookup type.');
    }

    $table = $tables[$type];


    /*
    |--------------------------------------------------------------------------
    | DELETE
    |--------------------------------------------------------------------------
    */

    if ($operation === 'delete') {

        if ($id <= 0) {
            throw new InvalidArgumentException('Invalid record.');
        }

        $used = 0;

        if ($type === 'category') {

            $stmt = $pdo->prepare(
                'SELECT
                    (SELECT COUNT(*) FROM products WHERE category_id = ?) +
                    (SELECT COUNT(*) FROM subcategories WHERE category_id = ?)'
            );

            $stmt->execute([$id, $id]);

            $used = (int) $stmt->fetchColumn();

        } elseif ($type === 'brand') {

            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM products WHERE brand_id = ?'
            );

            $stmt->execute([$id]);

            $used = (int) $stmt->fetchColumn();

        } elseif ($type === 'subcategory') {

            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM products WHERE subcategory_id = ?'
            );

            $stmt->execute([$id]);

            $used = (int) $stmt->fetchColumn();

        } elseif ($type === 'unit') {

            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM products WHERE unit_id = ?'
            );

            $stmt->execute([$id]);

            $used = (int) $stmt->fetchColumn();

        } elseif ($type === 'expense_type') {

            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM expenses WHERE expense_type_id = ?'
            );

            $stmt->execute([$id]);

            $used = (int) $stmt->fetchColumn();
        }


        if ($used > 0) {
            throw new InvalidArgumentException(
                'This item is already in use and cannot be deleted.'
            );
        }


        $stmt = $pdo->prepare(
            "DELETE FROM {$table} WHERE id = ?"
        );

        $stmt->execute([$id]);


        record_activity(
            $pdo,
            $userId,
            'Deleted ' . $type,
            'ID: ' . $id
        );


        respond([
            'ok' => true,
            'message' => ucfirst(str_replace('_', ' ', $type)) . ' deleted successfully.'
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | ADD / EDIT
    |--------------------------------------------------------------------------
    */

    $name = required($data, 'name', 'Name');


    if ($type === 'unit') {

        $shortName = clean_string(
            $data['short_name'] ?? 'pcs',
            20
        );


        if ($id > 0) {

            $stmt = $pdo->prepare(
                'UPDATE units
                 SET name = ?, short_name = ?
                 WHERE id = ?'
            );

            $stmt->execute([
                $name,
                $shortName,
                $id
            ]);

        } else {

            $stmt = $pdo->prepare(
                'INSERT INTO units (name, short_name)
                 VALUES (?, ?)'
            );

            $stmt->execute([
                $name,
                $shortName
            ]);

            $id = (int) $pdo->lastInsertId();
        }


    } elseif ($type === 'subcategory') {

        $categoryId =
            (int) ($data['category_id'] ?? 0) ?: null;


        if ($id > 0) {

            $stmt = $pdo->prepare(
                'UPDATE subcategories
                 SET category_id = ?, name = ?
                 WHERE id = ?'
            );

            $stmt->execute([
                $categoryId,
                $name,
                $id
            ]);

        } else {

            $stmt = $pdo->prepare(
                'INSERT INTO subcategories
                 (category_id, name)
                 VALUES (?, ?)'
            );

            $stmt->execute([
                $categoryId,
                $name
            ]);

            $id = (int) $pdo->lastInsertId();
        }


    } else {

        if ($id > 0) {

            $stmt = $pdo->prepare(
                "UPDATE {$table}
                 SET name = ?
                 WHERE id = ?"
            );

            $stmt->execute([
                $name,
                $id
            ]);

        } else {

            $stmt = $pdo->prepare(
                "INSERT INTO {$table} (name)
                 VALUES (?)"
            );

            $stmt->execute([$name]);

            $id = (int) $pdo->lastInsertId();
        }
    }


    record_activity(
        $pdo,
        $userId,
        $id > 0 ? 'Saved ' . $type : 'Added ' . $type,
        $name
    );


    respond([
        'ok' => true,
        'id' => $id,
        'message' =>
            ucfirst(str_replace('_', ' ', $type))
            . ' saved successfully.'
    ]);
}

    if ($action === 'products') {
        $data = rows($pdo, "SELECT p.*, b.name brand_name, c.name category_name, s.name subcategory_name, u.short_name unit_name FROM products p LEFT JOIN brands b ON b.id=p.brand_id LEFT JOIN categories c ON c.id=p.category_id LEFT JOIN subcategories s ON s.id=p.subcategory_id LEFT JOIN units u ON u.id=p.unit_id ORDER BY p.id DESC");
        respond(['ok' => true, 'data' => $data]);
    }

    if ($action === 'product_form_data') {
        respond(['ok' => true, 'brands' => rows($pdo, 'SELECT * FROM brands ORDER BY name'), 'categories' => rows($pdo, 'SELECT * FROM categories ORDER BY name'), 'subcategories' => rows($pdo, 'SELECT * FROM subcategories ORDER BY name'), 'units' => rows($pdo, 'SELECT * FROM units ORDER BY name')]);
    }

    if ($action === 'product_save') {
        require_csrf();
        $data = json_input();
        $id = (int) ($data['id'] ?? 0);
        $name = required($data, 'name', 'Product name');
        $barcode = clean_string($data['barcode'] ?? '', 100);
        if ($barcode === '') {
            $barcode = 'P' . date('ymdHis') . random_int(10, 99);
        }
        $incomingImage = trim((string) ($data['image_data'] ?? ''));
        $existingImage = '';
        if ($id > 0) {
            $existingImageStatement = $pdo->prepare('SELECT image_data FROM products WHERE id=?');
            $existingImageStatement->execute([$id]);
            $existingImage = (string) ($existingImageStatement->fetchColumn() ?: '');
        }
        $newImage = $incomingImage !== '' ? store_product_image($incomingImage) : '';
        $imageData = $newImage !== '' ? $newImage : $existingImage;
        $values = [$name, (int) ($data['brand_id'] ?? 0) ?: null, (int) ($data['category_id'] ?? 0) ?: null, (int) ($data['subcategory_id'] ?? 0) ?: null, (int) ($data['unit_id'] ?? 0) ?: null, clean_string($data['sku'] ?? '', 80), $barcode, money($data['stock'] ?? 0), money($data['cost_price'] ?? 0), money($data['sale_price'] ?? 0), money($data['dealer_price'] ?? 0), money($data['alert_qty'] ?? 5), max(0, (int) ($data['warranty_months'] ?? 0)), !empty($data['manage_stock']) ? 1 : 0, $imageData ?: null];
        try {
            if ($id > 0) {
                $stmt = $pdo->prepare('UPDATE products SET name=?,brand_id=?,category_id=?,subcategory_id=?,unit_id=?,sku=?,barcode=?,stock=?,cost_price=?,sale_price=?,dealer_price=?,alert_qty=?,warranty_months=?,manage_stock=?,image_data=? WHERE id=?');
                $values[] = $id;
                $stmt->execute($values);
            } else {
                $stmt = $pdo->prepare('INSERT INTO products (name,brand_id,category_id,subcategory_id,unit_id,sku,barcode,stock,cost_price,sale_price,dealer_price,alert_qty,warranty_months,manage_stock,image_data) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
                $stmt->execute($values);
                $id = (int) $pdo->lastInsertId();
            }
        } catch (Throwable $error) {
            if ($newImage !== '') {
                delete_product_image($newImage);
            }
            throw $error;
        }
        if ($newImage !== '' && $existingImage !== '' && $existingImage !== $newImage) {
            delete_product_image($existingImage);
        }
        record_activity($pdo, $userId, 'Saved product', $name);
        respond(['ok' => true, 'id' => $id, 'message' => 'Product saved successfully.']);
    }

    if ($action === 'sale_save') {
        require_csrf();
        $data = json_input();
        $items = is_array($data['items'] ?? null) ? $data['items'] : [];
        if (!$items) {
            throw new InvalidArgumentException('Add at least one product to the sale.');
        }
        assert_unique_product_items($items, 'Sale');
        $pdo->beginTransaction();
        $subtotal = 0.0;
        $validated = [];
        foreach ($items as $item) {
            $productId = positive_id($item['product_id'] ?? null, 'Sale product ID');
            $qty = strict_money($item['qty'] ?? null, 'Sale quantity', false);
            $price = strict_money($item['price'] ?? null, 'Sale price');
            $lineDiscount = strict_money($item['discount'] ?? 0, 'Sale line discount');
            if ($productId <= 0 || $qty <= 0) {
                throw new InvalidArgumentException('Every sale item needs a product and quantity.');
            }
            $stmt = $pdo->prepare('SELECT id,name,stock,manage_stock,warranty_months FROM products WHERE id=? FOR UPDATE');
            $stmt->execute([$productId]);
            $product = $stmt->fetch();
            if (!$product) {
                throw new InvalidArgumentException('A selected product no longer exists.');
            }
            if ((int) $product['manage_stock'] === 1 && (float) $product['stock'] < $qty) {
                throw new InvalidArgumentException('Not enough stock for ' . $product['name'] . '.');
            }
            $lineTotal = max(0, ($qty * $price) - $lineDiscount);
            $subtotal += $lineTotal;
            $serialNumbers = array_values(array_filter(array_map(fn($value) => clean_string($value, 160), is_array($item['serials'] ?? null) ? $item['serials'] : [])));
            $validated[] = [$productId, $qty, $price, $lineDiscount, $lineTotal, (int) $product['warranty_months'], (int) $product['manage_stock'], $serialNumbers];
        }
        $discount = min($subtotal, money($data['discount'] ?? 0));
        $vat = money($data['vat'] ?? 0);
        $otherCost = money($data['other_cost'] ?? 0);
        $total = round(max(0, $subtotal - $discount + $vat + $otherCost), 2);
        $customerId = (int) ($data['customer_id'] ?? 0) ?: null;
        $givenPayment = money($data['paid'] ?? 0);
        $advance = 0;

        if ($givenPayment > $total) {
            $advance = round($givenPayment - $total, 2);
            $paid = $total;
        } else {
            $paid = $givenPayment;
        }

        $due = round($total - $paid, 2);
        $settings = $pdo->query('SELECT invoice_prefix FROM settings WHERE id=1')->fetch();
        $invoiceNo = next_number($pdo, 'sales', $settings['invoice_prefix'] ?? 'INV');
        $accountId = (int) ($data['account_id'] ?? 0) ?: null;
        $stmt = $pdo->prepare('INSERT INTO sales (invoice_no,customer_id,account_id,sale_date,subtotal,discount,vat,other_cost,total,paid,due,note,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$invoiceNo, $customerId, $accountId, date_value($data['date'] ?? ''), $subtotal, $discount, $vat, $otherCost, $total, $paid, $due, clean_string($data['note'] ?? '', 1000), $userId]);
        $saleId = (int) $pdo->lastInsertId();

        if ($advance > 0 && $customerId) {
            $pdo->prepare(
                'UPDATE contacts SET advance_balance = advance_balance + ? WHERE id=?'
            )->execute([$advance, $customerId]);
        }

        $itemStmt = $pdo->prepare('INSERT INTO sale_items (sale_id,product_id,qty,price,discount,total,warranty_months) VALUES (?,?,?,?,?,?,?)');
        $stockStmt = $pdo->prepare('UPDATE products SET stock=stock-? WHERE id=?');
        foreach ($validated as $item) {
            $itemStmt->execute([$saleId, $item[0], $item[1], $item[2], $item[3], $item[4], $item[5]]);
            if ($item[6] === 1) {
                $stockStmt->execute([$item[1], $item[0]]);
            }
            if ($item[7]) {
                if (count($item[7]) !== count(array_unique($item[7]))) {
                    throw new InvalidArgumentException('Duplicate serial numbers are not allowed in one sale item.');
                }
                $serialStmt = $pdo->prepare("UPDATE serials SET status='sold',reference_type='sale',reference_id=? WHERE product_id=? AND serial_no=? AND status='stock'");
                foreach ($item[7] as $serialNumber) {
                    $serialStmt->execute([$saleId, $item[0], $serialNumber]);
                    if ($serialStmt->rowCount() !== 1) {
                        throw new InvalidArgumentException('Serial is not available in stock: ' . $serialNumber);
                    }
                }
            }
        }
        if ($paid > 0 && $accountId) {
            $pdo->prepare('UPDATE bank_accounts SET balance=balance+? WHERE id=?')->execute([$paid, $accountId]);
            $pdo->prepare("INSERT INTO transactions (account_id,contact_id,created_by,transaction_date,type,amount,source,reference_id,reference,note) VALUES (?,?,?,?,'in',?,'sale',?,?,?)")
                ->execute([$accountId, (int) ($data['customer_id'] ?? 0) ?: null, $userId, date_value($data['date'] ?? ''), $paid, $saleId, $invoiceNo, 'Sale payment']);
        }
        $pdo->commit();
        record_activity($pdo, $userId, 'Created sale', $invoiceNo);
        respond(['ok' => true, 'id' => $saleId, 'invoice_no' => $invoiceNo, 'message' => 'Sale created successfully.']);
    }

    if ($action === 'purchase_save') {
        require_csrf();
        $data = json_input();
        $items = is_array($data['items'] ?? null) ? $data['items'] : [];
        if (!$items) {
            throw new InvalidArgumentException('Add at least one product to the purchase.');
        }
        assert_unique_product_items($items, 'Purchase');
        $pdo->beginTransaction();
        $subtotal = 0.0;
        $validated = [];
        foreach ($items as $item) {
            $productId = positive_id($item['product_id'] ?? null, 'Purchase product ID');
            $qty = strict_money($item['qty'] ?? null, 'Purchase quantity', false);
            $cost = strict_money($item['cost_price'] ?? null, 'Purchase cost');
            $salePrice = strict_money($item['sale_price'] ?? 0, 'Purchase sale price');
            if ($productId <= 0 || $qty <= 0) {
                throw new InvalidArgumentException('Every purchase item needs a product and quantity.');
            }
            $lineTotal = round($qty * $cost, 2);
            $subtotal += $lineTotal;
            $serialNumbers = array_values(array_filter(array_map(fn($value) => clean_string($value, 160), is_array($item['serials'] ?? null) ? $item['serials'] : [])));
            $validated[] = [$productId, $qty, $cost, $salePrice, $lineTotal, max(0, (int) ($item['warranty_months'] ?? 0)), $serialNumbers, money($item['dealer_price'] ?? 0)];
        }
        $discount = min($subtotal, money($data['discount'] ?? 0));
        $otherCost = money($data['other_cost'] ?? 0);
        $total = round(max(0, $subtotal - $discount + $otherCost), 2);
        $paid = min($total, money($data['paid'] ?? 0));
        $due = round($total - $paid, 2);
        $settings = $pdo->query('SELECT purchase_prefix FROM settings WHERE id=1')->fetch();
        $invoiceNo = next_number($pdo, 'purchases', $settings['purchase_prefix'] ?? 'PUR');
        $accountId = (int) ($data['account_id'] ?? 0) ?: null;
        $stmt = $pdo->prepare('INSERT INTO purchases (invoice_no,supplier_id,account_id,purchase_date,subtotal,discount,other_cost,total,paid,due,note,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$invoiceNo, (int) ($data['supplier_id'] ?? 0) ?: null, $accountId, date_value($data['date'] ?? ''), $subtotal, $discount, $otherCost, $total, $paid, $due, clean_string($data['note'] ?? '', 1000), $userId]);
        $purchaseId = (int) $pdo->lastInsertId();
        $itemStmt = $pdo->prepare('INSERT INTO purchase_items (purchase_id,product_id,qty,cost_price,sale_price,total,warranty_months) VALUES (?,?,?,?,?,?,?)');
        $stockStmt = $pdo->prepare('UPDATE products SET stock=stock+?, cost_price=?, sale_price=IF(?>0,?,sale_price), dealer_price=IF(?>0,?,dealer_price), warranty_months=GREATEST(warranty_months,?) WHERE id=?');
        foreach ($validated as $item) {
            $itemStmt->execute([$purchaseId, $item[0], $item[1], $item[2], $item[3], $item[4], $item[5]]);
            $stockStmt->execute([$item[1], $item[2], $item[3], $item[3], $item[7], $item[7], $item[5], $item[0]]);
            if ($item[6]) {
                if (count($item[6]) !== count(array_unique($item[6]))) {
                    throw new InvalidArgumentException('Duplicate serial numbers are not allowed in one purchase item.');
                }
                $serialCheck = $pdo->prepare('SELECT id,status FROM serials WHERE serial_no=? FOR UPDATE');
                $serialStmt = $pdo->prepare("INSERT INTO serials (product_id,serial_no,status,reference_type,reference_id) VALUES (?,?,'stock','purchase',?)");
                foreach ($item[6] as $serialNumber) {
                    $serialCheck->execute([$serialNumber]);
                    if ($serialCheck->fetch()) {
                        throw new InvalidArgumentException('Serial number already exists: ' . $serialNumber);
                    }
                    $serialStmt->execute([$item[0], $serialNumber, $purchaseId]);
                }
            }
        }
        if ($paid > 0 && $accountId) {
            $pdo->prepare('UPDATE bank_accounts SET balance=balance-? WHERE id=?')->execute([$paid, $accountId]);
            $pdo->prepare("INSERT INTO transactions (account_id,contact_id,created_by,transaction_date,type,amount,source,reference_id,reference,note) VALUES (?,?,?,?,'out',?,'purchase',?,?,?)")
                ->execute([$accountId, (int) ($data['supplier_id'] ?? 0) ?: null, $userId, date_value($data['date'] ?? ''), $paid, $purchaseId, $invoiceNo, 'Purchase payment']);
        }
        $pdo->commit();
        record_activity($pdo, $userId, 'Created purchase', $invoiceNo);
        respond(['ok' => true, 'id' => $purchaseId, 'invoice_no' => $invoiceNo, 'message' => 'Purchase created successfully.']);
    }

    if ($action === 'invoices') {
        $type = clean_string($_GET['type'] ?? 'sale', 12);
        if ($type === 'purchase') {
            $data = rows($pdo, "SELECT p.*, COALESCE(c.name,'General Supplier') contact FROM purchases p LEFT JOIN contacts c ON c.id=p.supplier_id ORDER BY p.id DESC");
        } else {
            $data = rows($pdo, "SELECT s.*, COALESCE(c.name,'Walk-in Customer') contact FROM sales s LEFT JOIN contacts c ON c.id=s.customer_id ORDER BY s.id DESC");
        }
        respond(['ok' => true, 'data' => $data]);
    }

    if ($action === 'return_source') {
        $type = clean_string($_GET['type'] ?? 'sale', 12);
        $id = (int) ($_GET['id'] ?? 0);
        if ($type === 'purchase') {
            $document = rows($pdo, "SELECT p.*,COALESCE(c.name,'General Supplier') contact FROM purchases p LEFT JOIN contacts c ON c.id=p.supplier_id WHERE p.id=?", [$id]);
            $items = rows($pdo, 'SELECT i.id,i.product_id,p.name product_name,i.qty,i.cost_price price,i.total,COALESCE((SELECT SUM(ri.qty) FROM purchase_return_items ri JOIN purchase_returns r ON r.id=ri.purchase_return_id WHERE r.purchase_id=i.purchase_id AND ri.product_id=i.product_id),0) returned_qty FROM purchase_items i JOIN products p ON p.id=i.product_id WHERE i.purchase_id=?', [$id]);
        } else {
            $document = rows($pdo, "SELECT s.*,COALESCE(c.name,'Walk-in Customer') contact FROM sales s LEFT JOIN contacts c ON c.id=s.customer_id WHERE s.id=?", [$id]);
            $items = rows($pdo, 'SELECT i.id,i.product_id,p.name product_name,i.qty,i.price,i.total,COALESCE((SELECT SUM(ri.qty) FROM sale_return_items ri JOIN sale_returns r ON r.id=ri.sale_return_id WHERE r.sale_id=i.sale_id AND ri.product_id=i.product_id),0) returned_qty FROM sale_items i JOIN products p ON p.id=i.product_id WHERE i.sale_id=?', [$id]);
        }
        if (!$document) {
            throw new InvalidArgumentException('Source invoice not found.');
        }
        respond(['ok' => true, 'document' => $document[0], 'items' => $items]);
    }

    if ($action === 'returns') {
        $type = clean_string($_GET['type'] ?? 'sale', 12);
        if ($type === 'purchase') {
            $data = rows($pdo, "SELECT r.*,p.invoice_no source_invoice,COALESCE(c.name,'General Supplier') contact,u.name created_by_name FROM purchase_returns r JOIN purchases p ON p.id=r.purchase_id LEFT JOIN contacts c ON c.id=r.supplier_id LEFT JOIN users u ON u.id=r.created_by ORDER BY r.id DESC");
        } else {
            $data = rows($pdo, "SELECT r.*,s.invoice_no source_invoice,COALESCE(c.name,'Walk-in Customer') contact,u.name created_by_name FROM sale_returns r JOIN sales s ON s.id=r.sale_id LEFT JOIN contacts c ON c.id=r.customer_id LEFT JOIN users u ON u.id=r.created_by ORDER BY r.id DESC");
        }
        respond(['ok' => true, 'data' => $data]);
    }

    if ($action === 'sale_return_save') {
        require_csrf();
        $data = json_input();
        $saleId = (int) ($data['sale_id'] ?? 0);
        $items = is_array($data['items'] ?? null) ? $data['items'] : [];
        if (!$saleId || !$items) {
            throw new InvalidArgumentException('Sale invoice and return items are required.');
        }
        assert_unique_product_items($items, 'Sale return');
        $pdo->beginTransaction();
        $saleRows = rows($pdo, 'SELECT * FROM sales WHERE id=? FOR UPDATE', [$saleId]);
        if (!$saleRows) throw new InvalidArgumentException('Sale invoice not found.');
        $sale = $saleRows[0];
        $total = 0.0;
        $validated = [];
        foreach ($items as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $qty = money($item['qty'] ?? 0);
            if ($qty <= 0) continue;
            $source = rows($pdo, 'SELECT qty,price FROM sale_items WHERE sale_id=? AND product_id=?', [$saleId, $productId]);
            $returned = scalar($pdo, 'SELECT COALESCE(SUM(ri.qty),0) FROM sale_return_items ri JOIN sale_returns r ON r.id=ri.sale_return_id WHERE r.sale_id=? AND ri.product_id=?', [$saleId, $productId]);
            if (!$source || $qty > (float) $source[0]['qty'] - $returned) throw new InvalidArgumentException('Return quantity exceeds sold quantity.');
            $line = round($qty * (float) $source[0]['price'], 2);
            $total += $line;
            $validated[] = [$productId, $qty, (float) $source[0]['price'], $line];
        }
        if (!$validated) throw new InvalidArgumentException('Enter at least one return quantity.');
        $reference = next_number($pdo, 'sale_returns', 'SRT');
        $refund = min($total, money($data['refund'] ?? 0));
        $accountId = (int) ($data['account_id'] ?? 0) ?: null;
        $returnDate = date_value($data['date'] ?? '');
        $pdo->prepare('INSERT INTO sale_returns (reference,sale_id,customer_id,account_id,return_date,total,refund,note,created_by) VALUES (?,?,?,?,?,?,?,?,?)')->execute([$reference, $saleId, $sale['customer_id'], $accountId, $returnDate, $total, $refund, clean_string($data['note'] ?? ''), $userId]);
        $returnId = (int) $pdo->lastInsertId();
        $itemStmt = $pdo->prepare('INSERT INTO sale_return_items (sale_return_id,product_id,qty,price,total) VALUES (?,?,?,?,?)');
        foreach ($validated as $item) {
            $itemStmt->execute([$returnId, $item[0], $item[1], $item[2], $item[3]]);
            $pdo->prepare('UPDATE products SET stock=stock+? WHERE id=?')->execute([$item[1], $item[0]]);
        }
        if ($refund > 0 && $accountId) {
            $pdo->prepare('UPDATE bank_accounts SET balance=balance-? WHERE id=?')->execute([$refund, $accountId]);
            $pdo->prepare("INSERT INTO transactions (account_id,contact_id,created_by,transaction_date,type,amount,source,reference_id,reference,note) VALUES (?,?,?,?,'out',?,'sale_return',?,?,?)")->execute([$accountId, $sale['customer_id'], $userId, $returnDate, $refund, $returnId, $reference, 'Sale return refund']);
        }
        $returnedTotal = scalar($pdo, 'SELECT COALESCE(SUM(total),0) FROM sale_returns WHERE sale_id=?', [$saleId]);
        if ($returnedTotal >= (float) $sale['total']) $pdo->prepare("UPDATE sales SET status='returned' WHERE id=?")->execute([$saleId]);
        $pdo->commit();
        record_activity($pdo, $userId, 'Created sale return', $reference);
        respond(['ok' => true, 'message' => 'Sale return created successfully.', 'reference' => $reference]);
    }

    if ($action === 'purchase_return_save') {
        require_csrf();
        $data = json_input();
        $purchaseId = (int) ($data['purchase_id'] ?? 0);
        $items = is_array($data['items'] ?? null) ? $data['items'] : [];
        if (!$purchaseId || !$items) throw new InvalidArgumentException('Purchase invoice and return items are required.');
        assert_unique_product_items($items, 'Purchase return');
        $pdo->beginTransaction();
        $purchaseRows = rows($pdo, 'SELECT * FROM purchases WHERE id=? FOR UPDATE', [$purchaseId]);
        if (!$purchaseRows) throw new InvalidArgumentException('Purchase invoice not found.');
        $purchase = $purchaseRows[0];
        $total = 0.0;
        $validated = [];
        foreach ($items as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $qty = money($item['qty'] ?? 0);
            if ($qty <= 0) continue;
            $source = rows($pdo, 'SELECT qty,cost_price FROM purchase_items WHERE purchase_id=? AND product_id=?', [$purchaseId, $productId]);
            $returned = scalar($pdo, 'SELECT COALESCE(SUM(ri.qty),0) FROM purchase_return_items ri JOIN purchase_returns r ON r.id=ri.purchase_return_id WHERE r.purchase_id=? AND ri.product_id=?', [$purchaseId, $productId]);
            $stock = scalar($pdo, 'SELECT stock FROM products WHERE id=? FOR UPDATE', [$productId]);
            if (!$source || $qty > (float) $source[0]['qty'] - $returned || $qty > $stock) throw new InvalidArgumentException('Purchase return quantity is not available in stock.');
            $line = round($qty * (float) $source[0]['cost_price'], 2);
            $total += $line;
            $validated[] = [$productId, $qty, (float) $source[0]['cost_price'], $line];
        }
        if (!$validated) throw new InvalidArgumentException('Enter at least one return quantity.');
        $reference = next_number($pdo, 'purchase_returns', 'PRT');
        $received = min($total, money($data['received'] ?? 0));
        $accountId = (int) ($data['account_id'] ?? 0) ?: null;
        $returnDate = date_value($data['date'] ?? '');
        $pdo->prepare('INSERT INTO purchase_returns (reference,purchase_id,supplier_id,account_id,return_date,total,received,note,created_by) VALUES (?,?,?,?,?,?,?,?,?)')->execute([$reference, $purchaseId, $purchase['supplier_id'], $accountId, $returnDate, $total, $received, clean_string($data['note'] ?? ''), $userId]);
        $returnId = (int) $pdo->lastInsertId();
        $itemStmt = $pdo->prepare('INSERT INTO purchase_return_items (purchase_return_id,product_id,qty,cost_price,total) VALUES (?,?,?,?,?)');
        foreach ($validated as $item) {
            $itemStmt->execute([$returnId, $item[0], $item[1], $item[2], $item[3]]);
            $pdo->prepare('UPDATE products SET stock=stock-? WHERE id=?')->execute([$item[1], $item[0]]);
        }
        if ($received > 0 && $accountId) {
            $pdo->prepare('UPDATE bank_accounts SET balance=balance+? WHERE id=?')->execute([$received, $accountId]);
            $pdo->prepare("INSERT INTO transactions (account_id,contact_id,created_by,transaction_date,type,amount,source,reference_id,reference,note) VALUES (?,?,?,?,'in',?,'purchase_return',?,?,?)")->execute([$accountId, $purchase['supplier_id'], $userId, $returnDate, $received, $returnId, $reference, 'Purchase return received']);
        }
        $returnedTotal = scalar($pdo, 'SELECT COALESCE(SUM(total),0) FROM purchase_returns WHERE purchase_id=?', [$purchaseId]);
        if ($returnedTotal >= (float) $purchase['total']) $pdo->prepare("UPDATE purchases SET status='returned' WHERE id=?")->execute([$purchaseId]);
        $pdo->commit();
        record_activity($pdo, $userId, 'Created purchase return', $reference);
        respond(['ok' => true, 'message' => 'Purchase return created successfully.', 'reference' => $reference]);
    }

    if ($action === 'serials') {
        $data = rows($pdo, 'SELECT s.*,p.name product_name,p.warranty_months FROM serials s JOIN products p ON p.id=s.product_id ORDER BY s.id DESC');
        respond(['ok' => true, 'data' => $data]);
    }

    if ($action === 'serial_save') {
        require_csrf();
        $data = json_input();
        $productId = positive_id($data['product_id'] ?? null, 'Product ID');
        $serialNo = required($data, 'serial_no', 'Serial number');
        $pdo->prepare("INSERT INTO serials (product_id,serial_no,status,reference_type) VALUES (?,?,'stock','manual')")->execute([$productId, $serialNo]);
        record_activity($pdo, $userId, 'Added serial number', 'Product #' . $productId);
        respond(['ok' => true, 'message' => 'Serial number added.']);
    }

    if ($action === 'rmas') {
        $data = rows($pdo, "SELECT r.*,p.name product_name,s.serial_no,COALESCE(c.name,'Walk-in Customer') customer FROM rmas r LEFT JOIN products p ON p.id=r.product_id LEFT JOIN serials s ON s.id=r.serial_id LEFT JOIN contacts c ON c.id=r.customer_id ORDER BY r.id DESC");
        respond(['ok' => true, 'data' => $data]);
    }

    if ($action === 'rma_save') {
        require_csrf();
        $data = json_input();
        $serialId = positive_id($data['serial_id'] ?? null, 'Serial ID', true);
        $productId = positive_id($data['product_id'] ?? null, 'Product ID', true);
        $customerId = positive_id($data['customer_id'] ?? null, 'Customer ID', true);
        if (!$serialId && !$productId) throw new InvalidArgumentException('Choose a serial or product.');
        $issue = required($data, 'issue', 'Issue');
        $receivedDate = date_value($data['received_date'] ?? '');
        $cost = strict_money($data['cost'] ?? 0, 'Warranty cost');
        $charge = strict_money($data['charge'] ?? 0, 'Customer charge');

        $pdo->beginTransaction();
        try {
            if ($serialId) {
                $serialStmt = $pdo->prepare('SELECT id,product_id,status FROM serials WHERE id=? FOR UPDATE');
                $serialStmt->execute([$serialId]);
                $serial = $serialStmt->fetch(PDO::FETCH_ASSOC);
                if (!$serial) throw new InvalidArgumentException('Selected serial number was not found.');
                if ($serial['status'] === 'rma') throw new InvalidArgumentException('Selected serial number already has an active RMA.');
                if (!in_array($serial['status'], ['sold', 'returned'], true)) {
                    throw new InvalidArgumentException('Only sold or returned serial numbers can enter RMA.');
                }
                if ($productId && $productId !== (int) $serial['product_id']) {
                    throw new InvalidArgumentException('Selected product does not match the serial number.');
                }
                $productId = (int) $serial['product_id'];
            }

            $number = next_number($pdo, 'rmas', 'RMA');
            $pdo->prepare('INSERT INTO rmas (rma_no,serial_id,product_id,customer_id,issue,status,received_date,delivery_date,cost,charge,note) VALUES (?,?,?,?,?,?,?,?,?,?,?)')->execute([$number, $serialId, $productId, $customerId, $issue, 'in_house', $receivedDate, null, $cost, $charge, clean_string($data['note'] ?? '')]);
            if ($serialId) {
                $pdo->prepare("UPDATE serials SET status='rma',reference_type='rma',reference_id=? WHERE id=?")->execute([(int) $pdo->lastInsertId(), $serialId]);
            }
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
        record_activity($pdo, $userId, 'Created RMA', $number);
        respond(['ok' => true, 'message' => 'RMA created successfully.', 'rma_no' => $number]);
    }

    if ($action === 'rma_status') {
        require_csrf();
        $data = json_input();
        $id = positive_id($data['id'] ?? null, 'RMA ID');
        $status = enum_value($data['status'] ?? null, ['in_house', 'in_process', 'ready', 'delivered', 'cancelled'], 'RMA status');
        $pdo->beginTransaction();
        try {
            $rmaStmt = $pdo->prepare('SELECT id,serial_id,status FROM rmas WHERE id=? FOR UPDATE');
            $rmaStmt->execute([$id]);
            $rma = $rmaStmt->fetch(PDO::FETCH_ASSOC);
            if (!$rma) throw new InvalidArgumentException('RMA record was not found.');
            if (in_array($rma['status'], ['delivered', 'cancelled'], true) && $status !== $rma['status']) {
                throw new InvalidArgumentException('A completed RMA cannot be reopened.');
            }
            $pdo->prepare("UPDATE rmas SET status=?,delivery_date=CASE WHEN ?='delivered' THEN CURDATE() WHEN ? IN ('in_house','in_process','ready') THEN NULL ELSE delivery_date END WHERE id=?")->execute([$status, $status, $status, $id]);
            if (!empty($rma['serial_id']) && in_array($status, ['delivered', 'cancelled'], true)) {
                $pdo->prepare("UPDATE serials SET status='sold',reference_type='rma',reference_id=? WHERE id=? AND status='rma'")->execute([$id, (int) $rma['serial_id']]);
            }
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
        record_activity($pdo, $userId, 'Changed RMA status', 'RMA #' . $id . '; ' . $status);
        respond(['ok' => true, 'message' => 'RMA status updated.']);
    }

    if ($action === 'invoice_detail') {
    $type = clean_string($_GET['type'] ?? 'sale', 12);
    $id = (int) ($_GET['id'] ?? 0);

    if ($type === 'purchase') {

        $document = rows($pdo, "
            SELECT
                p.*,
                COALESCE(c.name, 'General Supplier') AS contact,
                c.mobile,
                c.address,
                COALESCE(ba.name, '') AS account_name,
                COALESCE(usr.name, 'System') AS created_by_name
            FROM purchases p
            LEFT JOIN contacts c
                ON c.id = p.supplier_id
            LEFT JOIN bank_accounts ba
                ON ba.id = p.account_id
            LEFT JOIN users usr
                ON usr.id = p.created_by
            WHERE p.id = ?
        ", [$id]);

        $items = rows($pdo, "
            SELECT
                i.*,
                pr.name AS product_name,
                pr.sku,
                pr.barcode,
                COALESCE(un.short_name, 'pcs') AS unit_name,
                COALESCE(
                    (
                        SELECT GROUP_CONCAT(
                            sr.serial_no
                            ORDER BY sr.id
                            SEPARATOR ', '
                        )
                        FROM serials sr
                        WHERE sr.product_id = i.product_id
                          AND sr.reference_type = 'purchase'
                          AND sr.reference_id = i.purchase_id
                    ),
                    ''
                ) AS serial_numbers
            FROM purchase_items i
            JOIN products pr
                ON pr.id = i.product_id
            LEFT JOIN units un
                ON un.id = pr.unit_id
            WHERE i.purchase_id = ?
            ORDER BY i.id
        ", [$id]);

    } else {

        $document = rows($pdo, "
            SELECT
                s.*,
                COALESCE(c.name, 'Walk-in Customer') AS contact,
                c.mobile,
                c.address,
                COALESCE(ba.name, '') AS account_name,
                COALESCE(usr.name, 'System') AS created_by_name
            FROM sales s
            LEFT JOIN contacts c
                ON c.id = s.customer_id
            LEFT JOIN bank_accounts ba
                ON ba.id = s.account_id
            LEFT JOIN users usr
                ON usr.id = s.created_by
            WHERE s.id = ?
        ", [$id]);

        $items = rows($pdo, "
            SELECT
                i.*,
                pr.name AS product_name,
                pr.sku,
                pr.barcode,
                COALESCE(un.short_name, 'pcs') AS unit_name,
                COALESCE(
                    (
                        SELECT GROUP_CONCAT(
                            sr.serial_no
                            ORDER BY sr.id
                            SEPARATOR ', '
                        )
                        FROM serials sr
                        WHERE sr.product_id = i.product_id
                          AND sr.reference_type = 'sale'
                          AND sr.reference_id = i.sale_id
                    ),
                    ''
                ) AS serial_numbers
            FROM sale_items i
            JOIN products pr
                ON pr.id = i.product_id
            LEFT JOIN units un
                ON un.id = pr.unit_id
            WHERE i.sale_id = ?
            ORDER BY i.id
        ", [$id]);
    }

    if (!$document) {
        throw new InvalidArgumentException('Invoice not found.');
    }

    $settings = $pdo
        ->query('SELECT * FROM settings WHERE id = 1')
        ->fetch();

    respond([
        'ok' => true,
        'document' => $document[0],
        'items' => $items,
        'settings' => $settings
    ]);
}

    if ($action === 'expenses') {
        $data = rows($pdo, 'SELECT e.*, t.name type_name, a.name account_name FROM expenses e LEFT JOIN expense_types t ON t.id=e.expense_type_id LEFT JOIN bank_accounts a ON a.id=e.account_id ORDER BY e.id DESC');
        respond(['ok' => true, 'data' => $data]);
    }

    if ($action === 'expense_save') {
        require_csrf();
        $data = json_input();
        $amount = strict_money($data['amount'] ?? null, 'Expense amount', false);
        if ($amount <= 0) {
            throw new InvalidArgumentException('Expense amount must be greater than zero.');
        }
        $accountId = (int) ($data['account_id'] ?? 0) ?: null;
        $date = date_value($data['date'] ?? '');
        $note = clean_string($data['note'] ?? '');
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO expenses (expense_type_id,account_id,expense_date,amount,note) VALUES (?,?,?,?,?)')->execute([(int) ($data['expense_type_id'] ?? 0) ?: null, $accountId, $date, $amount, $note]);
        $id = (int) $pdo->lastInsertId();
        if ($accountId) {
            $pdo->prepare('UPDATE bank_accounts SET balance=balance-? WHERE id=?')->execute([$amount, $accountId]);
            $pdo->prepare("INSERT INTO transactions (account_id,created_by,transaction_date,type,amount,source,reference_id,reference,note) VALUES (?,?,?,'out',?,'expense',?,'',?)")->execute([$accountId, $userId, $date, $amount, $id, $note]);
        }
        $pdo->commit();
        record_activity($pdo, $userId, 'Added expense', number_format($amount, 2));
        respond(['ok' => true, 'message' => 'Expense added successfully.']);
    }

    if ($action === 'accounts') {
        respond(['ok' => true, 'data' => rows($pdo, 'SELECT * FROM bank_accounts ORDER BY id DESC')]);
    }
if ($action === 'account_save') {
    require_csrf();
    $data = json_input();

    $id = (int) ($data['id'] ?? 0);
    $name = required($data, 'name', 'Account name');
    $accountNo = clean_string($data['account_no'] ?? '', 80);
    $bankName = clean_string($data['bank_name'] ?? '', 120);

    if ($id > 0) {
        $check = $pdo->prepare('SELECT id FROM bank_accounts WHERE id = ? LIMIT 1');
        $check->execute([$id]);

        if (!$check->fetchColumn()) {
            throw new InvalidArgumentException('Account not found.');
        }

        // Balance unchanged থাকবে।
        $stmt = $pdo->prepare(
            'UPDATE bank_accounts 
             SET name=?, account_no=?, bank_name=? 
             WHERE id=?'
        );

        $stmt->execute([
            $name,
            $accountNo,
            $bankName,
            $id
        ]);

        record_activity(
            $pdo,
            $userId,
            'Updated account',
            $name
        );

        respond([
            'ok' => true,
            'id' => $id,
            'message' => 'Account updated successfully.'
        ]);
    }

    $balance = money($data['balance'] ?? 0);

    $stmt = $pdo->prepare(
        'INSERT INTO bank_accounts
        (name, account_no, bank_name, balance)
        VALUES (?, ?, ?, ?)'
    );

    $stmt->execute([
        $name,
        $accountNo,
        $bankName,
        $balance
    ]);

    $id = (int) $pdo->lastInsertId();

    record_activity(
        $pdo,
        $userId,
        'Added account',
        $name
    );

    respond([
        'ok' => true,
        'id' => $id,
        'message' => 'Account added successfully.'
    ]);
}

    if ($action === 'transfer_save') {
        require_csrf();
        $data = json_input();
        $from = positive_id($data['from_account_id'] ?? null, 'Source account ID');
        $to = positive_id($data['to_account_id'] ?? null, 'Destination account ID');
        $amount = strict_money($data['amount'] ?? null, 'Transfer amount', false);
        if (!$from || !$to || $from === $to || $amount <= 0) {
            throw new InvalidArgumentException('Choose two different accounts and enter a valid amount.');
        }
        $date = date_value($data['date'] ?? '');
        $note = clean_string($data['note'] ?? 'Balance transfer');
        $pdo->beginTransaction();
        $lockIds = [$from, $to];
        sort($lockIds, SORT_NUMERIC);
        $lockedAccounts = rows($pdo, 'SELECT id,balance FROM bank_accounts WHERE id IN (?,?) ORDER BY id FOR UPDATE', $lockIds);
        if (count($lockedAccounts) !== 2) {
            throw new InvalidArgumentException('One of the selected accounts no longer exists.');
        }
        $balances = [];
        foreach ($lockedAccounts as $account) $balances[(int) $account['id']] = (float) $account['balance'];
        if (($balances[$from] ?? -1) < $amount) {
            throw new InvalidArgumentException('The source account does not have enough balance.');
        }
        $pdo->prepare('UPDATE bank_accounts SET balance=balance-? WHERE id=?')->execute([$amount, $from]);
        $pdo->prepare('UPDATE bank_accounts SET balance=balance+? WHERE id=?')->execute([$amount, $to]);
        $stmt = $pdo->prepare('INSERT INTO transactions (account_id,created_by,transaction_date,type,amount,source,reference,note) VALUES (?,?,?,?,?,?,?,?)');
        $reference = next_number($pdo, 'transfers', 'TR');
        $stmt->execute([$from, $userId, $date, 'transfer_out', $amount, 'transfer', $reference, $note]);
        $stmt->execute([$to, $userId, $date, 'transfer_in', $amount, 'transfer', $reference, $note]);
        $pdo->commit();
        record_activity($pdo, $userId, 'Transferred balance', $reference);
        respond(['ok' => true, 'message' => 'Balance transferred successfully.']);
    }

    if ($action === 'transactions') {
        $data = rows($pdo, 'SELECT t.*, a.name account_name,c.name contact_name,u.name created_by_name FROM transactions t LEFT JOIN bank_accounts a ON a.id=t.account_id LEFT JOIN contacts c ON c.id=t.contact_id LEFT JOIN users u ON u.id=t.created_by ORDER BY t.id DESC LIMIT 500');
        respond(['ok' => true, 'data' => $data]);
    }

    if ($action === 'cheques') {
        $status = clean_string($_GET['status'] ?? '', 20);
        $params = [];
        $where = '';
        if (in_array($status, ['pending', 'deposited', 'bounce', 'cleared'], true)) {
            $where = 'WHERE q.status=?';
            $params[] = $status;
        }
        $data = rows($pdo, "SELECT q.*,a.name account_name,COALESCE(c.name,'No Contact') contact,c.mobile FROM cheques q LEFT JOIN bank_accounts a ON a.id=q.account_id LEFT JOIN contacts c ON c.id=q.contact_id {$where} ORDER BY q.id DESC", $params);
        respond(['ok' => true, 'data' => $data]);
    }

    if ($action === 'cheque_save') {
        require_csrf();
        $data = json_input();
        $type = enum_value($data['type'] ?? '', ['receive', 'payment'], 'Cheque type', 'receive');
        $amount = strict_money($data['amount'] ?? null, 'Cheque amount', false);
        if ($amount <= 0) throw new InvalidArgumentException('Cheque amount must be greater than zero.');
        $chequeNo = required($data, 'cheque_no', 'Cheque number');
        $pdo->prepare('INSERT INTO cheques (account_id,contact_id,cheque_no,amount,issue_date,cheque_date,type,status,note) VALUES (?,?,?,?,?,?,?,"pending",?)')->execute([(int) ($data['account_id'] ?? 0) ?: null, (int) ($data['contact_id'] ?? 0) ?: null, $chequeNo, $amount, date_value($data['issue_date'] ?? ''), date_value($data['cheque_date'] ?? ''), $type, clean_string($data['note'] ?? '')]);
        record_activity($pdo, $userId, 'Created cheque', $chequeNo . '; ' . $type . '; amount ' . number_format($amount, 2));
        respond(['ok' => true, 'message' => 'Cheque added successfully.']);
    }

    if ($action === 'cheque_status') {
        require_csrf();
        $data = json_input();
        $status = enum_value($data['status'] ?? '', ['pending', 'deposited', 'bounce', 'cleared'], 'Cheque status');
        $id = positive_id($data['id'] ?? null, 'Cheque ID');
        $pdo->beginTransaction();
        $chequeRows = rows($pdo, 'SELECT * FROM cheques WHERE id=? FOR UPDATE', [$id]);
        if (!$chequeRows) throw new InvalidArgumentException('Cheque not found.');
        $cheque = $chequeRows[0];
        if ($status === 'cleared' && $cheque['status'] !== 'cleared' && $cheque['account_id']) {
            $sign = $cheque['type'] === 'receive' ? 1 : -1;
            $pdo->prepare('UPDATE bank_accounts SET balance=balance+? WHERE id=?')->execute([$sign * (float) $cheque['amount'], $cheque['account_id']]);
            $transactionType = $cheque['type'] === 'receive' ? 'in' : 'out';
            $pdo->prepare('INSERT INTO transactions (account_id,contact_id,created_by,transaction_date,type,amount,source,reference_id,reference,note) VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([$cheque['account_id'], $cheque['contact_id'], $userId, date('Y-m-d'), $transactionType, $cheque['amount'], 'cheque', $id, $cheque['cheque_no'], 'Cheque cleared']);
        }
        $pdo->prepare('UPDATE cheques SET status=? WHERE id=?')->execute([$status, $id]);
        $pdo->commit();
        record_activity($pdo, $userId, 'Changed cheque status', 'Cheque #' . $id . '; status ' . $status);
        respond(['ok' => true, 'message' => 'Cheque status updated.']);
    }

    if ($action === 'contact_ledger') {
        $contactId = (int) ($_GET['contact_id'] ?? 0);
        $contact = rows($pdo, 'SELECT * FROM contacts WHERE id=?', [$contactId]);
        if (!$contact) throw new InvalidArgumentException('Contact not found.');
        $entries = [];
        foreach (rows($pdo, 'SELECT sale_date entry_date,invoice_no reference,total debit,paid credit,due balance,"Sale" entry_type FROM sales WHERE customer_id=? ORDER BY sale_date,id', [$contactId]) as $row) $entries[] = $row;
        foreach (rows($pdo, 'SELECT purchase_date entry_date,invoice_no reference,paid debit,total credit,due balance,"Purchase" entry_type FROM purchases WHERE supplier_id=? ORDER BY purchase_date,id', [$contactId]) as $row) $entries[] = $row;
        foreach (rows($pdo, 'SELECT payment_date entry_date,CONCAT("PAY-",id) reference,IF(type="payment",amount,0) debit,IF(type="receive",amount,0) credit,0 balance,"Payment" entry_type FROM contact_payments WHERE contact_id=? ORDER BY payment_date,id', [$contactId]) as $row) $entries[] = $row;
        usort($entries, fn($a, $b) => strcmp($a['entry_date'], $b['entry_date']));
        respond(['ok' => true, 'contact' => $contact[0], 'entries' => $entries]);
    }

    if ($action === 'contact_payment_save') {
        require_csrf();
        $data = json_input();
        $contactId = positive_id($data['contact_id'] ?? null, 'Contact ID');
        $accountId = positive_id($data['account_id'] ?? null, 'Account ID', true);
        $amount = strict_money($data['amount'] ?? null, 'Payment amount', false);
        $type = enum_value($data['type'] ?? '', ['receive', 'payment'], 'Payment type', 'receive');
        if (!$contactId || $amount <= 0) throw new InvalidArgumentException('Contact and amount are required.');
        $date = date_value($data['date'] ?? '');
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO contact_payments (contact_id,account_id,payment_date,type,amount,discount,note,created_by) VALUES (?,?,?,?,?,?,?,?)')->execute([$contactId, $accountId, $date, $type, $amount, money($data['discount'] ?? 0), clean_string($data['note'] ?? ''), $userId]);
        $paymentId = (int) $pdo->lastInsertId();
        $remaining = $amount;
        if ($type === 'receive') {
            $documents = rows($pdo, "SELECT id,due FROM sales WHERE customer_id=? AND due>0 AND status='completed' ORDER BY sale_date,id FOR UPDATE", [$contactId]);
            foreach ($documents as $document) { 
                if ($remaining <= 0) break; 
                $applied = min($remaining, (float) $document['due']); 
                $pdo->prepare('UPDATE sales SET paid=paid+?,due=due-? WHERE id=?')->execute([$applied, $applied, $document['id']]); 
                $remaining -= $applied; 
            }

            if ($remaining > 0) {
                $pdo->prepare(
                    'UPDATE contacts SET advance_balance = advance_balance + ? WHERE id=?'
                )->execute([$remaining, $contactId]);
                $remaining = 0;
            }
        } else {
            $documents = rows($pdo, "SELECT id,due FROM purchases WHERE supplier_id=? AND due>0 AND status='completed' ORDER BY purchase_date,id FOR UPDATE", [$contactId]);
            foreach ($documents as $document) { if ($remaining <= 0) break; $applied = min($remaining, (float) $document['due']); $pdo->prepare('UPDATE purchases SET paid=paid+?,due=due-? WHERE id=?')->execute([$applied, $applied, $document['id']]); $remaining -= $applied; }
        }
        if ($accountId) {
            $sign = $type === 'receive' ? 1 : -1;
            $pdo->prepare('UPDATE bank_accounts SET balance=balance+? WHERE id=?')->execute([$sign * $amount, $accountId]);
            $pdo->prepare('INSERT INTO transactions (account_id,contact_id,created_by,transaction_date,type,amount,source,reference_id,reference,note) VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([$accountId, $contactId, $userId, $date, $type === 'receive' ? 'in' : 'out', $amount, 'contact_payment', $paymentId, 'PAY-' . $paymentId, clean_string($data['note'] ?? '')]);
        }
        $pdo->commit();
        record_activity($pdo, $userId, 'Recorded contact payment', 'Contact #' . $contactId . '; ' . $type . '; amount ' . number_format($amount, 2));
        respond(['ok' => true, 'message' => 'Contact payment saved.']);
    }

    if ($action === 'contact_payments') {
        $data = rows($pdo, "SELECT cp.*,c.name contact,c.type contact_type,COALESCE(a.name,'No Account') account,COALESCE(u.name,'System') created_by_name FROM contact_payments cp JOIN contacts c ON c.id=cp.contact_id LEFT JOIN bank_accounts a ON a.id=cp.account_id LEFT JOIN users u ON u.id=cp.created_by ORDER BY cp.id DESC LIMIT 500");
        respond(['ok' => true, 'data' => $data]);
    }

    if ($action === 'sms_packages') {
        respond(['ok' => true, 'balance' => (int) scalar($pdo, 'SELECT sms_balance FROM settings WHERE id=1'), 'history' => rows($pdo, "SELECT sp.*,COALESCE(a.name,'No Account') account FROM sms_purchases sp LEFT JOIN bank_accounts a ON a.id=sp.account_id ORDER BY sp.id DESC")]);
    }

    if ($action === 'sms_purchase') {
        require_csrf();
        $data = json_input();
        $packages = [100 => 120.0, 500 => 550.0, 1000 => 1000.0, 5000 => 4500.0];
        $units = positive_id($data['units'] ?? null, 'SMS units');
        $accountId = positive_id($data['account_id'] ?? null, 'Payment account ID');
        if (!isset($packages[$units]) || !$accountId) {
            throw new InvalidArgumentException('Choose a valid SMS package and payment account.');
        }
        $amount = $packages[$units];
        $pdo->beginTransaction();
        $balance = money(scalar($pdo, 'SELECT balance FROM bank_accounts WHERE id=? FOR UPDATE', [$accountId]));
        if ($balance < $amount) {
            throw new InvalidArgumentException('The selected account does not have enough balance.');
        }
        $packageName = number_format($units) . ' SMS';
        $pdo->prepare('INSERT INTO sms_purchases (package_name,units,amount,account_id) VALUES (?,?,?,?)')->execute([$packageName, $units, $amount, $accountId]);
        $purchaseId = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE bank_accounts SET balance=balance-? WHERE id=?')->execute([$amount, $accountId]);
        $pdo->prepare('UPDATE settings SET sms_balance=sms_balance+? WHERE id=1')->execute([$units]);
        $pdo->prepare("INSERT INTO transactions (account_id,created_by,transaction_date,type,amount,source,reference_id,reference,note) VALUES (?,?,CURDATE(),'out',?,'sms',?,?,'SMS package purchase')")->execute([$accountId, $userId, $amount, $purchaseId, 'SMS-' . $purchaseId]);
        $pdo->commit();
        record_activity($pdo, $userId, 'Purchased SMS package', $packageName);
        respond(['ok' => true, 'message' => $packageName . ' package purchased successfully.']);
    }

    if ($action === 'services') {
        $data = rows($pdo, "SELECT s.id,s.service_no,s.customer_id,s.technician_id,s.device,s.issue,s.serial_no,s.device_condition,s.technician_notes,s.status,s.amount,s.paid,s.service_charge,s.refund,s.received_date,s.delivery_date,s.note,s.created_at,CASE WHEN COALESCE(s.device_password,'')<>'' THEN 1 ELSE 0 END has_device_password,CASE WHEN s.device_password LIKE 'enc:v1:%' THEN 'encrypted' WHEN COALESCE(s.device_password,'')<>'' THEN 'legacy' ELSE 'none' END credential_protection,COALESCE(c.name,'Walk-in Customer') customer,c.mobile customer_mobile,e.name technician FROM services s LEFT JOIN contacts c ON c.id=s.customer_id LEFT JOIN employees e ON e.id=s.technician_id ORDER BY s.id DESC");
        respond(['ok' => true, 'data' => $data]);
    }

    if ($action === 'service_save') {
        require_csrf();
        $data = json_input();
        $number = next_number($pdo, 'services', 'SRV');
        $status = in_array($data['status'] ?? '', ['received', 'working', 'ready', 'delivered', 'cancelled'], true) ? $data['status'] : 'received';
        $amount = money($data['amount'] ?? 0);
        $charge = money($data['service_charge'] ?? 0);
        $paid = min($amount + $charge, money($data['paid'] ?? 0));
        $accountId = (int) ($data['account_id'] ?? 0) ?: null;
        $receivedDate = date_value($data['received_date'] ?? '');
        $pdo->beginTransaction();
        $deviceCredential = clean_string($data['device_password'] ?? '', 120);
        $protectedCredential = $deviceCredential === '' ? '' : encrypt_service_credential($deviceCredential);
        $pdo->prepare('INSERT INTO services (service_no,customer_id,technician_id,device,issue,serial_no,device_password,device_condition,technician_notes,status,amount,paid,service_charge,received_date,delivery_date,note) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$number, (int) ($data['customer_id'] ?? 0) ?: null, (int) ($data['technician_id'] ?? 0) ?: null, required($data, 'device', 'Device'), required($data, 'issue', 'Issue'), clean_string($data['serial_no'] ?? '', 160), $protectedCredential, clean_string($data['device_condition'] ?? '', 180), clean_string($data['technician_notes'] ?? '', 1000), $status, $amount, $paid, $charge, $receivedDate, !empty($data['delivery_date']) ? date_value($data['delivery_date']) : null, clean_string($data['note'] ?? '')]);
        $serviceId = (int) $pdo->lastInsertId();
        if ($paid > 0 && $accountId) {
            $pdo->prepare('UPDATE bank_accounts SET balance=balance+? WHERE id=?')->execute([$paid, $accountId]);
            $pdo->prepare("INSERT INTO transactions (account_id,contact_id,created_by,transaction_date,type,amount,source,reference_id,reference,note) VALUES (?,?,?,?,'in',?,'service',?,?,?)")
                ->execute([$accountId, (int) ($data['customer_id'] ?? 0) ?: null, $userId, $receivedDate, $paid, $serviceId, $number, 'Service payment']);
        }
        $pdo->commit();
        record_activity($pdo, $userId, 'Created service', $number);
        respond(['ok' => true, 'message' => 'Service job created successfully.', 'service_no' => $number]);
    }

    if ($action === 'service_credential_reveal') {
        require_csrf();
        $data = json_input();
        $serviceId = (int) ($data['id'] ?? 0);
        if ($serviceId <= 0) {
            throw new InvalidArgumentException('Service ID is required.');
        }
        $stmt = $pdo->prepare('SELECT service_no,device_password FROM services WHERE id=? LIMIT 1');
        $stmt->execute([$serviceId]);
        $service = $stmt->fetch();
        if (!$service) {
            respond(['ok' => false, 'message' => 'Service record not found.'], 404, 'SERVICE_NOT_FOUND');
        }
        $storedCredential = (string) ($service['device_password'] ?? '');
        if ($storedCredential === '') {
            respond(['ok' => false, 'message' => 'No device credential is stored for this service.'], 404, 'SERVICE_CREDENTIAL_NOT_FOUND');
        }
        if (!str_starts_with($storedCredential, 'enc:v1:')) {
            record_activity($pdo, $userId, 'Blocked legacy credential reveal', 'Service ID ' . $serviceId);
            respond(['ok' => false, 'message' => 'This legacy credential must be migrated in Phase 2 before it can be revealed.'], 409, 'LEGACY_CREDENTIAL_REQUIRES_MIGRATION');
        }
        $credential = decrypt_service_credential($storedCredential);
        record_activity($pdo, $userId, 'Revealed service credential', 'Service ID ' . $serviceId);
        respond(['ok' => true, 'service_id' => $serviceId, 'service_no' => $service['service_no'], 'credential' => $credential]);
    }

    if ($action === 'service_status') {
        require_csrf();
        $data = json_input();
        $status = enum_value($data['status'] ?? '', ['received', 'working', 'ready', 'delivered', 'cancelled'], 'Service status');
        $serviceId = positive_id($data['id'] ?? null, 'Service ID');
        $purgeCredential = in_array($status, ['delivered', 'cancelled'], true);
        $pdo->prepare('UPDATE services SET status=?, delivery_date=IF(?="delivered",COALESCE(delivery_date,CURDATE()),delivery_date), device_password=IF(?,NULL,device_password) WHERE id=?')->execute([$status, $status, $purgeCredential ? 1 : 0, $serviceId]);
        if ($purgeCredential) {
            record_activity($pdo, $userId, 'Purged service credential', 'Service ID ' . $serviceId . '; terminal status ' . $status);
        } else {
            record_activity($pdo, $userId, 'Changed service status', 'Service ID ' . $serviceId . '; status ' . $status);
        }
        respond(['ok' => true, 'message' => 'Service status updated.']);
    }

    if ($action === 'quotation_save') {
        require_csrf();
        $data = json_input();
        $items = is_array($data['items'] ?? null) ? $data['items'] : [];
        if (!$items) {
            throw new InvalidArgumentException('Add at least one quotation item.');
        }
        assert_unique_product_items($items, 'Quotation');
        $pdo->beginTransaction();
        $total = 0.0;
        $profit = 0.0;
        foreach ($items as &$item) {
            $productId = positive_id($item['product_id'] ?? null, 'Quotation product ID');
            $item['product_id'] = $productId;
            $item['qty'] = strict_money($item['qty'] ?? null, 'Quotation quantity', false);
            $item['price'] = strict_money($item['price'] ?? null, 'Quotation price');
            $productCostRows = rows($pdo, 'SELECT cost_price FROM products WHERE id=?', [$productId]);
            if (!$productCostRows) {
                throw new InvalidArgumentException('A quotation product no longer exists.');
            }
            $item['total'] = round($item['qty'] * $item['price'], 2);
            $total += $item['total'];
            $profit += round(($item['price'] - (float) $productCostRows[0]['cost_price']) * $item['qty'], 2);
        }
        unset($item);
        $number = next_number($pdo, 'quotations', 'QTN');
        $pdo->prepare('INSERT INTO quotations (quote_no,customer_id,bill_to,quote_date,valid_until,total,profit,status,note,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([$number, (int) ($data['customer_id'] ?? 0) ?: null, clean_string($data['bill_to'] ?? '', 180), date_value($data['date'] ?? ''), !empty($data['valid_until']) ? date_value($data['valid_until']) : null, $total, $profit, 'draft', clean_string($data['note'] ?? ''), $userId]);
        $quoteId = (int) $pdo->lastInsertId();
        $stmt = $pdo->prepare('INSERT INTO quotation_items (quotation_id,product_id,qty,price,total) VALUES (?,?,?,?,?)');
        foreach ($items as $item) {
            $stmt->execute([$quoteId, (int) $item['product_id'], $item['qty'], $item['price'], $item['total']]);
        }
        $pdo->commit();
        record_activity($pdo, $userId, 'Created quotation', $number);
        respond(['ok' => true, 'message' => 'Quotation created successfully.', 'quote_no' => $number]);
    }

    if ($action === 'quotations') {
        $data = rows($pdo, "SELECT q.*, COALESCE(NULLIF(q.bill_to,''),c.name,'Walk-in Customer') customer,COALESCE(u.name,'System') created_by_name FROM quotations q LEFT JOIN contacts c ON c.id=q.customer_id LEFT JOIN users u ON u.id=q.created_by ORDER BY q.id DESC");
        respond(['ok' => true, 'data' => $data]);
    }

    if ($action === 'quotation_details') {
        $id = (int) ($_GET['id'] ?? 0);
        $quotation = rows($pdo, "SELECT q.*,COALESCE(NULLIF(q.bill_to,''),c.name,'Walk-in Customer') customer,c.mobile customer_mobile,c.address customer_address,COALESCE(u.name,'System') created_by_name FROM quotations q LEFT JOIN contacts c ON c.id=q.customer_id LEFT JOIN users u ON u.id=q.created_by WHERE q.id=?", [$id]);
        if (!$quotation) {
            throw new InvalidArgumentException('Quotation not found.');
        }
        $items = rows($pdo, 'SELECT qi.*,p.name product_name,p.barcode,p.warranty_months FROM quotation_items qi JOIN products p ON p.id=qi.product_id WHERE qi.quotation_id=? ORDER BY qi.id', [$id]);
        respond(['ok' => true, 'quotation' => $quotation[0], 'items' => $items]);
    }

    if ($action === 'damage_save') {
        require_csrf();
        $data = json_input();
        $productId = positive_id($data['product_id'] ?? null, 'Product ID');
        $qty = strict_money($data['qty'] ?? null, 'Damage quantity', false);
        if (!$productId || $qty <= 0) {
            throw new InvalidArgumentException('Choose a product and enter a valid quantity.');
        }
        $pdo->beginTransaction();
        $stock = scalar($pdo, 'SELECT stock FROM products WHERE id=? FOR UPDATE', [$productId]);
        if ($stock < $qty) {
            throw new InvalidArgumentException('Damage quantity cannot exceed current stock.');
        }
        $product = rows($pdo, 'SELECT barcode,cost_price FROM products WHERE id=?', [$productId])[0];
        $purchasePrice = money($data['purchase_price'] ?? $product['cost_price']);
        $reference = next_number($pdo, 'damages', 'DMG');
        $serialNo = clean_string($data['serial_no'] ?? '', 160);
        if ($serialNo !== '') {
            $serial = rows($pdo, 'SELECT id,status FROM serials WHERE serial_no=? AND product_id=? FOR UPDATE', [$serialNo, $productId]);
            if (!$serial || !in_array($serial[0]['status'], ['stock', 'rma'], true)) {
                throw new InvalidArgumentException('The selected serial is not available in stock.');
            }
            $pdo->prepare("UPDATE serials SET status='damaged' WHERE id=?")->execute([(int) $serial[0]['id']]);
        }
        $pdo->prepare('INSERT INTO damages (reference_no,product_id,serial_no,qty,purchase_price,total,reason,damage_date) VALUES (?,?,?,?,?,?,?,?)')->execute([$reference, $productId, $serialNo, $qty, $purchasePrice, round($purchasePrice * $qty, 2), clean_string($data['reason'] ?? ''), date_value($data['date'] ?? '')]);
        $pdo->prepare('UPDATE products SET stock=stock-? WHERE id=?')->execute([$qty, $productId]);
        $pdo->commit();
        record_activity($pdo, $userId, 'Recorded damage', 'Product #' . $productId);
        respond(['ok' => true, 'message' => 'Damaged stock recorded.', 'reference' => $reference]);
    }

    if ($action === 'damages') {
        respond(['ok' => true, 'data' => rows($pdo, 'SELECT d.*, p.name product_name,p.barcode FROM damages d JOIN products p ON p.id=d.product_id ORDER BY d.id DESC')]);
    }

    if ($action === 'investor_save') {
        require_csrf();
        $data = json_input();
        $name = required($data, 'name', 'Investor name');
        $pdo->prepare('INSERT INTO investors (name,mobile,amount,join_date,note) VALUES (?,?,?,?,?)')->execute([$name, clean_string($data['mobile'] ?? '', 30), money($data['amount'] ?? 0), date_value($data['date'] ?? ''), clean_string($data['note'] ?? '')]);
        record_activity($pdo, $userId, 'Added investor', $name);
        respond(['ok' => true, 'message' => 'Investor added successfully.']);
    }

    if ($action === 'investors') {
        respond(['ok' => true, 'data' => rows($pdo, 'SELECT * FROM investors ORDER BY id DESC')]);
    }

    if ($action === 'emi_save') {
        require_csrf();
        $data = json_input();
        $total = money($data['total'] ?? 0);
        $down = min($total, money($data['down_payment'] ?? 0));
        $count = max(1, (int) ($data['installment_count'] ?? 1));
        $saleId = (int) ($data['sale_id'] ?? 0) ?: null;
        $customerId = (int) ($data['customer_id'] ?? 0) ?: null;
        if ($saleId && !$customerId) {
            $customerId = (int) scalar($pdo, 'SELECT customer_id FROM sales WHERE id=?', [$saleId]);
        }
        if (!$customerId || $total <= 0) {
            throw new InvalidArgumentException('Customer and total amount are required.');
        }
        $installment = round(($total - $down) / $count, 2);
        $frequency = in_array($data['frequency'] ?? '', ['monthly','weekly','daily'], true) ? $data['frequency'] : 'monthly';
        $startDate = date_value($data['start_date'] ?? '');
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO emis (customer_id,sale_id,total,down_payment,installment_count,installment_amount,frequency,start_date,status) VALUES (?,?,?,?,?,?,?,?,?)')->execute([$customerId, $saleId, $total, $down, $count, $installment, $frequency, $startDate, 'active']);
        $emiId = (int) $pdo->lastInsertId();
        $scheduleStmt = $pdo->prepare('INSERT INTO emi_installments (emi_id,installment_no,due_date,amount) VALUES (?,?,?,?)');
        $scheduleDate = new DateTimeImmutable($startDate);
        for ($number = 1; $number <= $count; $number++) {
            $dueDate = match ($frequency) { 'daily' => $scheduleDate->modify('+' . ($number - 1) . ' days'), 'weekly' => $scheduleDate->modify('+' . ($number - 1) . ' weeks'), default => $scheduleDate->modify('+' . ($number - 1) . ' months') };
            $scheduleStmt->execute([$emiId, $number, $dueDate->format('Y-m-d'), $number === $count ? round(($total - $down) - ($installment * ($count - 1)), 2) : $installment]);
        }
        $pdo->commit();
        record_activity($pdo, $userId, 'Created EMI', number_format($total, 2));
        respond(['ok' => true, 'message' => 'EMI plan created successfully.']);
    }

    if ($action === 'emi_payment') {
        require_csrf();
        $data = json_input();
        $emiId = positive_id($data['emi_id'] ?? null, 'EMI ID');
        $amount = strict_money($data['amount'] ?? null, 'EMI payment amount', false);
        if (!$emiId || $amount <= 0) {
            throw new InvalidArgumentException('EMI and payment amount are required.');
        }
        $pdo->beginTransaction();
        $plans = rows($pdo, 'SELECT total,down_payment,status FROM emis WHERE id=? FOR UPDATE', [$emiId]);
        if (!$plans) {
            throw new InvalidArgumentException('EMI plan not found.');
        }
        $existingPaid = scalar($pdo, 'SELECT COALESCE(SUM(amount),0) FROM emi_payments WHERE emi_id=?', [$emiId]);
        $outstanding = round(max(0, (float) $plans[0]['total'] - (float) $plans[0]['down_payment'] - $existingPaid), 2);
        if ($outstanding <= 0) {
            throw new InvalidArgumentException('This EMI plan is already fully paid.');
        }
        if ($amount > $outstanding + 0.001) {
            throw new InvalidArgumentException('Payment exceeds the remaining EMI balance of ' . number_format($outstanding, 2) . '.');
        }
        $paymentDate = date_value($data['date'] ?? '');
        $pdo->prepare('INSERT INTO emi_payments (emi_id,payment_date,amount,note) VALUES (?,?,?,?)')->execute([$emiId, $paymentDate, $amount, clean_string($data['note'] ?? '')]);
        $remainingPayment = $amount;
        $installments = rows($pdo, "SELECT * FROM emi_installments WHERE emi_id=? AND status<>'paid' ORDER BY installment_no FOR UPDATE", [$emiId]);
        foreach ($installments as $installmentRow) {
            if ($remainingPayment <= 0) break;
            $needed = (float) $installmentRow['amount'] - (float) $installmentRow['paid'];
            $applied = min($remainingPayment, $needed);
            $newPaid = (float) $installmentRow['paid'] + $applied;
            $status = $newPaid >= (float) $installmentRow['amount'] ? 'paid' : 'partial';
            $pdo->prepare('UPDATE emi_installments SET paid=?,paid_date=?,status=? WHERE id=?')->execute([$newPaid, $paymentDate, $status, $installmentRow['id']]);
            $remainingPayment -= $applied;
        }
        $paid = scalar($pdo, 'SELECT COALESCE(SUM(amount),0) FROM emi_payments WHERE emi_id=?', [$emiId]);
        $plan = rows($pdo, 'SELECT total,down_payment FROM emis WHERE id=?', [$emiId]);
        if ($plan && $paid + (float) $plan[0]['down_payment'] >= (float) $plan[0]['total']) {
            $pdo->prepare("UPDATE emis SET status='completed' WHERE id=?")->execute([$emiId]);
        }
        $pdo->commit();
        record_activity($pdo, $userId, 'Recorded EMI payment', 'EMI #' . $emiId . '; amount ' . number_format($amount, 2));
        respond(['ok' => true, 'message' => 'Installment payment recorded.']);
    }

    if ($action === 'emis') {
        $data = rows($pdo, "SELECT e.*, c.name customer,c.mobile, s.invoice_no sale_reference, COALESCE((SELECT SUM(amount) FROM emi_payments p WHERE p.emi_id=e.id),0) paid_installments,(SELECT MIN(due_date) FROM emi_installments i WHERE i.emi_id=e.id AND i.status<>'paid') next_due FROM emis e LEFT JOIN contacts c ON c.id=e.customer_id LEFT JOIN sales s ON s.id=e.sale_id ORDER BY e.id DESC");
        respond(['ok' => true, 'data' => $data]);
    }

    if ($action === 'installments') {
        $status = clean_string($_GET['status'] ?? 'due', 10);
        $where = $status === 'paid' ? "i.status='paid'" : "i.status<>'paid'";
        $data = rows($pdo, "SELECT i.*,e.installment_count,e.frequency,c.name customer,c.mobile FROM emi_installments i JOIN emis e ON e.id=i.emi_id LEFT JOIN contacts c ON c.id=e.customer_id WHERE {$where} ORDER BY i.due_date,i.id");
        respond(['ok' => true, 'data' => $data]);
    }

    if ($action === 'employee_save') {
        require_csrf();
        $data = json_input();
        $name = required($data, 'name', 'Employee name');
        $pdo->prepare('INSERT INTO employees (name,mobile,designation,role_id,salary,salary_day,manage_business,is_sr,join_date,status) VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([$name, clean_string($data['mobile'] ?? '', 30), clean_string($data['designation'] ?? '', 100), (int) ($data['role_id'] ?? 0) ?: null, money($data['salary'] ?? 0), max(1, min(28, (int) ($data['salary_day'] ?? 1))), !empty($data['manage_business']) ? 1 : 0, !empty($data['is_sr']) ? 1 : 0, date_value($data['join_date'] ?? ''), 'active']);
        record_activity($pdo, $userId, 'Added team member', $name);
        respond(['ok' => true, 'message' => 'Team member added successfully.']);
    }

    if ($action === 'employees') {
        respond(['ok' => true, 'data' => rows($pdo, 'SELECT e.*,r.name role_name FROM employees e LEFT JOIN roles r ON r.id=e.role_id ORDER BY e.id DESC')]);
    }

    if ($action === 'attendance_save') {
        require_csrf();
        $data = json_input();
        $employeeId = (int) ($data['employee_id'] ?? 0);
        if (!$employeeId) {
            throw new InvalidArgumentException('Choose a team member.');
        }
        $pdo->prepare('INSERT INTO attendance (employee_id,attendance_date,status,check_in,check_out) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE status=VALUES(status),check_in=VALUES(check_in),check_out=VALUES(check_out)')
            ->execute([$employeeId, date_value($data['date'] ?? ''), clean_string($data['status'] ?? 'present', 20), clean_string($data['check_in'] ?? '', 8) ?: null, clean_string($data['check_out'] ?? '', 8) ?: null]);
        respond(['ok' => true, 'message' => 'Attendance saved.']);
    }

    if ($action === 'attendance') {
        $data = rows($pdo, 'SELECT a.*, e.name employee, e.designation FROM attendance a JOIN employees e ON e.id=a.employee_id ORDER BY a.attendance_date DESC,a.id DESC LIMIT 500');
        respond(['ok' => true, 'data' => $data]);
    }

    if ($action === 'attendance_schedule') {
        $schedule = $pdo->query('SELECT * FROM attendance_schedules WHERE id=1')->fetch();
        respond(['ok' => true, 'schedule' => $schedule ?: null]);
    }

    if ($action === 'attendance_schedule_save') {
        require_csrf();
        $data = json_input();
        $offDays = is_array($data['off_days'] ?? null) ? implode(',', array_intersect($data['off_days'], ['Sat','Sun','Mon','Tue','Wed','Thu','Fri'])) : clean_string($data['off_days'] ?? '', 80);
        $checkIn = clean_string($data['check_in'] ?? '', 8);
        $checkOut = clean_string($data['check_out'] ?? '', 8);
        if (!$checkIn || !$checkOut) throw new InvalidArgumentException('Check-in and check-out times are required.');
        $pdo->prepare('INSERT INTO attendance_schedules (id,off_days,check_in,check_out,late_minutes,absent_minutes) VALUES (1,?,?,?,?,?) ON DUPLICATE KEY UPDATE off_days=VALUES(off_days),check_in=VALUES(check_in),check_out=VALUES(check_out),late_minutes=VALUES(late_minutes),absent_minutes=VALUES(absent_minutes)')->execute([$offDays, $checkIn, $checkOut, max(0, (int) ($data['late_minutes'] ?? 0)), max(0, (int) ($data['absent_minutes'] ?? 0))]);
        respond(['ok' => true, 'message' => 'Attendance schedule saved.']);
    }

    if ($action === 'roles') {
        respond(['ok' => true, 'data' => rows($pdo, 'SELECT * FROM roles ORDER BY id DESC')]);
    }

    if ($action === 'role_save') {
        require_csrf();
        $data = json_input();
        $requestedPermissions = is_array($data['permissions'] ?? null)
            ? array_map(static fn($value): string => strtolower(clean_string($value, 60)), $data['permissions'])
            : preg_split('/\s*,\s*/', clean_string($data['permissions'] ?? '', 1000), -1, PREG_SPLIT_NO_EMPTY);
        $allowedPermissions = array_keys(permission_catalog());
        $validatedPermissions = array_values(array_unique(array_filter($requestedPermissions ?: [], static fn(string $value): bool => in_array($value, $allowedPermissions, true))));
        $roleName = required($data, 'name', 'Role name');
        if (normalize_role_name($roleName) === 'administrator') {
            throw new InvalidArgumentException('Administrator is a reserved built-in role.');
        }
        if (!permissions_are_delegable($userPermissions, $validatedPermissions)) {
            respond(['ok' => false, 'message' => 'You cannot grant permissions that you do not hold.'], 403, 'PERMISSION_DELEGATION_DENIED');
        }
        $permissions = implode(',', $validatedPermissions);
        $pdo->prepare('INSERT INTO roles (name,permissions) VALUES (?,?)')->execute([$roleName, $permissions]);
        record_activity($pdo, $userId, 'Created role', $roleName);
        respond(['ok' => true, 'message' => 'Role created successfully.']);
    }

    if ($action === 'profile') {
        $stmt = $pdo->prepare('SELECT id,name,phone,address,language,profile_photo,role,must_change_password,password_changed_at,last_login_at FROM users WHERE id=?');
        $stmt->execute([$userId]);
        respond(['ok' => true, 'data' => $stmt->fetch()]);
    }

    if ($action === 'profile_save') {
        require_csrf();
        $data = json_input();
        $language = in_array($data['language'] ?? '', ['English', 'Bangla'], true) ? $data['language'] : 'English';
        $profilePhoto = image_value($data['profile_photo'] ?? '');
        $pdo->prepare('UPDATE users SET name=?,address=?,language=?,profile_photo=IF(?="",profile_photo,?) WHERE id=?')->execute([required($data, 'name', 'Full name'), clean_string($data['address'] ?? ''), $language, $profilePhoto, $profilePhoto ?: null, $userId]);
        respond(['ok' => true, 'message' => 'Profile updated successfully.']);
    }

    if ($action === 'password_change') {
        require_csrf();
        $data = json_input();
        $currentPassword = (string) ($data['current_password'] ?? '');
        $newPassword = (string) ($data['new_password'] ?? '');
        $confirmPassword = (string) ($data['confirm_password'] ?? '');
        $stmt = $pdo->prepare('SELECT password FROM users WHERE id=? AND status=1');
        $stmt->execute([$userId]);
        $currentHash = (string) $stmt->fetchColumn();
        if ($currentHash === '' || !password_verify($currentPassword, $currentHash)) {
            throw new InvalidArgumentException('Current password is incorrect.');
        }
        if ($newPassword !== $confirmPassword) {
            throw new InvalidArgumentException('New password confirmation does not match.');
        }
        if (!password_is_strong($newPassword)) {
            throw new InvalidArgumentException('Use at least 12 characters with uppercase, lowercase, number and symbol.');
        }
        if (password_verify($newPassword, $currentHash)) {
            throw new InvalidArgumentException('Choose a password different from the current password.');
        }
        $pdo->prepare('UPDATE users SET password=?,must_change_password=0,password_changed_at=NOW(),auth_version=auth_version+1 WHERE id=?')
            ->execute([password_hash($newPassword, PASSWORD_DEFAULT), $userId]);
        $_SESSION['auth_version'] = (int) $pdo->query('SELECT auth_version FROM users WHERE id=' . $userId)->fetchColumn();
        $_SESSION['must_change_password'] = false;
        session_regenerate_id(true);
        record_activity($pdo, $userId, 'Changed password');
        respond(['ok' => true, 'message' => 'Password changed successfully.']);
    }

    if ($action === 'marketplace') {
        $latest = $pdo->query('SELECT * FROM marketplace_requests ORDER BY id DESC LIMIT 1')->fetch();
        $settings = $pdo->query('SELECT business_name,marketplace_status FROM settings WHERE id=1')->fetch();
        respond(['ok' => true, 'request' => $latest ?: null, 'settings' => $settings]);
    }

    if ($action === 'marketplace_request') {
        require_csrf();
        $latest = $pdo->query("SELECT * FROM marketplace_requests WHERE status='pending' ORDER BY id DESC LIMIT 1")->fetch();
        if ($latest) throw new InvalidArgumentException('A marketplace activation request is already pending.');
        $pdo->prepare("INSERT INTO marketplace_requests (status,note) VALUES ('pending',?)")->execute([clean_string(json_input()['note'] ?? '')]);
        $pdo->prepare("UPDATE settings SET marketplace_status='pending' WHERE id=1")->execute();
        respond(['ok' => true, 'message' => 'Marketplace activation request submitted.']);
    }

    if ($action === 'report') {
        $type = clean_string($_GET['type'] ?? 'business', 30);
        $from = date_value($_GET['from'] ?? date('Y-m-01'));
        $to = date_value($_GET['to'] ?? date('Y-m-d'));
        $metrics = [
            'sales' => scalar($pdo, "SELECT COALESCE(SUM(total),0) FROM sales WHERE status='completed' AND sale_date BETWEEN ? AND ?", [$from, $to]),
            'sales_paid' => scalar($pdo, "SELECT COALESCE(SUM(paid),0) FROM sales WHERE status='completed' AND sale_date BETWEEN ? AND ?", [$from, $to]),
            'sales_due' => scalar($pdo, "SELECT COALESCE(SUM(due),0) FROM sales WHERE status='completed' AND sale_date BETWEEN ? AND ?", [$from, $to]),
            'sales_profit' => scalar($pdo, "SELECT COALESCE(SUM((i.price-p.cost_price)*i.qty-i.discount),0) FROM sale_items i JOIN sales s ON s.id=i.sale_id JOIN products p ON p.id=i.product_id WHERE s.status='completed' AND s.sale_date BETWEEN ? AND ?", [$from, $to]),
            'sales_vat' => scalar($pdo, "SELECT COALESCE(SUM(vat),0) FROM sales WHERE status='completed' AND sale_date BETWEEN ? AND ?", [$from, $to]),
            'sales_other' => scalar($pdo, "SELECT COALESCE(SUM(other_cost),0) FROM sales WHERE status='completed' AND sale_date BETWEEN ? AND ?", [$from, $to]),
            'purchases' => scalar($pdo, "SELECT COALESCE(SUM(total),0) FROM purchases WHERE status='completed' AND purchase_date BETWEEN ? AND ?", [$from, $to]),
            'purchases_paid' => scalar($pdo, "SELECT COALESCE(SUM(paid),0) FROM purchases WHERE status='completed' AND purchase_date BETWEEN ? AND ?", [$from, $to]),
            'purchases_due' => scalar($pdo, "SELECT COALESCE(SUM(due),0) FROM purchases WHERE status='completed' AND purchase_date BETWEEN ? AND ?", [$from, $to]),
            'purchase_other' => scalar($pdo, "SELECT COALESCE(SUM(other_cost),0) FROM purchases WHERE status='completed' AND purchase_date BETWEEN ? AND ?", [$from, $to]),
            'expenses' => scalar($pdo, 'SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date BETWEEN ? AND ?', [$from, $to]),
            'damage' => scalar($pdo, 'SELECT COALESCE(SUM(d.qty*p.cost_price),0) FROM damages d JOIN products p ON p.id=d.product_id WHERE d.damage_date BETWEEN ? AND ?', [$from, $to]),
            'sale_returns' => scalar($pdo, 'SELECT COALESCE(SUM(total),0) FROM sale_returns WHERE return_date BETWEEN ? AND ?', [$from, $to]),
            'purchase_returns' => scalar($pdo, 'SELECT COALESCE(SUM(total),0) FROM purchase_returns WHERE return_date BETWEEN ? AND ?', [$from, $to]),
            'service_total' => scalar($pdo, 'SELECT COALESCE(SUM(amount+service_charge),0) FROM services WHERE received_date BETWEEN ? AND ?', [$from, $to]),
            'service_paid' => scalar($pdo, 'SELECT COALESCE(SUM(paid),0) FROM services WHERE received_date BETWEEN ? AND ?', [$from, $to]),
            'service_refund' => scalar($pdo, 'SELECT COALESCE(SUM(refund),0) FROM services WHERE received_date BETWEEN ? AND ?', [$from, $to]),
            'warranty_earned' => scalar($pdo, 'SELECT COALESCE(SUM(charge),0) FROM rmas WHERE received_date BETWEEN ? AND ?', [$from, $to]),
            'warranty_cost' => scalar($pdo, 'SELECT COALESCE(SUM(cost),0) FROM rmas WHERE received_date BETWEEN ? AND ?', [$from, $to]),
            'salary' => scalar($pdo, "SELECT COALESCE(SUM(salary),0) FROM employees WHERE status='active'"),
            'receivable' => scalar($pdo, "SELECT COALESCE(SUM(due),0) FROM sales WHERE status='completed'"),
            'payable' => scalar($pdo, "SELECT COALESCE(SUM(due),0) FROM purchases WHERE status='completed'"),
            'stock_value' => scalar($pdo, 'SELECT COALESCE(SUM(stock*cost_price),0) FROM products'),
            'account_balance' => scalar($pdo, 'SELECT COALESCE(SUM(balance),0) FROM bank_accounts'),
            'investments' => scalar($pdo, 'SELECT COALESCE(SUM(amount),0) FROM investors'),
            'investor_count' => scalar($pdo, 'SELECT COUNT(*) FROM investors'),
            'product_count' => scalar($pdo, 'SELECT COUNT(*) FROM products'),
            'customer_count' => scalar($pdo, "SELECT COUNT(*) FROM contacts WHERE type IN ('customer','both')"),
            'supplier_count' => scalar($pdo, "SELECT COUNT(*) FROM contacts WHERE type IN ('supplier','both')"),
        ];
        $data = match ($type) {
            'sales' => rows($pdo, "SELECT s.id,s.invoice_no reference,s.sale_date date,COALESCE(c.name,'Walk-in Customer') name,COALESCE(u.name,'System') sales_person,s.total,s.paid,s.due,COALESCE((SELECT SUM((i.price-p.cost_price)*i.qty-i.discount) FROM sale_items i JOIN products p ON p.id=i.product_id WHERE i.sale_id=s.id),0) profit FROM sales s LEFT JOIN contacts c ON c.id=s.customer_id LEFT JOIN users u ON u.id=s.created_by WHERE s.sale_date BETWEEN ? AND ? ORDER BY s.sale_date DESC", [$from, $to]),
            'purchases' => rows($pdo, "SELECT p.invoice_no reference,p.purchase_date date,COALESCE(c.name,'General Supplier') name,p.total,p.paid,p.due FROM purchases p LEFT JOIN contacts c ON c.id=p.supplier_id WHERE p.purchase_date BETWEEN ? AND ? ORDER BY p.purchase_date DESC", [$from, $to]),
            'expense' => rows($pdo, "SELECT CONCAT('EXP-',e.id) reference,e.expense_date date,COALESCE(t.name,'Other') name,e.amount total,e.amount paid,0 due FROM expenses e LEFT JOIN expense_types t ON t.id=e.expense_type_id WHERE e.expense_date BETWEEN ? AND ? ORDER BY e.expense_date DESC", [$from, $to]),
            'receivable', 'customer' => rows($pdo, "SELECT c.id contact_id,CONCAT('CUS-',c.id) reference,CURDATE() date,c.name,c.mobile,c.address,COALESCE(SUM(s.total),0) total,COALESCE(SUM(s.paid),0) paid,c.opening_balance+COALESCE(SUM(s.due),0) due FROM contacts c LEFT JOIN sales s ON s.customer_id=c.id AND s.status='completed' WHERE c.type IN ('customer','both') GROUP BY c.id ORDER BY due DESC"),
            'payable' => rows($pdo, "SELECT c.id contact_id,CONCAT('SUP-',c.id) reference,CURDATE() date,c.name,c.mobile,c.address,COALESCE(SUM(p.total),0) total,COALESCE(SUM(p.paid),0) paid,c.opening_balance+COALESCE(SUM(p.due),0) due FROM contacts c LEFT JOIN purchases p ON p.supplier_id=c.id AND p.status='completed' WHERE c.type IN ('supplier','both') GROUP BY c.id ORDER BY due DESC"),
            'stock', 'low_stock' => rows($pdo, "SELECT COALESCE(NULLIF(p.barcode,''),CONCAT('PRD-',p.id)) reference,CURDATE() date,p.name,p.stock total,p.cost_price paid,p.sale_price due FROM products p " . ($type === 'low_stock' ? 'WHERE p.manage_stock=1 AND p.stock<=p.alert_qty ' : '') . 'ORDER BY p.stock ASC'),
            'top_customer' => rows($pdo, "SELECT c.id contact_id,CONCAT('CUS-',c.id) reference,MAX(s.sale_date) date,c.name,c.mobile,c.address,SUM(s.total) total,SUM(s.paid) paid,SUM(s.due) due,COALESCE(SUM((SELECT SUM((i.price-p.cost_price)*i.qty-i.discount) FROM sale_items i JOIN products p ON p.id=i.product_id WHERE i.sale_id=s.id)),0) profit FROM sales s JOIN contacts c ON c.id=s.customer_id WHERE s.sale_date BETWEEN ? AND ? GROUP BY c.id ORDER BY total DESC LIMIT 50", [$from, $to]),
            'alert_stock' => rows($pdo, "SELECT p.id,p.name product_name,b.name brand_name,c.name category_name,p.cost_price,p.sale_price,p.barcode,p.stock,p.alert_qty FROM products p LEFT JOIN brands b ON b.id=p.brand_id LEFT JOIN categories c ON c.id=p.category_id WHERE p.manage_stock=1 AND p.stock<=p.alert_qty ORDER BY p.stock"),
            'sale_product' => rows($pdo, "SELECT s.sale_date date,p.name product_name,SUM(i.qty) qty,p.cost_price unit_cost,AVG(i.price) unit_price,SUM(i.total) total_sales,SUM((i.price-p.cost_price)*i.qty-i.discount) profit FROM sale_items i JOIN sales s ON s.id=i.sale_id JOIN products p ON p.id=i.product_id WHERE s.sale_date BETWEEN ? AND ? GROUP BY s.sale_date,p.id ORDER BY s.sale_date DESC,p.name", [$from, $to]),
            'account_payment' => rows($pdo, "SELECT t.transaction_date date,a.name account_title,t.reference,COALESCE(c.name,'-') contact,COALESCE(u.name,'System') created_by,IF(t.type IN ('out','transfer_out'),t.amount,0) payment,IF(t.type IN ('in','transfer_in'),t.amount,0) received,t.source FROM transactions t LEFT JOIN bank_accounts a ON a.id=t.account_id LEFT JOIN contacts c ON c.id=t.contact_id LEFT JOIN users u ON u.id=t.created_by WHERE t.transaction_date BETWEEN ? AND ? ORDER BY t.transaction_date DESC,t.id DESC", [$from, $to]),
            'transaction' => rows($pdo, "SELECT t.transaction_date date,COALESCE(c.name,a.name,'-') contact,t.source type,IF(t.type IN ('in','transfer_in'),t.amount,0) received,IF(t.type IN ('out','transfer_out'),t.amount,0) payment,t.note,t.reference FROM transactions t LEFT JOIN contacts c ON c.id=t.contact_id LEFT JOIN bank_accounts a ON a.id=t.account_id WHERE t.transaction_date BETWEEN ? AND ? ORDER BY t.transaction_date DESC,t.id DESC", [$from, $to]),
            'stock_detail' => rows($pdo, "SELECT p.id,p.name product,b.name brand,c.name category,COALESCE((SELECT SUM(i.qty) FROM purchase_items i JOIN purchases x ON x.id=i.purchase_id WHERE i.product_id=p.id),0) purchase_qty,COALESCE((SELECT SUM(i.qty) FROM purchase_return_items i WHERE i.product_id=p.id),0) purchase_return_qty,COALESCE((SELECT SUM(i.qty) FROM sale_items i WHERE i.product_id=p.id),0) sold_qty,COALESCE((SELECT SUM(i.qty) FROM sale_return_items i WHERE i.product_id=p.id),0) return_qty,COALESCE((SELECT SUM(d.qty) FROM damages d WHERE d.product_id=p.id),0) damage_qty,p.alert_qty,p.cost_price rate,p.stock,p.stock*p.cost_price stock_value,(SELECT MAX(x.purchase_date) FROM purchase_items i JOIN purchases x ON x.id=i.purchase_id WHERE i.product_id=p.id) purchase_date FROM products p LEFT JOIN brands b ON b.id=p.brand_id LEFT JOIN categories c ON c.id=p.category_id ORDER BY p.name"),
            'stock_list' => rows($pdo, "SELECT p.id,p.name,b.name brand,c.name category,p.stock,p.cost_price,p.stock*p.cost_price stock_value,p.sale_price FROM products p LEFT JOIN brands b ON b.id=p.brand_id LEFT JOIN categories c ON c.id=p.category_id ORDER BY p.name"),
            'expense_type' => rows($pdo, "SELECT t.name expense_type,e.amount,e.note,e.expense_date date,e.id FROM expenses e LEFT JOIN expense_types t ON t.id=e.expense_type_id WHERE e.expense_date BETWEEN ? AND ? ORDER BY e.expense_date DESC", [$from, $to]),
            'service' => rows($pdo, "SELECT s.status,COUNT(*) count,SUM(s.amount+s.service_charge) cost,SUM(s.paid) paid,SUM(s.refund) refund,SUM((s.amount+s.service_charge)-s.paid) due FROM services s WHERE s.received_date BETWEEN ? AND ? GROUP BY s.status ORDER BY s.status", [$from, $to]),
            default => rows($pdo, "SELECT s.invoice_no reference,s.sale_date date,COALESCE(c.name,'Walk-in Customer') name,s.total,s.paid,s.due FROM sales s LEFT JOIN contacts c ON c.id=s.customer_id WHERE s.sale_date BETWEEN ? AND ? ORDER BY s.sale_date DESC LIMIT 100", [$from, $to]),
        };
        respond(['ok' => true, 'metrics' => $metrics, 'data' => $data, 'from' => $from, 'to' => $to]);
    }

    if ($action === 'settings_save') {
        require_csrf();
        $data = json_input();
        $logoData = image_value($data['logo_data'] ?? '');
        $pdo->prepare('UPDATE settings SET business_name=?,phone=?,email=?,address=?,currency=?,invoice_prefix=?,purchase_prefix=?,low_stock_alert=?,invoice_note=?,vat_percentage=?,tin_number=?,tagline=?,website=?,invoice_footer=?,sms_invoice=?,product_code=?,vat_on_product=?,printer_size=?,default_invoice=?,logo_data=IF(?="",logo_data,?) WHERE id=1')
            ->execute([SOFTWARE_NAME, clean_string($data['phone'] ?? '', 30), clean_string($data['email'] ?? '', 120), clean_string($data['address'] ?? ''), clean_string($data['currency'] ?? 'BDT', 10), clean_string($data['invoice_prefix'] ?? 'INV', 10), clean_string($data['purchase_prefix'] ?? 'PUR', 10), money($data['low_stock_alert'] ?? 5), clean_string($data['invoice_note'] ?? ''), money($data['vat_percentage'] ?? 0), clean_string($data['tin_number'] ?? '', 80), clean_string($data['tagline'] ?? '', 180), clean_string($data['website'] ?? DEVELOPER_COMPANY_URL, 180), clean_string($data['invoice_footer'] ?? ''), !empty($data['sms_invoice']) ? 1 : 0, !empty($data['product_code']) ? 1 : 0, !empty($data['vat_on_product']) ? 1 : 0, clean_string($data['printer_size'] ?? '80mm', 20), clean_string($data['default_invoice'] ?? 'Invoice 1', 30), $logoData, $logoData ?: null]);
        record_activity($pdo, $userId, 'Updated business settings');
        respond(['ok' => true, 'message' => 'Business settings updated.']);
    }

    if ($action === 'backup') {
        require_csrf();
        $tables = ['settings','contacts','brands','categories','subcategories','units','products','bank_accounts','sales','sale_items','sale_returns','sale_return_items','purchases','purchase_items','purchase_returns','purchase_return_items','expense_types','expenses','transactions','services','quotations','quotation_items','damages','investors','emis','emi_installments','emi_payments','employees','attendance','attendance_schedules','serials','rmas','cheques','roles','contact_payments','marketplace_requests','sms_purchases'];
        $backup = ['database' => DB_NAME, 'created_at' => date(DATE_ATOM), 'tables' => []];
        foreach ($tables as $table) {
            if ($table === 'services') {
                $backup['tables'][$table] = rows($pdo, 'SELECT id,service_no,customer_id,technician_id,device,issue,serial_no,device_condition,technician_notes,status,amount,paid,service_charge,refund,received_date,delivery_date,note,created_at FROM services');
                continue;
            }
            $backup['tables'][$table] = rows($pdo, "SELECT * FROM `{$table}`");
        }
        record_activity($pdo, $userId, 'Generated browser backup', 'Sensitive credential fields excluded');
        respond(['ok' => true, 'data' => $backup]);
    }

    if ($action === 'admin_data') {
        $users = rows($pdo, 'SELECT id,name,phone,role,status,created_at FROM users ORDER BY id');
        $activity = rows($pdo, 'SELECT l.*,u.name user_name FROM activity_logs l LEFT JOIN users u ON u.id=l.user_id ORDER BY l.id DESC LIMIT 30');
        respond(['ok' => true, 'users' => $users, 'activity' => $activity]);
    }

    if ($action === 'user_save') {
        require_csrf();
        $data = json_input();
        $password = (string) ($data['password'] ?? '');
        if (!password_is_strong($password)) {
            throw new InvalidArgumentException('Use at least 12 characters with uppercase, lowercase, number and symbol.');
        }
        $roleName = clean_string($data['role'] ?? 'Staff', 100);
        $targetPermissions = role_permissions_by_name($pdo, $roleName);
        if ($targetPermissions === null) {
            throw new InvalidArgumentException('Select a valid configured role.');
        }
        if (!permissions_are_delegable($userPermissions, $targetPermissions)) {
            respond(['ok' => false, 'message' => 'You cannot assign a role with permissions that you do not hold.'], 403, 'PERMISSION_DELEGATION_DENIED');
        }
        $pdo->prepare('INSERT INTO users (name,phone,password,role,must_change_password) VALUES (?,?,?,?,1)')->execute([required($data, 'name', 'Name'), required($data, 'phone', 'Phone'), password_hash($password, PASSWORD_DEFAULT), $roleName]);
        record_activity($pdo, $userId, 'Created user', clean_string($data['phone'] ?? '', 30));
        respond(['ok' => true, 'message' => 'User created successfully.']);
    }

    respond(['ok' => false, 'message' => 'Unknown API action.'], 404, 'UNKNOWN_ACTION');
} catch (MalformedJsonException $error) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    respond(['ok' => false, 'message' => $error->getMessage()], 400, 'MALFORMED_JSON');
} catch (ApplicationConfigurationException $error) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    app_log('error', 'Application configuration error', ['message' => $error->getMessage(), 'action' => $action]);
    respond(['ok' => false, 'message' => $error->getMessage()], 503, 'CONFIGURATION_REQUIRED');
} catch (InvalidArgumentException $error) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    respond(['ok' => false, 'message' => $error->getMessage()], 422, 'VALIDATION_ERROR');
} catch (PDOException $error) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    app_log('error', 'Database request failed', pdo_exception_log_context($error, $action));
    if (str_contains($error->getMessage(), 'Duplicate entry')) {
        respond(['ok' => false, 'message' => 'This record already exists.'], 409, 'DUPLICATE_RECORD');
    }
    respond(['ok' => false, 'message' => 'The database could not complete the request.'], 500, 'DATABASE_ERROR');
} catch (Throwable $error) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    app_log('error', 'Unhandled application request failure', ['exception' => get_class($error), 'message' => $error->getMessage(), 'action' => $action]);
    $message = APP_ENV === 'local' ? 'Something went wrong: ' . $error->getMessage() : 'Something went wrong. Please try again or contact support.';
    respond(['ok' => false, 'message' => $message], 500, 'INTERNAL_ERROR');
}
