@php
//聯合主鍵保留字弱點防禦函式
function unionPKDef($key) {
    $key = str_replace("/","(slash)",$key);
    //因為反斜線在php有用途, 兩個反斜線代表一個反斜線.
    $key = str_replace("\\","(backslash)",$key);
    $key = str_replace("{","(brackets)",$key);
    $key = str_replace("}","(brackets_r)",$key);
    $result = $key;
    return $result;
}

//欄位值解析保留字
function unionPKDef_decode($key) {
    $key = str_replace("(slash)","/",$key);
    $key = str_replace("(backslash)","\\",$key);
    $key = str_replace("(brackets)","{",$key);
    $key = str_replace("(brackets_r)","}",$key);
    $result = $key;
    return $result;
}

//解決版型衝突專用，欄位值解析保留字。
function unionPKDef_decode_for_convert($key) {
    $key = str_replace("(slash)","/",$key);
    $key = str_replace("(backslash)","\\",$key);
    $key = str_replace("(brackets)(brackets)","{ { ",$key);
    $key = str_replace("(brackets)","{",$key);
    $key = str_replace("(brackets_r)(brackets_r)","} } ",$key);
    $key = str_replace("(brackets_r)","}",$key);
    $result = $key;
    return $result;
}
@endphp
