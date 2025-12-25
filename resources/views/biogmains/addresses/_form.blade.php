{{-- 共享表单组件 - Addresses --}}
@php
    $isEdit = isset($row);
    $formAction = $isEdit
        ? route('basicinformation.addresses.update', ['basicinformation' => $id, 'address'=> $id.'-'.$row->c_addr_id.'-'.$row->c_addr_type.'-'.$row->c_sequence])
        : route('basicinformation.addresses.store', ['basicinformation' => $id]);
@endphp

<form action="{{ $formAction }}" method="post">
    @if($isEdit)
        {{ method_field('PATCH') }}
    @endif
    {{ csrf_field() }}

    <div class="form-group row">
        <label for="person_id" class="col-sm-2 col-form-label">person id</label>
        <div class="col-sm-10">
            <input type="text" class="form-control person_id" name="person_id" value="{{ $id }}" disabled>
        </div>
    </div>

    <div class="form-group row">
        <label for="c_sequence" class="col-sm-2 col-form-label">遷徙次序</label>
        <div class="col-sm-10">
            <input type="number" class="form-control" name="c_sequence" value="{{ $isEdit ? $row->c_sequence : '' }}" maxlength="4" {{ $isEdit ? '' : 'required' }}>
        </div>
    </div>

    <div class="form-group row">
        <label for="c_addr_type" class="col-sm-2 col-form-label">地址類別(c_addr_type)</label>
        <div class="col-sm-10">
            <select-vue name="c_addr_type" model="biogaddr" selected="{{ $isEdit ? $row->c_addr_type : '0' }}"></select-vue>
        </div>
    </div>

    <div class="form-group row">
        <label for="c_addr_id" class="col-sm-2 col-form-label">地名(c_addr_id)</label>
        <div class="col-sm-10">
            <select class="form-control c_addr_id" name="c_addr_id">
                @if($isEdit && isset($addr_str))
                    <option value="{{ $row->c_addr_id }}" selected="selected">{{ $addr_str }}</option>
                @else
                    <option value="0"> 0[Unknown][未详]</option>
                @endif
            </select>
            @if($isEdit && isset($other_belongs_str) && $other_belongs_str)
                其他上層歸屬資訊：{{$other_belongs_str}}
            @endif
        </div>
    </div>

    <div class="form-group row">
        <label for="c_firstyear" class="col-sm-2 col-form-label">始年(c_firstyear)</label>
        <div class="col-sm-10">
            <div class="d-flex align-items-center flex-wrap">
                <div class="d-flex align-items-center flex-wrap mr-3" style="min-width: 12ch; flex: 1 1 12ch;">
                    <input type="number" name="c_firstyear" class="form-control" style="width: 12ch; min-width: 12ch;" value="{{ $isEdit ? $row->c_firstyear : '' }}">
                </div>
                <div class="d-flex align-items-center flex-wrap mr-3" style="min-width: 36ch; flex: 1 1 36ch;">
                    <label class="mb-0 mr-2" for="c_fy_nh_code">年号</label>
                    <div class="mr-2" style="min-width: 16ch; flex: 1 1 16ch;">
                        <select-vue name="c_fy_nh_code" model="nianhao" selected="{{ $isEdit ? $row->c_fy_nh_code : '' }}"></select-vue>
                    </div>
                    <input type="number" name="c_fy_nh_year" class="form-control mr-2"
                           style="width: 8ch; min-width: 8ch;"
                           value="{{ $isEdit ? $row->c_fy_nh_year : '' }}">
                    <span>年</span>
                </div>
                <div class="d-flex align-items-center flex-wrap mr-3" style="min-width: 28ch; flex: 1 1 28ch;">
                    <label class="mb-0 mr-2" for="c_fy_range">時限</label>
                    <div class="flex-grow-1" style="min-width: 16ch;">
                        <select-vue name="c_fy_range" model="range" selected="{{ $isEdit ? $row->c_fy_range : '' }}"></select-vue>
                    </div>
                </div>
                <div class="d-flex align-items-center flex-wrap" style="min-width: 56ch; flex: 1 1 56ch;">
                    <div class="custom-control custom-checkbox mr-4">
                        <input type="hidden" name="c_fy_intercalary" value="0">
                        <input type="checkbox"
                               class="custom-control-input"
                               id="c_fy_intercalary"
                               name="c_fy_intercalary"
                               value="1"
                               {{ ($isEdit && $row->c_fy_intercalary == 1) ? 'checked' : '' }}>
                        <label class="custom-control-label" for="c_fy_intercalary">閏月</label>
                    </div>
                    <div class="d-flex flex-column mr-2" style="width: 8ch; min-width: 8ch;">
                        <input type="number" name="c_fy_month" class="form-control lunar-month"
                               min="1"
                               max="12"
                               value="{{ $isEdit ? $row->c_fy_month : '' }}">
                        <div class="invalid-feedback">請輸入 1-12 或留空</div>
                    </div>
                    <span class="mr-2">月</span>
                    <div class="d-flex flex-column mr-2" style="width: 8ch; min-width: 8ch;">
                        <input type="number" name="c_fy_day" class="form-control lunar-day"
                               min="1"
                               max="30"
                               value="{{ $isEdit ? $row->c_fy_day : '' }}">
                        <div class="invalid-feedback">請輸入 1-30 或留空</div>
                    </div>
                    <span class="mr-2">日</span>
                    <label class="mb-0 mr-2">日(干支)</label>
                    <div class="flex-grow-1" style="min-width: 12ch;">
                        <select-vue name="c_fy_day_gz" model="ganzhi" selected="{{ $isEdit ? $row->c_fy_day_gz : '' }}"></select-vue>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="form-group row">
        <label for="c_lastyear" class="col-sm-2 col-form-label">終年(c_lastyear)</label>
        <div class="col-sm-10">
            <div class="d-flex align-items-center flex-wrap">
                <div class="d-flex align-items-center flex-wrap mr-3" style="min-width: 12ch; flex: 1 1 12ch;">
                    <input type="number" name="c_lastyear" class="form-control" style="width: 12ch; min-width: 12ch;" value="{{ $isEdit ? $row->c_lastyear : '' }}">
                </div>
                <div class="d-flex align-items-center flex-wrap mr-3" style="min-width: 36ch; flex: 1 1 36ch;">
                    <label class="mb-0 mr-2" for="c_ly_nh_code">年号</label>
                    <div class="mr-2" style="min-width: 16ch; flex: 1 1 16ch;">
                        <select-vue name="c_ly_nh_code" model="nianhao" selected="{{ $isEdit ? $row->c_ly_nh_code : '' }}"></select-vue>
                    </div>
                    <input type="number" name="c_ly_nh_year" class="form-control mr-2"
                           style="width: 8ch; min-width: 8ch;"
                           value="{{ $isEdit ? $row->c_ly_nh_year : '' }}">
                    <span>年</span>
                </div>
                <div class="d-flex align-items-center flex-wrap mr-3" style="min-width: 28ch; flex: 1 1 28ch;">
                    <label class="mb-0 mr-2" for="c_ly_range">時限</label>
                    <div class="flex-grow-1" style="min-width: 16ch;">
                        <select-vue name="c_ly_range" model="range" selected="{{ $isEdit ? $row->c_ly_range : '' }}"></select-vue>
                    </div>
                </div>
                <div class="d-flex align-items-center flex-wrap" style="min-width: 56ch; flex: 1 1 56ch;">
                    <div class="custom-control custom-checkbox mr-4">
                        <input type="hidden" name="c_ly_intercalary" value="0">
                        <input type="checkbox"
                               class="custom-control-input"
                               id="c_ly_intercalary"
                               name="c_ly_intercalary"
                               value="1"
                               {{ ($isEdit && $row->c_ly_intercalary == 1) ? 'checked' : '' }}>
                        <label class="custom-control-label" for="c_ly_intercalary">閏月</label>
                    </div>
                    <div class="d-flex flex-column mr-2" style="width: 8ch; min-width: 8ch;">
                        <input type="number" name="c_ly_month" class="form-control lunar-month"
                               min="1"
                               max="12"
                               value="{{ $isEdit ? $row->c_ly_month : '' }}">
                        <div class="invalid-feedback">請輸入 1-12 或留空</div>
                    </div>
                    <span class="mr-2">月</span>
                    <div class="d-flex flex-column mr-2" style="width: 8ch; min-width: 8ch;">
                        <input type="number" name="c_ly_day" class="form-control lunar-day"
                               min="1"
                               max="30"
                               value="{{ $isEdit ? $row->c_ly_day : '' }}">
                        <div class="invalid-feedback">請輸入 1-30 或留空</div>
                    </div>
                    <span class="mr-2">日</span>
                    <label class="mb-0 mr-2">日(干支)</label>
                    <div class="flex-grow-1" style="min-width: 12ch;">
                        <select-vue name="c_ly_day_gz" model="ganzhi" selected="{{ $isEdit ? $row->c_ly_day_gz : '' }}"></select-vue>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="form-group row">
        <label for="c_source" class="col-sm-2 col-form-label">出處(c_source)</label>
        <div class="col-sm-5">
            <select class="form-control c_source" name="c_source" id="c_source">
                @if($isEdit && isset($text_str) && $text_str)
                    <option value="{{ $row->c_source }}" selected="selected">{{ $text_str }}</option>
                @else
                    <option value="" selected="selected">请搜索</option>
                @endif
            </select>
        </div>
    </div>

    <div class="form-group row">
        <label for="c_pages" class="col-sm-2 col-form-label">頁數/條目</label>
        <div class="col-sm-4">
            <input type="text" class="form-control" name="c_pages" value="{{ $isEdit ? $row->c_pages : '' }}">
        </div>
    </div>

    <div class="form-group row">
        <label for="c_notes" class="col-sm-2 col-form-label">注(c_notes)</label>
        <div class="col-sm-10">
            <textarea class="form-control" name="c_notes" cols="30" rows="5">{{ $isEdit ? $row->c_notes : '' }}</textarea>
        </div>
    </div>

    <div class="form-group row">
        <label for="c_natal" class="col-sm-2 col-form-label">娘家地址(c_natal)</label>
        <div class="col-sm-10">
            <select class="form-control select2" name="c_natal">
                <option disabled value="">请选择</option>
                <option value="0" {{ ($isEdit && $row->c_natal == 0) ? 'selected' : '' }}>0-否</option>
                <option value="1" {{ ($isEdit && $row->c_natal == 1) ? 'selected' : '' }}>1-是</option>
            </select>
        </div>
    </div>

    <div class="form-group row">
        <label for="textperson_pair" class="col-sm-2 col-form-label">候選出處與頁數</label>
        <div class="col-sm-10">
            <select class="form-control textperson_pair" name="">
                <option value="">由此選取[出處]頁面中的出處與頁碼資訊</option>
            </select>
        </div>
    </div>

    <div class="form-group row">
        <label for="" class="col-sm-2 col-form-label">建檔</label>
        <div class="col-sm-10">
            <input type="text" class="form-control" value="{{ $isEdit ? $row->c_created_by.'/'.$row->c_created_date : '' }}" disabled>
        </div>
    </div>

    <div class="form-group row">
        <label for="" class="col-sm-2 col-form-label">更新</label>
        <div class="col-sm-10">
            <input type="text" class="form-control" value="{{ $isEdit ? $row->c_modified_by.'/'.$row->c_modified_date : '' }}" disabled>
        </div>
    </div>

    <div class="form-group row">
        <div class="offset-sm-2 col-sm-10">
            <button type="submit" class="btn btn-secondary">Submit</button>
        </div>
    </div>
