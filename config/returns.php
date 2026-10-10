<?php

return [
    'sales_returns_enabled' => ['Sales returns enabled', 'checkbox', true, 'required|boolean'],
    'purchase_returns_enabled' => ['Purchase returns enabled', 'checkbox', true, 'required|boolean'],
    'return_default_stock_action' => ['Default stock action', 'choice', 'RESTOCK', 'required|in:RESTOCK,WRITEOFF,SUPPLIER'],
    'return_default_method' => ['Default refund method', 'payment', null, 'nullable|integer|exists:payment_methods,id'],
    'return_require_reason' => ['Require return reason', 'checkbox', true, 'required|boolean'],
    'return_approval_above' => ['Manager approval above (0 disables)', 'number', '0', 'required|numeric|min:0|max:999999999999|decimal:0,2'],
    'return_allow_anonymous' => ['Allow anonymous sale returns', 'checkbox', true, 'required|boolean'],
    'return_apply_customer_due' => ['Allow return credit to customer due', 'checkbox', true, 'required|boolean'],
    'return_customer_search' => ['Allow customer search for due allocation', 'checkbox', true, 'required|boolean'],
    'return_allow_supplier' => ['Allow return to supplier', 'checkbox', true, 'required|boolean'],
    'return_fee_policy' => ['Payment charge refund policy', 'choice', 'NONE', 'required|in:NONE,FULL,PRO_RATA'],
    'return_auto_print' => ['Automatically print return receipt', 'checkbox', false, 'required|boolean'],
];
