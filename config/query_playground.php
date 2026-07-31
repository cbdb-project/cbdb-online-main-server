<?php

return [
    'sse_heartbeat_seconds' => env('QUERY_PLAYGROUND_SSE_HEARTBEAT_SECONDS', 10),
    'sse_padding_bytes' => env('QUERY_PLAYGROUND_SSE_PADDING_BYTES', 2048),

    // 見 docs/QUERY_PLAYGROUND_QA_MULTITURN_PLAN.md：QA 模式多輪追問，對話總輪數硬上限
    // （含首輪）；validation 換算 conversation_history 陣列筆數上限為 qa_max_turns - 1。
    'qa_max_turns' => env('QUERY_PLAYGROUND_QA_MAX_TURNS', 5),
    // 每個登入使用者每分鐘可呼叫 answer-from-nl／answer-from-nl-stream 的次數上限。
    'qa_rate_limit_per_minute' => env('QUERY_PLAYGROUND_QA_RATE_LIMIT_PER_MINUTE', 10),
    // conversation_history 內所有 summary 加總字元數上限（防禦性保險，正常情況下
    // qa_max_turns - 1 筆 summary 不可能超過此值，除非 request 被竄改）。
    'qa_history_char_limit' => env('QUERY_PLAYGROUND_QA_HISTORY_CHAR_LIMIT', 6000),
];
