<?php

return ['expiry' => (bool) env('EXPIRE', false), 'barcode' => (bool) env('BARCODE', false), 'cheque' => (bool) env('CHEQUE', false), 'unit' => (bool) env('UNIT', false)];
