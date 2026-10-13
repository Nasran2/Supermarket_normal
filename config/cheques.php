<?php

return ['settings' => [
    'cheque_alert_days' => ['Alert / action days before cheque date', 'number', 3, 'required|integer|min:0|max:90'],
    'cheque_allow_customer' => ['Allow customer cheques', 'checkbox', true, 'required|boolean'],
    'cheque_allow_own' => ['Allow own cheques', 'checkbox', true, 'required|boolean'],
    'cheque_allow_third_party' => ['Allow third-party transfers', 'checkbox', true, 'required|boolean'],
    'cheque_allow_multiple' => ['Allow multiple cheques per payment', 'checkbox', true, 'required|boolean'],
    'cheque_require_branch' => ['Require branch', 'checkbox', false, 'required|boolean'],
    'cheque_allow_image' => ['Allow scanned cheque attachments', 'checkbox', true, 'required|boolean'],
    'cheque_dashboard' => ['Show dashboard alerts', 'checkbox', true, 'required|boolean'],
    'cheque_default_return_reason' => ['Default return reason', 'text', '', 'nullable|string|max:255'],
]];
