<?php

return [
    'ta' => json_decode(file_get_contents(__DIR__.'/../resources/documentation/ta.json'), true, 512, JSON_THROW_ON_ERROR),
    'si' => json_decode(file_get_contents(__DIR__.'/../resources/documentation/si.json'), true, 512, JSON_THROW_ON_ERROR),
];
