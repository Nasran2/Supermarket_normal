<?php

return ['customer_tax' => filter_var(env('CUS_TAX', false), FILTER_VALIDATE_BOOLEAN), 'expiry' => (bool) env('EXPIRE', false), 'barcode' => (bool) env('BARCODE', false),
    'camera_barcode' => filter_var(env('CAM_BARCODE', false), FILTER_VALIDATE_BOOLEAN),
    'cheque' => (bool) env('CHEQUE', false), 'unit' => (bool) env('UNIT', false), 'scode' => (bool) env('SCODE', false), 'colors' => (bool) env('COLORS', false), 'super_admin' => filter_var(env('SUPER_ADMIN', false), FILTER_VALIDATE_BOOLEAN)];
