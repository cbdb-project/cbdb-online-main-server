<?php

return [
    'sse_heartbeat_seconds' => env('QUERY_PLAYGROUND_SSE_HEARTBEAT_SECONDS', 10),
    'sse_padding_bytes' => env('QUERY_PLAYGROUND_SSE_PADDING_BYTES', 2048),
];
