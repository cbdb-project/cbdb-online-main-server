<?php

return [
    'cbdb' => [
        'enabled' => env('MCP_ENABLED', true),
        'max_limit' => (int) env('MCP_MAX_LIMIT', 100),
        'rate_limit_per_minute' => (int) env('MCP_RATE_LIMIT_PER_MINUTE', 120),
        'require_token_abilities' => env('MCP_REQUIRE_TOKEN_ABILITIES', true),
        'required_ability' => env('MCP_REQUIRED_ABILITY', 'mcp:read'),
        'allowed_tables' => array_values(array_unique(array_filter(array_map(
            static fn ($table): string => trim((string) $table),
            explode(',', (string) env('MCP_ALLOWED_TABLES', implode(',', array_keys(config('codes.tables', [])))))
        )))),
    ],
];
