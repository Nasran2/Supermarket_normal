<?php

return [
    'business_name' => ['Shop / business name', 'text', '', 'sometimes|required|string|max:150'],
    'legal_name' => ['Legal name (optional)', 'text', '', 'sometimes|nullable|string|max:150'],
    'tax_number' => ['Shop TIN / VAT number', 'text', '', 'sometimes|nullable|string|max:100'],
    'address' => ['Shop address', 'textarea', '', 'sometimes|nullable|string|max:500'],
    'city' => ['City', 'text', '', 'sometimes|nullable|string|max:150'],
    'phone' => ['Shop telephone', 'text', '', 'sometimes|nullable|string|max:40'],
    'vat_rate' => ['VAT rate (%)', 'number', 18, 'required|numeric|min:0|max:100|decimal:0,2'],
    'vat_price_mode' => ['Selling prices', 'choice', 'INCLUSIVE', 'required|in:INCLUSIVE,EXCLUSIVE'],
    'tax_invoice_note' => ['Tax invoice note (optional)', 'textarea', '', 'sometimes|nullable|string|max:1000'],
];
