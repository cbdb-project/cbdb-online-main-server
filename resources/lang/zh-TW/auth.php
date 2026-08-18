<?php

return [
    'failed'   => '帳號或密碼不正確。',
    'throttle' => '登入嘗試次數過多，請 :seconds 秒後再試。',
    // 註冊／忘記密碼／重設密碼的限流訊息（#1264）。與上面的 throttle 分開：那句字面是登入專用的。
    'throttle_requests' => '嘗試次數過多，請 :seconds 秒後再試。',
    'account_inactive' => '此帳號尚未啟用或已被停用，請聯絡管理員。',
];
