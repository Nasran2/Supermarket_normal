<?php

return [
    'enabled' => filter_var(env('SMS', false), FILTER_VALIDATE_BOOLEAN),
    'username' => env('SMS_USERNAME', ''),
    'password' => env('SMS_PASSWORD', ''),
    'admin_numbers' => env('SMS_ADMIN_NUMBERS', ''),
    'cheque_reminder_time' => env('SMS_CHEQUE_REMINDER_TIME', '08:00'),
    'types' => [
        'RECEIPT' => [
            'label' => 'Sale receipt', 'description' => 'Let staff send the bill from the receipt popup or sale details.', 'icon' => 'receipt-text', 'enabled' => true,
            'template' => 'Thank you for shopping at {business}. Invoice: {invoice} Total: {currency} {total} Paid: {paid} Due: {due}. View bill: {receipt_url}',
            'tokens' => ['name', 'business', 'invoice', 'total', 'paid', 'due', 'currency', 'receipt_url'],
        ],
        'DUE' => [
            'label' => 'Due reminder', 'description' => 'Send an outstanding balance reminder to one customer or many.', 'icon' => 'history', 'enabled' => true,
            'template' => 'Dear {name}, your outstanding balance at {business} is {currency} {due}. Please arrange payment. Thank you.',
            'tokens' => ['name', 'business', 'due', 'currency'],
        ],
        'CUSTOM' => [
            'label' => 'Promotions & custom messages', 'description' => 'Compose individual or bulk messages from the SMS workspace.', 'icon' => 'send', 'enabled' => true,
            'template' => 'Hello {name}, discover our latest offers at {business}. Thank you for shopping with us!',
            'tokens' => ['name', 'business', 'due', 'currency'],
        ],
        'PAYMENT' => [
            'label' => 'Due-payment confirmation', 'description' => 'Automatically notify the customer when an invoice or account due payment is recorded.', 'icon' => 'wallet', 'enabled' => true,
            'template' => 'Dear {name}, {business} received {currency} {amount} by {method}. {payment_status}. Remaining account balance: {currency} {due}. Thank you.',
            'tokens' => ['name', 'business', 'amount', 'due', 'invoice', 'method', 'payment_status', 'date', 'currency', 'receipt_url'],
        ],
        'CHEQUE' => [
            'label' => 'Admin cheque reminder', 'description' => 'Send daily due-soon and overdue cheque alerts to your admin numbers.', 'icon' => 'landmark', 'enabled' => true,
            'template' => 'Cheque reminder: {cheque_number} ({cheque_type}) {bank}, {currency} {amount}, due {date}{overdue}. {parties}. — {business}',
            'tokens' => ['business', 'name', 'cheque_number', 'cheque_type', 'bank', 'amount', 'date', 'overdue', 'parties', 'currency'],
        ],
        'CHEQUE_CUSTOMER' => [
            'label' => 'Customer cheque reminder', 'description' => 'Optionally remind the customer about their pending cheque. Own business cheques are excluded.', 'icon' => 'contact', 'enabled' => false,
            'template' => 'Dear {name}, your cheque {cheque_number} for {currency} {amount} at {business} is due on {date}{overdue}. Please ensure funds are available. Thank you.',
            'tokens' => ['business', 'name', 'cheque_number', 'cheque_type', 'bank', 'amount', 'date', 'overdue', 'parties', 'currency'],
        ],
    ],
];
