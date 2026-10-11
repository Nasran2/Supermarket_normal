<?php

// Defaults preserve the existing bill layout. Newly supported contact details
// appear when filled in; currency code remains optional alongside the symbol.
return [
    'business' => [
        'logo' => ['setting' => 'show_logo_receipt', 'default' => true],
        'logo_secondary' => ['setting' => 'show_second_logo_receipt', 'default' => true],
        'business_name' => ['setting' => 'show_business_name_receipt', 'default' => true],
        'legal_name' => ['setting' => 'show_legal_name_receipt', 'default' => true],
        'address' => ['setting' => 'show_address_receipt', 'default' => true],
        'city' => ['setting' => 'show_city_receipt', 'default' => true],
        'phone' => ['setting' => 'show_phone_receipt', 'default' => true],
        'mobile' => ['setting' => 'show_mobile_receipt', 'default' => true],
        'whatsapp' => ['setting' => 'show_whatsapp_receipt', 'default' => true],
        'email' => ['setting' => 'show_email_receipt', 'default' => true],
        'website' => ['setting' => 'show_website_receipt', 'default' => true],
        'registration_number' => ['setting' => 'show_registration_number_receipt', 'default' => true],
        'tax_number' => ['setting' => 'show_tax_number_receipt', 'default' => true],
        'currency' => ['setting' => 'show_currency_receipt', 'default' => false],
        'currency_symbol' => ['setting' => 'show_currency_symbol_receipt', 'default' => true],
    ],
    'receipt' => [
        'receipt_footer' => ['setting' => 'show_receipt_footer', 'default' => true],
        'receipt_message' => ['setting' => 'show_receipt_message', 'default' => true],
    ],
];
