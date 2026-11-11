<?php

return [
    'pin' => env('TWIN_INSTALL_PIN'),
    'state_path' => storage_path('app/private'),
    'shops' => require __DIR__.'/../database/data/installer-shops.php',
    'modules' => [
        'PHONE_REP' => ['Repair Center', 'Receive devices, assign repairs and track job payments.', 'services.phone_rep'],
        'hr_module' => ['HR & payroll', 'Manage staff, attendance, leave and payroll.', 'hr.enabled'],
        'BARCODE' => ['Barcodes & labels', 'Scan products and print barcode labels.', 'features.barcode'],
        'CAM_BARCODE' => ['Camera scanning', 'Scan barcodes with a device camera. Requires barcodes.', 'features.camera_barcode'],
        'EXPIRE' => ['Expiry dates', 'Track expiry dates by stock batch.', 'features.expiry'],
        'UNIT' => ['Units & conversions', 'Sell by piece, weight, box or other unit.', 'features.unit'],
        'CHEQUE' => ['Cheque management', 'Receive and track cheques.', 'features.cheque'],
        'CUS_TAX' => ['Customer tax invoices', 'Offer customer tax invoices at checkout.', 'features.customer_tax'],
        'COLORS' => ['Appearance settings', 'Customize the POS colors and branding.', 'features.colors'],
        'SMS' => ['SMS', 'Configure SMS notifications and receipt links.', 'sms.enabled'],
        'SUPER_ADMIN' => ['Super Admin panel', 'Enable the separate panel. Its account is created from the server console.', 'features.super_admin'],
        'TAMIL' => ['Tamil help', 'Show Tamil translations in the Help pages.', 'documentation-languages.tamil'],
        'SINHALA' => ['Sinhala help', 'Show Sinhala translations in the Help pages.', 'documentation-languages.sinhala'],
    ],
    'shop_type' => env('TWIN_SHOP_TYPE', 'retail'),
];