</form>

@section('js')
    <script>
    onViteReady(function() {
        $(".select2").select2();
        textperson_pair_first_load();
        $(".c_addr_id").select2(options('addr'));
        $(".c_source").select2(options('text'));

        function validateLunarInput($input, max) {
            var value = $input.val().trim();
            if (value === '') {
                $input.removeClass('is-invalid');
                return;
            }
            var parsed = Number(value);
            var isInteger = Number.isInteger(parsed);
            var isValid = isInteger && parsed >= 1 && parsed <= max;
            $input.toggleClass('is-invalid', !isValid);
        }

        function bindLunarValidation() {
            $('.lunar-month').each(function () {
                var $input = $(this);
                validateLunarInput($input, 12);
                $input.on('input change', function () {
                    validateLunarInput($input, 12);
                });
            });
            $('.lunar-day').each(function () {
                var $input = $(this);
                validateLunarInput($input, 30);
                $input.on('input change', function () {
                    validateLunarInput($input, 30);
                });
            });
        }

        bindLunarValidation();

        function formatRepo (repo) {
            if (repo.loading) {
                return repo.text;
            }

            return "<div class='select2-result-repository clearfix'>" +
                "<div class='select2-result-repository__meta'>" +
                "<div class='select2-result-repository__title'>" +
                repo.text +
                "</div></div></div>";
        }

        function formatRepoSelection (repo) {
            return repo.text || repo.text;
        }

        function options(model) {
            return {
                ajax: {
                    url: "/api/select/search/"+model,
                    dataType: 'json',
                    delay: 250,
                    data: function (params) {
                        return {
                            q: params.term, // search term
                            page: params.page,
                        };
                    },
                    processResults: function (data, params) {
                        // parse the results into the format expected by Select2
                        // since we are using custom formatting functions we do not need to
                        // alter the remote JSON data, except to indicate that infinite
                        // scrolling can be used
                        params.page = params.page || 1;

                        return {
                            results: data.data,
                            pagination: {
                                more: (params.page * 30) < data.total
                            }
                        };
                    },
                    cache: true
                },
                placeholder: '请搜索',
                escapeMarkup: function (markup) { return markup; }, // let our custom formatter work
                minimumInputLength: 1,
                templateResult: formatRepo,
                templateSelection: formatRepoSelection
            }
        }

        function textperson_pair_first_load(){
            let person_id = $('.person_id').val();
            //console.log(person_id);
            let data = [{
                id: 0,
                text: '請填寫[人物 >> 出處]'
            }];
            $.get('/api/select/search/textperson', {q: person_id}, function (data, textStatus){
                //console.log(data);
                for (let i=data.data.length-1; i>-1; i--){
                    item = data.data[i];
                    //console.log(item);
                    $(".textperson_pair").append(new Option(item['text'], item['value']));
                }
            });
        }

        $(".textperson_pair").change(function(){
            var hasValue = $(".textperson_pair").val();
            //console.log(hasValue);
            var textperson_value = hasValue.split("&and&");
            $.get('/api/select/search/text', {q: textperson_value[0]}, function (data, textStatus){
                //console.log(data);
                for (var i=data.data.length-1; i>-1; i--){
                    item = data.data[i];
                    console.log(item);
                    var textperson_text = item['text'];
                }
                //console.log(textperson_value);
                /*在這裡添加錄入表單更新的欄位與資料*/
                $("select[name='c_source'] option[selected]").val(textperson_value[0]);
                $("select[name='c_source']").val(textperson_value[0]);
                $("#select2-c_source-container").text(textperson_text);
                $("#select2-c_source-container").css("background","#FFFFBB");
                $("input[name='c_pages']").val(textperson_value[1]);
                $("input[name='c_pages']").css("background","#FFFFBB");
                alert('更新[出處]與[頁數/條目]成功');
            });
        });
    });
    </script>
@endsection
