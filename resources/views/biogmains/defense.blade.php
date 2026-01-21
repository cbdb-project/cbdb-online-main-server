@php
//聯合主鍵保留字弱點防禦函式
if (!function_exists('unionPKDef')) {
    function unionPKDef($key) {
        $key = str_replace("/","(slash)",$key);
        //因為反斜線在php有用途, 兩個反斜線代表一個反斜線.
        $key = str_replace("\\","(backslash)",$key);
        $key = str_replace("{","(brackets)",$key);
        $key = str_replace("}","(brackets_r)",$key);
        // URL 特殊字符處理：? 會被解析為查詢字符串開始，# 會被解析為錨點，& 會被解析為參數分隔符
        $key = str_replace("?","(question)",$key);
        $key = str_replace("#","(hash)",$key);
        $key = str_replace("&","(amp)",$key);
        // 複合主鍵分隔符處理：- 是複合主鍵的分隔符，必須編碼以避免解析錯誤
        $key = str_replace("-","(minus)",$key);
        $result = $key;
        return $result;
    }
}

//欄位值解析保留字
if (!function_exists('unionPKDef_decode')) {
    function unionPKDef_decode($key) {
        $key = str_replace("(slash)","/",$key);
        $key = str_replace("(backslash)","\\",$key);
        $key = str_replace("(brackets)","{",$key);
        $key = str_replace("(brackets_r)","}",$key);
        // URL 特殊字符處理
        $key = str_replace("(question)","?",$key);
        $key = str_replace("(hash)","#",$key);
        $key = str_replace("(amp)","&",$key);
        // 複合主鍵分隔符處理
        $key = str_replace("(minus)","-",$key);
        $result = $key;
        return $result;
    }
}

//解決版型衝突專用，欄位值解析保留字。
if (!function_exists('unionPKDef_decode_for_convert')) {
    function unionPKDef_decode_for_convert($key) {
        $key = str_replace("(slash)","/",$key);
        $key = str_replace("(backslash)","\\",$key);
        $key = str_replace("(brackets)(brackets)","{ { ",$key);
        $key = str_replace("(brackets)","{",$key);
        $key = str_replace("(brackets_r)(brackets_r)","} } ",$key);
        $key = str_replace("(brackets_r)","}",$key);
        // URL 特殊字符處理
        $key = str_replace("(question)","?",$key);
        $key = str_replace("(hash)","#",$key);
        $key = str_replace("(amp)","&",$key);
        // 複合主鍵分隔符處理
        $key = str_replace("(minus)","-",$key);
        $result = $key;
        return $result;
    }
}

/**
 * 對複合主鍵字串進行 URL 編碼（用於構建編輯鏈接）
 *
 * 此函數將複合主鍵字串（格式：欄位1-欄位2-欄位3...）中的每個欄位值分別編碼，
 * 然後再用分隔符 - 連接。這樣可以確保分隔符不被編碼，同時欄位值中的特殊字符被正確處理。
 *
 * 與 unionPKDef() 的區別：
 * - unionPKDef(): 對整個字串編碼（包括分隔符 -），適用於單個欄位值
 * - unionPKDef_for_url(): 對每個欄位分別編碼，保留分隔符，適用於複合主鍵
 *
 * @param string $compositePK 原始複合主鍵字串（如 "12345-1-測試/別名-1"）
 * @return string 編碼後的複合主鍵字串（如 "12345-1-測試(slash)別名-1"）
 */
if (!function_exists('unionPKDef_for_url')) {
    function unionPKDef_for_url($compositePK) {
        if (empty($compositePK)) {
            return $compositePK;
        }
        // 將複合主鍵按分隔符 - 分割
        $parts = explode("-", $compositePK);
        // 對每個部分單獨編碼
        foreach ($parts as $key => $value) {
            $parts[$key] = unionPKDef($value);
        }
        // 用分隔符 - 重新連接
        return implode("-", $parts);
    }
}
@endphp
