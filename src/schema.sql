SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;


CREATE TABLE IF NOT EXISTS schema_migrations (
    migration_id VARCHAR(120) PRIMARY KEY,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    phone VARCHAR(30) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role VARCHAR(40) NOT NULL DEFAULT 'Admin',
    address VARCHAR(255) DEFAULT '',
    language VARCHAR(20) NOT NULL DEFAULT 'English',
    profile_photo LONGTEXT NULL,
    status TINYINT(1) NOT NULL DEFAULT 1,
    must_change_password TINYINT(1) NOT NULL DEFAULT 1,
    auth_version INT UNSIGNED NOT NULL DEFAULT 1,
    password_changed_at TIMESTAMP NULL,
    last_login_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
    id TINYINT UNSIGNED PRIMARY KEY DEFAULT 1,
    business_name VARCHAR(160) NOT NULL DEFAULT 'VibRetail',
    phone VARCHAR(30) DEFAULT '',
    email VARCHAR(120) DEFAULT '',
    address VARCHAR(255) DEFAULT '',
    currency VARCHAR(10) NOT NULL DEFAULT 'BDT',
    invoice_prefix VARCHAR(10) NOT NULL DEFAULT 'INV',
    purchase_prefix VARCHAR(10) NOT NULL DEFAULT 'PUR',
    low_stock_alert DECIMAL(12,2) NOT NULL DEFAULT 5,
    invoice_note VARCHAR(255) DEFAULT 'Thank you for your business.',
    vat_percentage DECIMAL(7,2) NOT NULL DEFAULT 0,
    tin_number VARCHAR(80) DEFAULT '',
    tagline VARCHAR(180) DEFAULT '',
    website VARCHAR(180) DEFAULT '',
    invoice_footer VARCHAR(255) DEFAULT '',
    sms_invoice TINYINT(1) NOT NULL DEFAULT 1,
    product_code TINYINT(1) NOT NULL DEFAULT 0,
    vat_on_product TINYINT(1) NOT NULL DEFAULT 0,
    printer_size VARCHAR(20) NOT NULL DEFAULT '80mm',
    default_invoice VARCHAR(30) NOT NULL DEFAULT 'Invoice 1',
    marketplace_status VARCHAR(30) NOT NULL DEFAULT 'inactive',
    sms_balance INT UNSIGNED NOT NULL DEFAULT 0,
    logo_data MEDIUMTEXT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contacts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type ENUM('customer','supplier','both') NOT NULL DEFAULT 'customer',
    name VARCHAR(160) NOT NULL,
    mobile VARCHAR(30) NOT NULL,
    email VARCHAR(120) DEFAULT '',
    address VARCHAR(255) DEFAULT '',
    contact_person VARCHAR(120) DEFAULT '',
    opening_balance DECIMAL(14,2) NOT NULL DEFAULT 0,
    advance_balance DECIMAL(14,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_contacts_type (type),
    INDEX idx_contacts_mobile (mobile)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS brands (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL UNIQUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL UNIQUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS subcategories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id INT UNSIGNED NULL,
    name VARCHAR(120) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_subcategory_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS units (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL UNIQUE,
    short_name VARCHAR(20) NOT NULL DEFAULT 'pcs',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS products (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(180) NOT NULL,
    brand_id INT UNSIGNED NULL,
    category_id INT UNSIGNED NULL,
    subcategory_id INT UNSIGNED NULL,
    unit_id INT UNSIGNED NULL,
    sku VARCHAR(80) DEFAULT '',
    barcode VARCHAR(100) DEFAULT '',
    stock DECIMAL(14,2) NOT NULL DEFAULT 0,
    cost_price DECIMAL(14,2) NOT NULL DEFAULT 0,
    sale_price DECIMAL(14,2) NOT NULL DEFAULT 0,
    dealer_price DECIMAL(14,2) NOT NULL DEFAULT 0,
    alert_qty DECIMAL(14,2) NOT NULL DEFAULT 5,
    warranty_months INT UNSIGNED NOT NULL DEFAULT 0,
    manage_stock TINYINT(1) NOT NULL DEFAULT 1,
    image_data MEDIUMTEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_product_barcode (barcode),
    INDEX idx_product_name (name),
    CONSTRAINT fk_product_brand FOREIGN KEY (brand_id) REFERENCES brands(id) ON DELETE SET NULL,
    CONSTRAINT fk_product_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
    CONSTRAINT fk_product_subcategory FOREIGN KEY (subcategory_id) REFERENCES subcategories(id) ON DELETE SET NULL,
    CONSTRAINT fk_product_unit FOREIGN KEY (unit_id) REFERENCES units(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bank_accounts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    account_no VARCHAR(80) DEFAULT '',
    bank_name VARCHAR(120) DEFAULT '',
    balance DECIMAL(14,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sales (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_no VARCHAR(40) NOT NULL UNIQUE,
    customer_id INT UNSIGNED NULL,
    account_id INT UNSIGNED NULL,
    sale_date DATE NOT NULL,
    subtotal DECIMAL(14,2) NOT NULL DEFAULT 0,
    discount DECIMAL(14,2) NOT NULL DEFAULT 0,
    vat DECIMAL(14,2) NOT NULL DEFAULT 0,
    other_cost DECIMAL(14,2) NOT NULL DEFAULT 0,
    total DECIMAL(14,2) NOT NULL DEFAULT 0,
    paid DECIMAL(14,2) NOT NULL DEFAULT 0,
    due DECIMAL(14,2) NOT NULL DEFAULT 0,
    note TEXT NULL,
    status ENUM('completed','returned','cancelled') NOT NULL DEFAULT 'completed',
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_sale_customer FOREIGN KEY (customer_id) REFERENCES contacts(id) ON DELETE SET NULL,
    CONSTRAINT fk_sale_account FOREIGN KEY (account_id) REFERENCES bank_accounts(id) ON DELETE SET NULL,
    CONSTRAINT fk_sale_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sale_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sale_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    qty DECIMAL(14,2) NOT NULL,
    price DECIMAL(14,2) NOT NULL,
    discount DECIMAL(14,2) NOT NULL DEFAULT 0,
    total DECIMAL(14,2) NOT NULL,
    warranty_months INT UNSIGNED NOT NULL DEFAULT 0,
    CONSTRAINT fk_sale_item_sale FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
    CONSTRAINT fk_sale_item_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS purchases (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_no VARCHAR(40) NOT NULL UNIQUE,
    supplier_id INT UNSIGNED NULL,
    account_id INT UNSIGNED NULL,
    purchase_date DATE NOT NULL,
    subtotal DECIMAL(14,2) NOT NULL DEFAULT 0,
    discount DECIMAL(14,2) NOT NULL DEFAULT 0,
    other_cost DECIMAL(14,2) NOT NULL DEFAULT 0,
    total DECIMAL(14,2) NOT NULL DEFAULT 0,
    paid DECIMAL(14,2) NOT NULL DEFAULT 0,
    due DECIMAL(14,2) NOT NULL DEFAULT 0,
    note TEXT NULL,
    status ENUM('completed','returned','cancelled') NOT NULL DEFAULT 'completed',
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_purchase_supplier FOREIGN KEY (supplier_id) REFERENCES contacts(id) ON DELETE SET NULL,
    CONSTRAINT fk_purchase_account FOREIGN KEY (account_id) REFERENCES bank_accounts(id) ON DELETE SET NULL,
    CONSTRAINT fk_purchase_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS purchase_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    purchase_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    qty DECIMAL(14,2) NOT NULL,
    cost_price DECIMAL(14,2) NOT NULL,
    sale_price DECIMAL(14,2) NOT NULL DEFAULT 0,
    total DECIMAL(14,2) NOT NULL,
    warranty_months INT UNSIGNED NOT NULL DEFAULT 0,
    CONSTRAINT fk_purchase_item_purchase FOREIGN KEY (purchase_id) REFERENCES purchases(id) ON DELETE CASCADE,
    CONSTRAINT fk_purchase_item_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS expense_types (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL UNIQUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS expenses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    expense_type_id INT UNSIGNED NULL,
    account_id INT UNSIGNED NULL,
    expense_date DATE NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    note VARCHAR(255) DEFAULT '',
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_expense_type FOREIGN KEY (expense_type_id) REFERENCES expense_types(id) ON DELETE SET NULL,
    CONSTRAINT fk_expense_account FOREIGN KEY (account_id) REFERENCES bank_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transactions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id INT UNSIGNED NULL,
    contact_id INT UNSIGNED NULL,
    created_by INT UNSIGNED NULL,
    transaction_date DATE NOT NULL,
    type ENUM('in','out','transfer_in','transfer_out') NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    source VARCHAR(50) NOT NULL DEFAULT 'manual',
    reference_id INT UNSIGNED NULL,
    reference VARCHAR(100) DEFAULT '',
    note VARCHAR(255) DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_transaction_account FOREIGN KEY (account_id) REFERENCES bank_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS services (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    service_no VARCHAR(40) NOT NULL UNIQUE,
    customer_id INT UNSIGNED NULL,
    technician_id INT UNSIGNED NULL,
    device VARCHAR(160) NOT NULL,
    issue TEXT NOT NULL,
    serial_no VARCHAR(160) DEFAULT '',
    device_password TEXT NULL,
    device_condition VARCHAR(180) DEFAULT '',
    technician_notes TEXT NULL,
    status ENUM('received','working','ready','delivered','cancelled') NOT NULL DEFAULT 'received',
    amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    paid DECIMAL(14,2) NOT NULL DEFAULT 0,
    service_charge DECIMAL(14,2) NOT NULL DEFAULT 0,
    refund DECIMAL(14,2) NOT NULL DEFAULT 0,
    received_date DATE NOT NULL,
    delivery_date DATE NULL,
    note VARCHAR(255) DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_service_customer FOREIGN KEY (customer_id) REFERENCES contacts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quotations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    quote_no VARCHAR(40) NOT NULL UNIQUE,
    customer_id INT UNSIGNED NULL,
    bill_to VARCHAR(180) DEFAULT '',
    quote_date DATE NOT NULL,
    valid_until DATE NULL,
    total DECIMAL(14,2) NOT NULL DEFAULT 0,
    profit DECIMAL(14,2) NOT NULL DEFAULT 0,
    status ENUM('draft','sent','accepted','rejected') NOT NULL DEFAULT 'draft',
    note VARCHAR(255) DEFAULT '',
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_quote_customer FOREIGN KEY (customer_id) REFERENCES contacts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quotation_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    quotation_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    qty DECIMAL(14,2) NOT NULL,
    price DECIMAL(14,2) NOT NULL,
    total DECIMAL(14,2) NOT NULL,
    CONSTRAINT fk_quote_item_quote FOREIGN KEY (quotation_id) REFERENCES quotations(id) ON DELETE CASCADE,
    CONSTRAINT fk_quote_item_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS damages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reference_no VARCHAR(40) NOT NULL UNIQUE,
    product_id INT UNSIGNED NOT NULL,
    serial_no VARCHAR(160) DEFAULT '',
    qty DECIMAL(14,2) NOT NULL,
    purchase_price DECIMAL(14,2) NOT NULL DEFAULT 0,
    total DECIMAL(14,2) NOT NULL DEFAULT 0,
    reason VARCHAR(255) DEFAULT '',
    damage_date DATE NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_damage_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS investors (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    mobile VARCHAR(30) DEFAULT '',
    amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    join_date DATE NOT NULL,
    note VARCHAR(255) DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS emis (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NULL,
    sale_id INT UNSIGNED NULL,
    total DECIMAL(14,2) NOT NULL,
    down_payment DECIMAL(14,2) NOT NULL DEFAULT 0,
    installment_count INT UNSIGNED NOT NULL DEFAULT 1,
    installment_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    frequency ENUM('monthly','weekly','daily') NOT NULL DEFAULT 'monthly',
    start_date DATE NOT NULL,
    status ENUM('active','completed','overdue','cancelled') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_emi_customer FOREIGN KEY (customer_id) REFERENCES contacts(id) ON DELETE SET NULL,
    CONSTRAINT fk_emi_sale FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS emi_payments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    emi_id INT UNSIGNED NOT NULL,
    payment_date DATE NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    note VARCHAR(255) DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_emi_payment FOREIGN KEY (emi_id) REFERENCES emis(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS emi_installments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    emi_id INT UNSIGNED NOT NULL,
    installment_no INT UNSIGNED NOT NULL,
    due_date DATE NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    paid DECIMAL(14,2) NOT NULL DEFAULT 0,
    paid_date DATE NULL,
    status ENUM('due','partial','paid') NOT NULL DEFAULT 'due',
    note VARCHAR(255) DEFAULT '',
    UNIQUE KEY uq_emi_installment (emi_id, installment_no),
    CONSTRAINT fk_emi_installment_plan FOREIGN KEY (emi_id) REFERENCES emis(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS employees (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    mobile VARCHAR(30) DEFAULT '',
    designation VARCHAR(100) DEFAULT '',
    role_id INT UNSIGNED NULL,
    salary DECIMAL(14,2) NOT NULL DEFAULT 0,
    salary_day TINYINT UNSIGNED NOT NULL DEFAULT 1,
    manage_business TINYINT(1) NOT NULL DEFAULT 0,
    is_sr TINYINT(1) NOT NULL DEFAULT 0,
    join_date DATE NOT NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attendance (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id INT UNSIGNED NOT NULL,
    attendance_date DATE NOT NULL,
    status ENUM('present','absent','leave','late') NOT NULL DEFAULT 'present',
    check_in TIME NULL,
    check_out TIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_attendance (employee_id, attendance_date),
    CONSTRAINT fk_attendance_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS serials (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id INT UNSIGNED NOT NULL,
    serial_no VARCHAR(160) NOT NULL UNIQUE,
    status ENUM('stock','sold','rma','returned','damaged') NOT NULL DEFAULT 'stock',
    reference_type VARCHAR(30) DEFAULT '',
    reference_id INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_serial_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sale_returns (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reference VARCHAR(40) NOT NULL UNIQUE,
    sale_id INT UNSIGNED NOT NULL,
    customer_id INT UNSIGNED NULL,
    account_id INT UNSIGNED NULL,
    return_date DATE NOT NULL,
    total DECIMAL(14,2) NOT NULL DEFAULT 0,
    refund DECIMAL(14,2) NOT NULL DEFAULT 0,
    note VARCHAR(255) DEFAULT '',
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_sale_return_sale FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE RESTRICT,
    CONSTRAINT fk_sale_return_customer FOREIGN KEY (customer_id) REFERENCES contacts(id) ON DELETE SET NULL,
    CONSTRAINT fk_sale_return_account FOREIGN KEY (account_id) REFERENCES bank_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sale_return_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sale_return_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    qty DECIMAL(14,2) NOT NULL,
    price DECIMAL(14,2) NOT NULL,
    total DECIMAL(14,2) NOT NULL,
    CONSTRAINT fk_sale_return_item_return FOREIGN KEY (sale_return_id) REFERENCES sale_returns(id) ON DELETE CASCADE,
    CONSTRAINT fk_sale_return_item_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS purchase_returns (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reference VARCHAR(40) NOT NULL UNIQUE,
    purchase_id INT UNSIGNED NOT NULL,
    supplier_id INT UNSIGNED NULL,
    account_id INT UNSIGNED NULL,
    return_date DATE NOT NULL,
    total DECIMAL(14,2) NOT NULL DEFAULT 0,
    received DECIMAL(14,2) NOT NULL DEFAULT 0,
    note VARCHAR(255) DEFAULT '',
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_purchase_return_purchase FOREIGN KEY (purchase_id) REFERENCES purchases(id) ON DELETE RESTRICT,
    CONSTRAINT fk_purchase_return_supplier FOREIGN KEY (supplier_id) REFERENCES contacts(id) ON DELETE SET NULL,
    CONSTRAINT fk_purchase_return_account FOREIGN KEY (account_id) REFERENCES bank_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS purchase_return_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    purchase_return_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    qty DECIMAL(14,2) NOT NULL,
    cost_price DECIMAL(14,2) NOT NULL,
    total DECIMAL(14,2) NOT NULL,
    CONSTRAINT fk_purchase_return_item_return FOREIGN KEY (purchase_return_id) REFERENCES purchase_returns(id) ON DELETE CASCADE,
    CONSTRAINT fk_purchase_return_item_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rmas (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rma_no VARCHAR(40) NOT NULL UNIQUE,
    serial_id INT UNSIGNED NULL,
    product_id INT UNSIGNED NULL,
    customer_id INT UNSIGNED NULL,
    issue VARCHAR(255) NOT NULL,
    status ENUM('in_house','in_process','ready','delivered','cancelled') NOT NULL DEFAULT 'in_house',
    received_date DATE NOT NULL,
    delivery_date DATE NULL,
    cost DECIMAL(14,2) NOT NULL DEFAULT 0,
    charge DECIMAL(14,2) NOT NULL DEFAULT 0,
    note VARCHAR(255) DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_rma_serial FOREIGN KEY (serial_id) REFERENCES serials(id) ON DELETE SET NULL,
    CONSTRAINT fk_rma_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL,
    CONSTRAINT fk_rma_customer FOREIGN KEY (customer_id) REFERENCES contacts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cheques (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id INT UNSIGNED NULL,
    contact_id INT UNSIGNED NULL,
    cheque_no VARCHAR(100) NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    issue_date DATE NOT NULL,
    cheque_date DATE NOT NULL,
    type ENUM('receive','payment') NOT NULL DEFAULT 'receive',
    status ENUM('pending','deposited','bounce','cleared') NOT NULL DEFAULT 'pending',
    note VARCHAR(255) DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_cheque_account FOREIGN KEY (account_id) REFERENCES bank_accounts(id) ON DELETE SET NULL,
    CONSTRAINT fk_cheque_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS roles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    permissions TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attendance_schedules (
    id TINYINT UNSIGNED PRIMARY KEY DEFAULT 1,
    off_days VARCHAR(80) DEFAULT '',
    check_in TIME NOT NULL,
    check_out TIME NOT NULL,
    late_minutes INT UNSIGNED NOT NULL DEFAULT 0,
    absent_minutes INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contact_payments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contact_id INT UNSIGNED NOT NULL,
    account_id INT UNSIGNED NULL,
    payment_date DATE NOT NULL,
    type ENUM('receive','payment') NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    discount DECIMAL(14,2) NOT NULL DEFAULT 0,
    note VARCHAR(255) DEFAULT '',
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_contact_payment_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
    CONSTRAINT fk_contact_payment_account FOREIGN KEY (account_id) REFERENCES bank_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS marketplace_requests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    requested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at TIMESTAMP NULL,
    note VARCHAR(255) DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sms_purchases (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    package_name VARCHAR(80) NOT NULL,
    units INT UNSIGNED NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    account_id INT UNSIGNED NULL,
    status ENUM('completed','cancelled') NOT NULL DEFAULT 'completed',
    purchased_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_sms_purchase_account FOREIGN KEY (account_id) REFERENCES bank_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS activity_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    action VARCHAR(100) NOT NULL,
    details VARCHAR(255) DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_activity_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    login_key CHAR(64) NOT NULL,
    attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    success TINYINT(1) NOT NULL DEFAULT 0,
    INDEX idx_login_attempt_key_time (login_key, attempted_at),
    INDEX idx_login_attempt_cleanup (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS document_sequences (
    document_type VARCHAR(30) PRIMARY KEY,
    sequence_no BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
