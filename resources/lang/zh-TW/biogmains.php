<?php

return [
    // ─── Common form actions ────────────────────────────────────────
    'save_directly'           => '直接儲存',
    'submit_proposal'         => '提交提案',
    'save_as'                 => '另存新檔',

    // ─── Modification note ─────────────────────────────────────────
    'modification_note_label'       => '修改說明 / 提案理由',
    'modification_note_placeholder' => '請簡述本次修改的原因（直接儲存或提交提案時均會記錄此說明）',
    'modification_note_hint'        => '此說明將記錄於操作歷史中。提交提案時必填，直接儲存時可選填。',

    // ─── Record counts ─────────────────────────────────────────────
    'record_count'            => '共查詢到 :count 筆記錄',
    'total_records'           => '共計 :count 條記錄',
    'no_data_row'             => '暫無數據',

    // ─── Candidate source helper ───────────────────────────────────
    'candidate_source_title'  => '候選出處與頁數',
    'candidate_source_hint'   => '由此選取[出處]頁面中的出處與頁碼資訊',
    'please_fill_source'      => '請填寫[人物 >> 出處]',
    'update_source_success'   => '更新[出處]與[頁數/條目]成功',

    // ─── Common field labels ───────────────────────────────────────
    'sequence'                => '次序',
    'start_year'              => '始年',
    'end_year'                => '終年',
    'pages_entries'           => '頁數/條目',
    'notes_field'             => '註',
    'please_select'           => '請選擇',
    'please_search'           => '請搜尋',
    'actions'                 => '操作',
    'source_field'            => '出處',
    'place_name'              => '地名',
    'year_field'              => '年份',
    'quantity'                => '數量',

    // ─── JS confirm dialogs ────────────────────────────────────────
    'delete_confirm_js'       => "您真的確定要刪除嗎？\n\n請確認！",

    // ─── Audit fields component ────────────────────────────────────
    'audit_created'           => '建檔',
    'audit_updated'           => '更新',
    'audit_display_hint'      => '此為顯示用資訊，不會包含在表單提交中',

    // ─── Person ID display component ──────────────────────────────
    'person_basic_info'       => '人物基本資訊',
    'person_id_colon'         => '人物 ID：',
    'person_name_pinyin'      => '姓名（拼音）：',
    'person_dynasty'          => '朝代：',
    'person_name_chinese'     => '姓名（中文）：',

    // ─── Basic Information ─────────────────────────────────────────
    'basic_info_title'        => '基本資料',
    'basic_info_readonly'     => '基本資料（只讀）',
    // 基本資料編輯器區塊分組標題（block grouping）。
    'block_names'             => '姓名',
    'block_attributes'        => '基本屬性',
    'block_life_dates'        => '生卒年與指數年',
    'block_floruit'           => '在世年（活動年）',
    'block_origin'            => '籍貫與戶籍',
    'block_notes'             => '備註',
    // 建檔/更新顯示連接詞（合併「{使用者} 於 {日期}」；audit_created/audit_updated 沿用上方既有鍵，不重複定義）。
    'audit_at'                => '於',
    // basic_info 網格重設計：派生/指數唯讀子區塊標題與派生全名標籤。
    'derived_auto_tag'        => '自動生成（唯讀，由上方姓名合併）',
    'index_auto_tag'          => '指數年與指數地址（系統依算法定期自動計算，唯讀，無需手動填寫）',
    'full_name_chn'           => '姓名（中）',
    'pinyin_full'             => '拼音',
    'foreign_full'            => '外文全名',
    'rm_full'                 => '羅馬字全名',
    'generate_pinyin_btn'     => '生成拼音',
    'surname_chn'             => '姓',
    'mingzi_chn'              => '名',
    'foreign_surname'         => '外文姓',
    'foreign_mingzi'          => '外文名',
    'foreign_rm_surname'      => '外文羅馬字轉寫姓',
    'foreign_rm_mingzi'       => '外文羅馬字轉寫名',
    'foreign_full_name'       => '外文全名',
    'foreign_rm_full_name'    => '外文羅馬字轉寫姓名',
    'name_auto_hint'          => '此欄位由「姓」和「名」自動合併生成，無需手動填寫',
    'pinyin_auto_hint'        => '此欄位由「Xing」和「Ming」自動合併生成，無需手動填寫',
    'foreign_full_auto_hint'  => '此欄位由「外文名」和「外文姓」自動合併生成（名+姓順序），無需手動填寫',
    'rm_auto_hint'            => '此欄位由「外文羅馬字轉寫姓」和「外文羅馬字轉寫名」自動合併生成，無需手動填寫',
    'auto_calc_hint'          => '此欄位由算法定期自動計算生成，無需手動填寫',
    'index_year_method'       => '指數年推算方法',
    'index_year_source_col'   => '指數年推算來源',
    'index_addr'              => '指數地址',
    'index_addr_type'         => '指數地址類型',
    'death_age_label'         => '享年',
    'range_label'             => '範圍',
    'fl_earliest_notes'       => '在世始年註',
    'fl_latest_notes'         => '在世終年註',
    'choronym_field'          => '郡望',
    'household_field'         => '戶籍',
    'person_name_chn_label'   => '姓名（中）',
    'search_placeholder'      => '搜尋人物 (所有 ü 在拼音中我們都以 v 替代)',
    'all_dynasties_opt'       => '全部朝代',
    'basicinfo_check_alert'   => '訊息提示：要離開視窗了，請您確認[名]和[Ming]是否填寫。',
    'basicinfo_pinyin_alert'  => '訊息提示：「生成拼音」已經完成。',

    // ─── Addresses module ─────────────────────────────────────────
    'addresses_list'          => '地址清單',
    'migration_sequence'      => '遷徙次序',
    'address_type'            => '地址類別',
    'maiden_addr'             => '娘家地址',
    'other_upper_info'        => '其他上層歸屬資訊',

    // ─── Alt Name module ──────────────────────────────────────────
    'altname_list'            => '別名清單',
    'altname_seq_title'       => '別名次序調整',
    'new_sequence'            => '新次序',
    'altname_chinese'         => '別名漢字',
    'altname_pinyin_label'    => '別名拼音',
    'altname_type_code'       => '別名類別代碼',
    'submit_btn'              => '提交',
    'submitted_ok'            => '已提交',
    'submit_failed'           => '提交失敗',
    'non_json_response'       => '非 JSON 回應',
    'network_error'           => '網路或伺服器錯誤',

    // ─── Sources module ───────────────────────────────────────────
    'sources_list'            => '出處清單',
    'primary_source'          => '主要出處',
    'self_biography'          => '本人傳記',
    'wiki_warning'            => '警告',
    'wiki_warning_text'       => '本記錄為批量導入的 Wiki 對照資料，如果修改此記錄，下次導入時會丟失您的修改。請確認是否需要進行手動修改。',
    'options'                 => '選項',
    'page_no'                 => '頁碼',

    // ─── Entries module ───────────────────────────────────────────
    'entries_list'            => '入仕清單',
    'entry_method'            => '入仕法',
    'entry_year_field'        => '入仕年',
    'exam_ranking'            => '科第名次',
    'nth_attempt'             => '第幾舉',
    'arabic_numerals_hint'    => '請填阿拉伯數字(半形/半角)',
    'exam_subject'            => '考試科目',
    'parental_status'         => '父母狀態',
    'location'                => '地點',
    'entry_age'               => '入仕年齡',
    'official_appt'           => '授官',
    'kinship_type'            => '親屬關係類別',
    'relative_field'          => '親戚',
    'social_rel_type'         => '社會關係類別',
    'social_rel_person'       => '社會關係人',
    'social_inst_field'       => '社交機構',

    // ─── Events module ────────────────────────────────────────────
    'events_list'             => '事件清單',
    'event_name'              => '事件名稱',
    'event_role'              => '傳主在該事件中角色',
    'event_year_field'        => '事件發生年',
    'event_reign_year'        => '事件年號',
    'major_event'             => '大事件',
    'not_specified'           => '未詳',

    // ─── Kinship module ───────────────────────────────────────────
    'kinship_list'            => '親屬清單',
    'kinship_relation'        => '親屬關係',
    'relative_name'           => '親戚姓名',
    'paired_kinship'          => '成對親屬關係',
    'no_paired_kinship'       => '無對應親屬關係',
    'reverse_pair_label'      => '互逆配對碼',
    'reverse_pair_hint'       => '系統依關係碼自動雙向同步；預設取建議的反向碼，可手動更正（例：父→子或女、第幾子）。',
    'keep_current_pair'       => '（保持目前反向碼）',
    'kinship_create'          => '新增親屬關係',
    'kinship_edit'            => '編輯親屬關係',
    'kinship_pair_auto_hint'  => '互逆親屬關係（配對）由系統依關係碼自動雙向同步，無需手動填寫。',

    // ─── Texts module ─────────────────────────────────────────────
    'texts_list'              => '著述清單',
    'text_code'               => '著述代碼',
    'text_role'               => '著述角色',
    'book_title_field'        => '書名',

    // ─── Possession module ────────────────────────────────────────
    'possession_list'         => '財產清單',
    'possession_seq_title'    => '財產次序調整',
    'possession_action_field' => '行為（擁有、捐出等）',
    'possession_english'      => '財產（英文描述）',
    'possession_chinese'      => '財產（中文描述）',
    'unit'                    => '度量單位',
    'action_col'              => '行為',
    'possession_col'          => '財產',

    // ─── Social Institution module ────────────────────────────────
    'socialinst_list'         => '社交機構清單',
    'socialinst_field'        => '社交機構',
    'socialinst_role'         => '社交機構角色',

    // ─── Statuses module ──────────────────────────────────────────
    'statuses_list'           => '社會區分清單',
    'status_en_col'           => '社會區分(英)',
    'status_zh_col'           => '社會區分(中)',
    'supplement_text'         => '補充文字',
    'supplement_placeholder'  => '請補充「並稱/齊名」的稱號，如「東南三賢」、「四俊」等',

    // ─── Assoc module ─────────────────────────────────────────────
    'assoc_list'              => '社會關係清單',
    'assoc_category_col'      => '社會關係類別',
    'assoc_person_y'          => '社會關係人Y',
    'assoc_relative'          => '社會關係人親屬',
    'assoc_start_year'        => '社會關係始年',
    'assoc_end_year'          => '社會關係終年',
    'academic_topic'          => '學術主題',
    'occasion'                => '場合',
    'work_title'              => '作品標題',
    'assoc_count_field'       => '關係次數',
    'assoc_count_hint'        => '此欄位僅適用於書信：當無法以標題及日期區分多次信件時，則僅建「一筆」社會關係，並將信件總數填於此欄。請填阿拉伯數字',
    'intermediary'            => '社會關係中介人',
    'intermediary_type'       => '社會關係中介類型',
    'witness'                 => '社會關係指證人',
    'assoc_location'          => '社會關係發生地',
    'paired_assoc'            => '成對社會關係',
    'no_paired_assoc'         => '無對應社會關係',
    'paired_assoc_kinship'    => '成對社會關係人的親屬關係',
    'paired_label'            => '成對：',
    'no_matching_codes'       => '未找到匹配的代碼',
    'assoc_person_col'        => '社會關係人',

    // ─── Offices module ───────────────────────────────────────────
    'offices_list'            => '官名清單',
    'office_name_field'       => '官名',
    'appt_type'               => '除授類別',
    'assume_office'           => '是否赴任',
    'office_category'         => '職官類別',
    'sequence_same_note'      => '註：若有同時任命的官職，請手動填上相同的 sequence',

    // ─── AI Features ──────────────────────────────────────────────
    'ai_status_recognition'   => 'AI 智能識別社會區分類別代碼',
    'ai_assoc_recognition'    => 'AI 智能識別社會關係代碼',
    'ai_offices_autofill'     => 'AI 智能填充',
    'ai_consent_intro'        => '使用智能識別功能即表示您理解並同意：',
    'ai_fill_consent_intro'   => '使用智能填充功能即表示您理解並同意：',
    'ai_notice_title'         => '重要提示：數據收集與第三方服務',
    'ai_input_placeholder_status'  => '例如：通醫學',
    'ai_description_hint_status'   => 'AI 會語義理解您的描述，從社會區分類別代碼表中找出最相關的代碼',
    'ai_input_placeholder_assoc'   => '例如：為釋顯達作塔記',
    'ai_description_hint_assoc'    => 'AI 會語義理解您的描述，從社會關係代碼表中找出最相關的代碼',
    'ai_input_placeholder_offices' => '例如：雍正元年正月初三知涿州新城縣至於六月十五卒于任',
    'ai_description_hint_offices'  => 'AI 將自動提取官名、地名、日期等信息並填充表單',
    'ai_consent_record'       => '您輸入的文本及 AI 識別結果將被記錄用於研究與改進',
    'ai_consent_third_party'  => '您的文本將發送至第三方 AI 服務進行處理',
    'ai_consent_fill_third_party' => '您的文本將發送至第三方 AI 服務（Google Gemini API、OpenAI API 等，恕不另行通知）進行處理',
    'ai_consent_verify'       => 'AI 識別結果僅供參考，請務必核實後再提交',
    'ai_current_model'        => '當前使用模型：',
    'ai_recognize_btn'        => 'AI 智能識別',
    'ai_fill_btn'             => 'AI 智能填充',
    'ai_clear_btn'            => '清除 AI 建議',
    'ai_processing'           => 'AI 識別中，請稍候...',
    'ai_fill_processing'      => 'AI 處理中...',
    'ai_recognition_failed'   => '識別失敗',
    'ai_fill_complete'        => 'AI 填充完成',
    'ai_fields_matched'       => ':count 個欄位成功匹配',
    'ai_fields_confirm'       => ':count 個欄位需要確認（黃色標記，請檢查後直接提交）',
    'ai_fields_manual'        => ':count 個欄位需要手動搜尋（AI 已提取關鍵字但未找到匹配）',
    'ai_fields_failed'        => ':count 個欄位無法提取',
    'ai_no_match'             => '表中未找到對應的概念',
    'ai_candidate_codes'      => '候選代碼（點擊填入表單）',
    'ai_enter_description'    => '請輸入描述文字',
    'ai_enter_text_first'     => '請先輸入原始文本',
    'ai_clear_confirm'        => '確定要清除所有 AI 填充的內容嗎？',
    'ai_original_text_label'  => '原始文本（請粘貼包含任官記錄的文本）',

    // ─── Sequence adjustment partial ──────────────────────────────
    'seq_adjust_title'        => '次序調整',
    'seq_adjust_btn'          => '調整次序',
    'seq_adjust_hint'         => '顯示「新次序」欄位後，可逐條修改並提交。',
    'seq_collapse_btn'        => '收起次序調整',

    // ─── Inline time fields component ────────────────────────────
    'convert_to_reign_year'   => '將西元年份轉換為年號',
    'convert_to_ad_year'      => '將年號轉換為西元年份',
    'year_unit'               => '年',
    'month_unit'              => '月',
    'day_unit'                => '日',
    'month_range_hint'        => '請輸入 1-12 或留空',
    'day_range_hint'          => '請輸入 1-30 或留空',
    'time_range_label'        => '時限',
    'intercalary_month_label' => '閏月',
    'day_ganzhi_label'        => '日(干支)',

    // ─── History button ───────────────────────────────────────────
    'view_history'            => '查看本頁歷史記錄',

    // ─── AI result summary suffixes (HTML count placeholders) ─────
    'ai_fields_matched_suffix'  => '個欄位成功匹配',
    'ai_fields_confirm_suffix'  => '個欄位需要確認（黃色標記，請檢查後直接提交）',
    'ai_fields_manual_suffix'   => '個欄位需要手動搜尋（AI 已提取關鍵字但未找到匹配）',
    'ai_fields_failed_suffix'   => '個欄位無法提取',
    'ai_fill_done_status'       => '填充完成',
];
