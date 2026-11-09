<?php
declare(strict_types=1);
foreach (['Schema', 'Allocation', 'StateMachines', 'Energy', 'Invoice', 'Billing', 'BillingPdf', 'Configuration', 'Engine', 'WebView', 'WebAccess'] as $file) {
    require_once __DIR__ . '/' . $file . '.php';
}
