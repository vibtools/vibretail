<?php
declare(strict_types=1);

return [
    ['type' => 'link', 'key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'DB'],
    ['type' => 'group', 'label' => 'Customer & Supplier', 'icon' => 'CS', 'items' => [['customer', 'Customer'], ['supplier', 'Supplier']]],
    ['type' => 'group', 'label' => 'Product', 'icon' => 'PR', 'items' => [['product-new', 'New Product'], ['product-list', 'Product List'], ['brand', 'Brand'], ['category', 'Category'], ['subcategory', 'Sub Category', true], ['unit', 'Unit']]],
    ['type' => 'group', 'label' => 'Purchase', 'icon' => 'PU', 'items' => [['purchase-new', 'Create Purchase'], ['purchase-list', 'Purchase List'], ['purchase-return', 'Purchase Return List']]],
    ['type' => 'group', 'label' => 'Sale', 'icon' => 'SL', 'items' => [['sale-new', 'Create Sale'], ['sale-vat', 'Sale With Vat'], ['sale-list', 'Sale List'], ['sale-return', 'Sale Return List']]],
    ['type' => 'group', 'label' => 'Warranty', 'icon' => 'WA', 'items' => [['serial-list', 'Serial List'], ['rma', 'RMA']]],
    ['type' => 'group', 'label' => 'Service', 'icon' => 'SV', 'items' => [['service-new', 'Create Service'], ['service-list', 'Service List'], ['service-report', 'Service Report']]],
    ['type' => 'group', 'label' => 'Quotation', 'icon' => 'QT', 'items' => [['quotation-new', 'Create Quotation'], ['quotation-list', 'Quotation List']]],
    ['type' => 'group', 'label' => 'Damage', 'icon' => 'DM', 'items' => [['damage', 'Add Damage'], ['damage-list', 'Damage List']]],
    ['type' => 'group', 'label' => 'Expense', 'icon' => 'EX', 'items' => [['expense', 'Expense'], ['expense-type', 'Expense Type'], ['expense-report', 'Expense By Type']]],
    ['type' => 'group', 'label' => 'Barcode', 'icon' => 'BC', 'items' => [['barcode', 'Multi Barcode'], ['barcode-single', 'Single Barcode']]],
    ['type' => 'group', 'label' => 'Bank Accounts', 'icon' => 'BK', 'items' => [['bank', 'Bank Accounts'], ['transfer', 'Balance Transfer'], ['cheque', 'Cheque'], ['transactions', 'Transactions']]],
    ['type' => 'group', 'label' => 'Investment', 'icon' => 'IN', 'items' => [['investor', 'Investor List']]],
    ['type' => 'group', 'label' => 'EMI/কিস্তি', 'icon' => 'EM', 'new' => true, 'items' => [['emi-new', 'Create EMI'], ['emi-list', 'EMI List'], ['installment-report', 'Installment Report']]],
    ['type' => 'group', 'label' => 'HRM', 'icon' => 'HR', 'items' => [['team', 'Team'], ['sr-list', 'SR List'], ['attendance', 'Attendance'], ['role', 'Role']]],
    ['type' => 'group', 'label' => 'Report', 'icon' => 'RP', 'report' => true, 'items' => [['business-report', 'Business Report'], ['sales-report', 'Sale Report'], ['top-customer', 'Top Customer'], ['customer-report', 'Customer Report'], ['receivable-report', 'Receivable Report'], ['payable-report', 'Payable Report'], ['low-stock', 'Low Stock Product List'], ['alert-product', 'Alert Product List'], ['sale-product-report', 'Sale Product Report'], ['account-payment-report', 'Account Payment Report'], ['full-expense-report', 'Expense Report'], ['transaction-report', 'Transaction Report'], ['daily-report', 'Daily Report'], ['stock-report', 'Stock Report'], ['stock-list', 'Stock List']]],
    ['type' => 'link', 'key' => 'admin', 'label' => 'Admin', 'icon' => 'AD'],
    ['type' => 'link', 'key' => 'settings', 'label' => 'Business Setting', 'icon' => 'BS'],
    ['type' => 'group', 'label' => 'Marketplace', 'icon' => 'MP', 'new' => true, 'items' => [['marketplace', 'Active Marketplace']]],
];
