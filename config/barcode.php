<?php

return [
    'settings' => [
        'barcode_default_type' => ['Default barcode format', 'type', 'CODE128', 'required|in:CODE128,EAN13,EAN8,UPCA'],
        'barcode_auto_generate' => ['Allow automatic generation', 'checkbox', true, 'required|boolean'],
        'barcode_generate_on_create' => ['Auto generate on product create', 'checkbox', false, 'required|boolean'],
        'barcode_prefix' => ['Numeric generation prefix', 'text', '200', 'nullable|regex:/^[0-9]*$/|max:12'],
        'barcode_length' => ['Generated CODE128 length (including prefix)', 'number', 13, 'required|integer|min:4|max:18'],
        'barcode_required' => ['Require a barcode on products', 'checkbox', false, 'required|boolean'],
        'barcode_allow_above_stock' => ['Allow print quantity above stock', 'checkbox', true, 'required|boolean'],
        'barcode_default_quantity' => ['Default sticker quantity', 'number', 1, 'required|integer|min:1|max:10000'],
        'barcode_large_job' => ['Confirm jobs above this label count', 'number', 500, 'required|integer|min:1|max:10000'],
        'barcode_printer_type' => ['Printer type', 'printer', 'LABEL', 'required|in:LABEL,NORMAL,THERMAL,CUSTOM'],
        'barcode_enabled' => ['Enable POS barcode scanning', 'checkbox', true, 'required|boolean'],
        'barcode_scanner_focus' => ['Focus POS search on opening', 'checkbox', true, 'required|boolean'],
    ],
];
