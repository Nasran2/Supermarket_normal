<?php

return [
    'expiry_default_tracking' => ['Track expiry on new products', 'checkbox', false, 'required|boolean'],
    'expiry_selection_mode' => ['When a product has several expiry dates', 'choice', 'ASK', 'required|in:ASK,FEFO'],
    'expiry_alert_days' => ['Expiry alert days', 'number', 45, 'required|integer|min:1|max:3650'],
    'expiry_urgent_days' => ['Urgent expiry days', 'number', 7, 'required|integer|min:0|max:3650|lte:expiry_alert_days'],
    'expiry_block_expired' => ['Block expired sales', 'checkbox', true, 'required|boolean'],
    'expiry_allow_override' => ['Allow manager to confirm an expired sale', 'checkbox', false, 'required|boolean'],
    'expiry_show_pos' => ['Show expiry in POS', 'checkbox', true, 'required|boolean'],
    'expiry_show_receipt' => ['Show expiry date on receipt', 'checkbox', false, 'required|boolean'],
    'expiry_dashboard' => ['Show dashboard expiry alerts', 'checkbox', true, 'required|boolean'],
    'expiry_dashboard_limit' => ['Maximum dashboard rows', 'number', 10, 'required|integer|min:1|max:50'],
    'expiry_purchase_required' => ['Require expiry on purchases', 'checkbox', true, 'required|boolean'],
    'expiry_batch_enabled' => ['Use batch numbers', 'checkbox', true, 'required|boolean'],
    'expiry_unknown_return' => ['Allow restock without known expiry', 'checkbox', false, 'required|boolean'],
];
