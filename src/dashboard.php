<?php
declare(strict_types=1);

$pageKey = 'dashboard';
$pageTitle = 'Dashboard';
$pageSubtitle = 'Business performance, cash flow and recent activity.';
$pageSection = 'ERP';

// Preserve the accepted baseline dashboard footer exactly in UI-02A.
$shellShowDeveloperCredit = false;

require __DIR__ . '/ui/app-shell.php';
