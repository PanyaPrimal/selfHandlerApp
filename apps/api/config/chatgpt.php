<?php

return [
    'url' => env('CHATGPT_BRIDGE_URL'),
    'token' => env('CHATGPT_BRIDGE_TOKEN', hash_hmac('sha256', 'selfhandler-chatgpt-bridge', (string) env('APP_KEY'))),
];
