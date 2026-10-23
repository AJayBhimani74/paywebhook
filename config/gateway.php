<?php

return [
    'url' => env('GATEWAY_URL', 'http://127.0.0.1:8000/fake-gateway'),
    'secret' => env('GATEWAY_WEBHOOK_SECRET', 'whsec_local_demo_secret'),
    'tolerance' => 300,
    'simulator' => env('GATEWAY_SIMULATOR', false),
];
