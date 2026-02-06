{{-- 人物 ID 顯示組件：展示人物基本信息（ID、姓名拼音、朝代） --}}
@props([
    'personId' => null,
])

@php
    // 查詢人物基本信息
    $person = null;
    $dynastyName = '';
    $dynastyCode = '';
    $dynastyStart = null;
    $dynastyEnd = null;

    if ($personId) {
        try {
            $person = \App\Models\BiogMain::with('simpleDynasty')->find($personId);
            if ($person && $person->simpleDynasty) {
                $dynasty = $person->simpleDynasty;
                $dynastyName = $dynasty->c_dynasty_chn ?? $dynasty->c_dynasty ?? '';
                $dynastyCode = $dynasty->c_dy ?? '';
                $dynastyStart = $dynasty->c_start;
                $dynastyEnd = $dynasty->c_end;
            }
        } catch (\Exception $e) {
            // 在測試環境或資料庫連線失敗時，優雅降級
        }
    }
@endphp

<div class="form-group row person-id-display-component">
    <label for="person_id_display" class="col-sm-2 col-form-label">人物基本信息</label>
    <div class="col-sm-10">
        <input type="hidden" class="person_id" value="{{ $personId }}">
        <input type="hidden" class="dynasty_name" value="{{ $dynastyName }}">
        <input type="hidden" class="dynasty_code" value="{{ $dynastyCode }}">
        <input type="hidden" class="dynasty_start" value="{{ $dynastyStart }}">
        <input type="hidden" class="dynasty_end" value="{{ $dynastyEnd }}">
        <div class="card bg-light">
            <div class="card-body p-3">
                <div class="row">
                    <div class="col-md-4">
                        <strong>人物 ID：</strong>
                        <span class="text-primary">{{ $personId }}</span>
                    </div>
                    @if($person)
                        <div class="col-md-4">
                            <strong>姓名（拼音）：</strong>
                            <span>{{ $person->c_name ?? '—' }}</span>
                        </div>
                        <div class="col-md-4">
                            <strong>朝代：</strong>
                            <span>{{ $dynastyName ?: '—' }}@if($dynastyStart !== null || $dynastyEnd !== null)（{{ $dynastyStart }}–{{ $dynastyEnd }}）@endif</span>
                        </div>
                    @endif
                </div>
                @if($person && $person->c_name_chn)
                    <div class="row mt-2">
                        <div class="col-12">
                            <strong>姓名（中文）：</strong>
                            <span>{{ $person->c_name_chn }}</span>
                        </div>
                    </div>
                @endif
            </div>
        </div>
        <small class="text-muted">此為顯示用資訊，不會包含在表單提交中</small>
    </div>
</div>
