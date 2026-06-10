{{--
    Place Name 連結（CHGIS 地圖）
    參數：
      - $entry: PersonMapPointsService 的單筆點位陣列（含 name_chn/name/linkable/lon/lat/key/addr_id）
      - $personId: 人物 c_personid
    有效座標渲染為可點擊元素（點擊由 chgis-map 前端委派處理），否則為純文字。

    安全：所有屬性值與文字一律以 {{ }}（自動 htmlspecialchars + ENT_QUOTES）輸出，
    切勿改為 {!! !!} 或塞入 inline JS。不使用 href，避免未綁定 JS 時點擊污染 URL hash。
--}}
@php
    $entry = array_merge([
        'name_chn' => null,
        'name' => null,
        'addr_id' => null,
        'key' => '',
        'lon' => null,
        'lat' => null,
        'linkable' => false,
    ], $entry ?? []);

    // 顯示地名（非官名）：name_chn → name → #addr_id；以 null/空字串判斷，保留地名為 '0' 的邊界
    $chgisName = $entry['name_chn'];
    if ($chgisName === null || $chgisName === '') {
        $chgisName = $entry['name'];
    }
    if ($chgisName === null || $chgisName === '') {
        $chgisName = $entry['addr_id'] === null || $entry['addr_id'] === ''
            ? __('chgis_map.unknown_place')
            : '#' . $entry['addr_id'];
    }
@endphp
@if(!empty($entry['linkable']))
    <a class="chgis-place-link"
       role="button"
       tabindex="0"
       data-person-id="{{ $personId }}"
       data-key="{{ $entry['key'] }}"
       data-lon="{{ $entry['lon'] }}"
       data-lat="{{ $entry['lat'] }}"
       aria-label="{{ __('chgis_map.open_place_on_map', ['name' => $chgisName]) }}"
       title="{{ __('chgis_map.view_on_map') }}">{{ $chgisName }}</a>
@else
    {{ $chgisName }}
@endif
