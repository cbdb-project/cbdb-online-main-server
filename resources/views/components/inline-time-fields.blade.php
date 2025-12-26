@props([
    'yearName',
    'yearValue' => '',
    'yearRequired' => false,
    'yearAttributes' => [],
    'yearInputType' => 'number',
    'nhCodeName',
    'nhCodeValue' => '',
    'nhYearName',
    'nhYearValue' => '',
    'rangeName' => null,
    'rangeValue' => '',
    'showLunar' => false,
    'intercalaryName' => null,
    'intercalaryValue' => null,
    'monthName' => null,
    'monthValue' => '',
    'dayName' => null,
    'dayValue' => '',
    'dayGzName' => null,
    'dayGzValue' => '',
    'nhLabel' => '年号',
    'rangeLabel' => '時限',
    'intercalaryLabel' => '閏月',
    'dayGzLabel' => '日(干支)',
    'showLunarPlaceholder' => false,
    'notesName' => null,
    'notesValue' => '',
    'notesLabel' => '',
    'showNotes' => false,
])

<div class="col-sm-10">
    <div class="d-flex align-items-center flex-wrap">
        <div class="d-flex align-items-center flex-wrap mr-2" style="min-width: 12ch; flex: 1 1 12ch;">
            <input type="{{ $yearInputType }}"
                   name="{{ $yearName }}"
                   class="form-control era-year-input"
                   style="width: 12ch; min-width: 12ch;"
                   value="{{ $yearValue }}"
                   data-nh-code-name="{{ $nhCodeName }}"
                   data-nh-year-name="{{ $nhYearName }}"
                   @if($yearRequired) required @endif
                   @foreach($yearAttributes as $attr => $value) {{ $attr }}="{{ $value }}" @endforeach>
        </div>
        <button type="button"
                class="btn btn-sm btn-outline-secondary era-convert-btn mr-3"
                title="將公元年份轉換為年號"
                data-toggle="tooltip">
            <i class="fas fa-search"></i>
        </button>
        <div class="d-flex align-items-center flex-wrap mr-3" style="min-width: 36ch; flex: 1 1 36ch;">
            <label class="mb-0 mr-2" for="{{ $nhCodeName }}">{{ $nhLabel }}</label>
            <div class="mr-2" style="min-width: 16ch; flex: 1 1 16ch;">
                <select-vue element-id="{{ $nhCodeName }}" name="{{ $nhCodeName }}" model="nianhao" id-key="c_nianhao_id" selected="{{ $nhCodeValue }}"></select-vue>
            </div>
            <input type="number"
                   name="{{ $nhYearName }}"
                   class="form-control mr-2"
                   style="width: 8ch; min-width: 8ch;"
                   value="{{ $nhYearValue }}">
            <span>年</span>
        </div>
        @if($rangeName)
            <div class="d-flex align-items-center flex-wrap mr-3" style="min-width: 28ch; flex: 1 1 28ch;">
                <label class="mb-0 mr-2" for="{{ $rangeName }}">{{ $rangeLabel }}</label>
                <div class="flex-grow-1" style="min-width: 16ch;">
                    <select-vue element-id="{{ $rangeName }}" name="{{ $rangeName }}" model="range" id-key="c_range_code" selected="{{ $rangeValue }}"></select-vue>
                </div>
            </div>
        @endif
        @if($showLunar)
            <div class="d-flex align-items-center flex-wrap" style="min-width: 56ch; flex: 1 1 56ch;">
                <div class="custom-control custom-checkbox mr-4">
                    <input type="hidden" name="{{ $intercalaryName }}" value="0">
                    <input type="checkbox"
                           class="custom-control-input"
                           id="{{ $intercalaryName }}"
                           name="{{ $intercalaryName }}"
                           value="1"
                           {{ (int) $intercalaryValue === 1 ? 'checked' : '' }}>
                    <label class="custom-control-label" for="{{ $intercalaryName }}">{{ $intercalaryLabel }}</label>
                </div>
                <div class="d-flex flex-column mr-2" style="width: 8ch; min-width: 8ch;">
                    <input type="number"
                           name="{{ $monthName }}"
                           class="form-control lunar-month"
                           min="1"
                           max="12"
                           value="{{ $monthValue }}">
                    <div class="invalid-feedback">請輸入 1-12 或留空</div>
                </div>
                <span class="mr-2">月</span>
                <div class="d-flex flex-column mr-2" style="width: 8ch; min-width: 8ch;">
                    <input type="number"
                           name="{{ $dayName }}"
                           class="form-control lunar-day"
                           min="1"
                           max="30"
                           value="{{ $dayValue }}">
                    <div class="invalid-feedback">請輸入 1-30 或留空</div>
                </div>
                <span class="mr-2">日</span>
                <label class="mb-0 mr-2" for="{{ $dayGzName }}">{{ $dayGzLabel }}</label>
                <div class="flex-grow-1" style="min-width: 12ch;">
                    <select-vue element-id="{{ $dayGzName }}" name="{{ $dayGzName }}" model="ganzhi" id-key="c_ganzhi_code" selected="{{ $dayGzValue }}"></select-vue>
                </div>
            </div>
        @elseif($showLunarPlaceholder)
            <div class="d-flex align-items-center flex-wrap" style="min-width: 56ch; flex: 1 1 56ch;"></div>
        @endif
        @if($showNotes)
            <div class="w-100 my-2"></div>
            <label class="mb-0 mr-2" for="{{ $notesName }}">{{ $notesLabel }}</label>
            <input type="text" name="{{ $notesName }}" id="{{ $notesName }}" class="form-control"
                   value="{{ $notesValue }}">
        @endif
    </div>
</div>
