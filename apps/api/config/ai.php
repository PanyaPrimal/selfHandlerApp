<?php

return [
    'providers' => ['anthropic', 'openai'],
    'endpoints' => [
        'anthropic' => 'https://api.anthropic.com/v1/messages',
        'openai' => 'https://api.openai.com/v1/responses',
    ],
    'anthropic_version' => '2023-06-01',
    'connect_timeout_seconds' => 5,
    'timeout_seconds' => 20,
    'default_max_output_tokens' => 512,
    'minimum_max_output_tokens' => 128,
    'maximum_max_output_tokens' => 2048,
    'context_project_limit' => 100,
    'context_tag_limit' => 100,
    'confirmation_ttl_minutes' => 10,
    'scope' => 'storage_inbox',
];
