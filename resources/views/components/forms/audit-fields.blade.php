{{-- 審計字段組件：建檔/更新信息 --}}
@props([
    'createdBy' => null,
    'createdDate' => null,
    'modifiedBy' => null,
    'modifiedDate' => null,
    'show' => true,
])

@if($show && ($createdBy || $modifiedBy))
    <div class="form-group row">
        <label class="col-sm-2 col-form-label">建檔</label>
        <div class="col-sm-10">
            <input type="text"
                   class="form-control"
                   value="{{ $createdBy && $createdDate ? $createdBy.'/'.$createdDate : '' }}"
                   disabled>
        </div>
    </div>

    <div class="form-group row">
        <label class="col-sm-2 col-form-label">更新</label>
        <div class="col-sm-10">
            <input type="text"
                   class="form-control"
                   value="{{ $modifiedBy && $modifiedDate ? $modifiedBy.'/'.$modifiedDate : '' }}"
                   disabled>
        </div>
    </div>
@endif
