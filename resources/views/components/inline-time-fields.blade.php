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
    'nhLabel' => null,
    'rangeLabel' => null,
    'intercalaryLabel' => null,
    'dayGzLabel' => null,
    'showLunarPlaceholder' => false,
    'notesName' => null,
    'notesValue' => '',
    'notesLabel' => '',
    'showNotes' => false,
    'disabled' => false,
])

@php
    $disabledAttr = $disabled ? 'disabled' : '';
    $nhLabel         = $nhLabel         ?? __('person.reign_year');
    $rangeLabel      = $rangeLabel      ?? __('biogmains.time_range_label');
    $intercalaryLabel = $intercalaryLabel ?? __('biogmains.intercalary_month_label');
    $dayGzLabel      = $dayGzLabel      ?? __('biogmains.day_ganzhi_label');
@endphp

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
                   @foreach($yearAttributes as $attr => $value) {{ $attr }}="{{ $value }}" @endforeach
                   {{ $disabledAttr }}>
        </div>
        @if(!$disabled)
            <div class="d-flex mr-3">
                <button type="button"
                        class="btn btn-sm btn-outline-secondary era-convert-btn"
                        title="{{ __('biogmains.convert_to_reign_year') }}"
                        data-toggle="tooltip">
                    <i class="fas fa-arrow-right"></i>
                </button>
                <button type="button"
                        class="btn btn-sm btn-outline-secondary era-reverse-convert-btn ml-1"
                        title="{{ __('biogmains.convert_to_ad_year') }}"
                        data-toggle="tooltip">
                    <i class="fas fa-arrow-left"></i>
                </button>
            </div>
        @endif
        <div class="d-flex align-items-center flex-wrap mr-3" style="min-width: 36ch; flex: 1 1 36ch;">
            <label class="mb-0 mr-2" for="{{ $nhCodeName }}">{{ $nhLabel }}</label>
            <div class="mr-2" style="min-width: 16ch; flex: 1 1 16ch;">
                <select-vue element-id="{{ $nhCodeName }}" name="{{ $nhCodeName }}" model="nianhao" id-key="c_nianhao_id" selected="{{ $nhCodeValue }}" :disabled="{{ $disabled ? 'true' : 'false' }}"></select-vue>
            </div>
            <input type="number"
                   name="{{ $nhYearName }}"
                   class="form-control mr-2"
                   style="width: 8ch; min-width: 8ch;"
                   value="{{ $nhYearValue }}"
                   {{ $disabledAttr }}>
            <span>{{ __('biogmains.year_unit') }}</span>
        </div>
        @if($rangeName)
            <div class="d-flex align-items-center flex-wrap mr-3" style="min-width: 28ch; flex: 1 1 28ch;">
                <label class="mb-0 mr-2" for="{{ $rangeName }}">{{ $rangeLabel }}</label>
                <div class="flex-grow-1" style="min-width: 16ch;">
                    <select-vue element-id="{{ $rangeName }}" name="{{ $rangeName }}" model="range" id-key="c_range_code" selected="{{ $rangeValue }}" :disabled="{{ $disabled ? 'true' : 'false' }}"></select-vue>
                </div>
            </div>
        @endif
        @if($showLunar)
            <div class="d-flex align-items-center flex-wrap" style="flex: 1 1 56ch;">
                <div class="custom-control custom-checkbox mr-4">
                    <input type="hidden" name="{{ $intercalaryName }}" value="0">
                    <input type="checkbox"
                           class="custom-control-input"
                           id="{{ $intercalaryName }}"
                           name="{{ $intercalaryName }}"
                           value="1"
                           {{ (int) $intercalaryValue === 1 ? 'checked' : '' }}
                           {{ $disabledAttr }}>
                    <label class="custom-control-label" for="{{ $intercalaryName }}">{{ $intercalaryLabel }}</label>
                </div>
                <div class="d-flex flex-column mr-2" style="width: 8ch; min-width: 8ch;">
                    <input type="number"
                           name="{{ $monthName }}"
                           class="form-control lunar-month"
                           min="1"
                           max="12"
                           value="{{ $monthValue }}"
                           {{ $disabledAttr }}>
                    <div class="invalid-feedback">{{ __('biogmains.month_range_hint') }}</div>
                </div>
                <span class="mr-2">{{ __('biogmains.month_unit') }}</span>
                <div class="d-flex flex-column mr-2" style="width: 8ch; min-width: 8ch;">
                    <input type="number"
                           name="{{ $dayName }}"
                           class="form-control lunar-day"
                           min="1"
                           max="30"
                           value="{{ $dayValue }}"
                           {{ $disabledAttr }}>
                    <div class="invalid-feedback">{{ __('biogmains.day_range_hint') }}</div>
                </div>
                <span class="mr-2">{{ __('biogmains.day_unit') }}</span>
                <div class="d-flex align-items-center" style="min-width: 0; flex: 1 1 auto;">
                    <label class="mb-0 mr-2 text-nowrap" for="{{ $dayGzName }}">{{ $dayGzLabel }}</label>
                    <div class="flex-grow-1" style="min-width: 12ch;">
                        <select-vue element-id="{{ $dayGzName }}" name="{{ $dayGzName }}" model="ganzhi" id-key="c_ganzhi_code" selected="{{ $dayGzValue }}" :disabled="{{ $disabled ? 'true' : 'false' }}"></select-vue>
                    </div>
                </div>
            </div>
        @elseif($showLunarPlaceholder)
            <div class="d-flex align-items-center flex-wrap" style="min-width: 56ch; flex: 1 1 56ch;"></div>
        @endif
        @if($showNotes)
            <div class="w-100 my-2"></div>
            <label class="mb-0 mr-2" for="{{ $notesName }}">{{ $notesLabel }}</label>
            <input type="text" name="{{ $notesName }}" id="{{ $notesName }}" class="form-control"
                   value="{{ $notesValue }}"
                   {{ $disabledAttr }}>
        @endif
    </div>
</div>
