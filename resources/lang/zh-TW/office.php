<?php

return [
    'page_title_index' => '官職實體',
    'page_title_create' => '新增官職',
    'page_title_edit' => '編輯官職',
    'intro' => '把 OFFICE_CODES 與其類型關聯當作單一官職實體管理；寫入走共用 mutation API。',

    'search_placeholder' => '搜尋官職名／拼音／ID',
    'search' => '搜尋',
    'col_id' => 'ID',
    'col_name' => '官職名',
    'col_pinyin' => '拼音',
    'col_dynasty' => '朝代',
    'col_types' => '類型數',
    'col_actions' => '操作',
    'empty_list' => '沒有符合的官職',
    'total_count' => '共 :n 筆',

    'btn_create' => '新增官職',
    'btn_edit' => '編輯',
    'btn_delete' => '刪除',
    'btn_save' => '儲存',
    'btn_cancel' => '取消',
    'btn_back' => '返回列表',

    'field_name' => '官職名（中文）',
    'field_translation' => '英文／翻譯',
    'field_dynasty' => '朝代',
    'field_types' => '官職類型（可多選）',
    'field_source' => '來源（TEXT）',
    'type_add_placeholder' => '搜尋並加入類型節點',
    'source_placeholder' => '搜尋來源文獻',
    'dynasty_placeholder' => '選擇朝代',
    'no_types' => '尚未選擇類型',

    'save_failed' => '儲存失敗',
    'created' => '已新增',
    'updated' => '已更新',
    'deleted' => '已刪除',
    'delete_confirm' => '確定刪除此官職？此動作可於 /operations 復原。',
    'delete_blocked' => '此官職仍被人物任官引用，無法刪除',

    'err_name_required' => '官職名為必填',
    'err_dynasty_invalid' => '請選擇有效朝代',
    'err_types_required' => '至少選擇一個類型',
    'err_source_required' => '請選擇有效來源',
    'err_type_not_found' => '類型節點不存在',
];
