<?php

return [
    'api_url'       => rtrim((string) env('SISTEMA_API_URL'), '/'),
    'front_url'     => rtrim((string) env('SISTEMA_FRONT_URL'), '/'),
    'bridge_secret' => env('SISTEMA_BRIDGE_SECRET'),
];
