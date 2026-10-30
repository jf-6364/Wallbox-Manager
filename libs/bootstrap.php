<?php
declare(strict_types=1);
foreach (['Schema', 'Allocation', 'StateMachines', 'Energy', 'Billing', 'BillingPdf', 'Configuration', 'Engine'] as $file) {
    require_once __DIR__ . '/' . $file . '.php';
}
