<?php

return [
    'cbdb' => [
        'enabled' => env('MCP_ENABLED', true),
        'max_limit' => (int) env('MCP_MAX_LIMIT', 100),
        'rate_limit_per_minute' => (int) env('MCP_RATE_LIMIT_PER_MINUTE', 120),
        'require_token_abilities' => env('MCP_REQUIRE_TOKEN_ABILITIES', true),
        // ⚠️ 這個值同時是「簽發 token 時的允許／預設能力」（見 App\Support\ApiTokenAbilities）
        // 與「MCP 端點的准入判定」（見 App\Http\Middleware\EnsureMcpAbility）。
        // 改動它會讓所有既存 token 立刻失去 MCP 權限——它們帶的是舊字串。token 還是 ['*']
        // 的年代改這個值是無害的（通配滿足任何能力），自 P1-4 收斂之後就不是了。
        'required_ability' => env('MCP_REQUIRED_ABILITY', 'mcp:read'),
        'allowed_tables' => array_values(array_unique(array_filter(array_map(
            static fn ($table): string => trim((string) $table),
            explode(',', (string) env('MCP_ALLOWED_TABLES', implode(',', array_keys(config('codes.tables', [])))))
        )))),
    ],
];
