<?php

return [
    'enabled' => filter_var(env('hr_module', env('HR_MODULE', false)), FILTER_VALIDATE_BOOLEAN),
];
