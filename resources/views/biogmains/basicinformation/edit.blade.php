@extends('layouts.dashboard-v3')

@section('content')
    @include('biogmains.banner')
    <div class="card card-default">
        <div class="card-header">
            <h3 class="card-title">基本资料</h3>
        </div>

        <div id='check_info' style='display:none;' class="alert alert-danger alert-dismissible">訊息提示：要離開視窗了，請您確認[名]和[Ming]是否填寫。</div>
        <div id='pinyin_info' style='display:none;' class="alert alert-success alert-dismissible">訊息提示：「生成拼音」已經完成。</div>

        <div class="card-body">
            <form id="basic-info-form" action="/basicinformation/{{ $basicinformation->c_personid }}"
                  method="post">
                {{ method_field('PATCH') }}
                {{ csrf_field() }}
                <div class="form-group row">
                    <label for="c_persionid" class="col-sm-2 col-form-label">person id</label>
                    <div class="col-sm-10">
                        <input type="text" name="c_personid" class="form-control"
                               value="{{ $basicinformation->c_personid }}" disabled>
                    </div>
                </div>
                <div class="form-group row">
                    <div class="col-sm-6">
                        <div class="form-group row">
                            <label for="c_surname_chn" class="col-sm-4 col-form-label">姓</label>
                            <div class="col-sm-8">
                                <input type="text" name="c_surname_chn" class="form-control @error('c_surname_chn') is-invalid @enderror"
                                       value="{{ old('c_surname_chn') ? old('c_surname_chn') : $basicinformation->c_surname_chn }}">
                                @error('c_surname_chn')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                        <div class="form-group row">
                            <label for="c_mingzi_chn" class="col-sm-4 col-form-label">名</label>
                            <div class="col-sm-8">
                                <input type="text" name="c_mingzi_chn" class="form-control @error('c_mingzi_chn') is-invalid @enderror"
                                       value="{{ old('c_mingzi_chn') ? old('c_mingzi_chn') : $basicinformation->c_mingzi_chn }}">
                                @error('c_mingzi_chn')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="form-group row">
                            <label for="c_surname" class="col-sm-4 col-form-label">Xing</label>
                            <div class="col-sm-8">
                                <input type="text" name="c_surname" class="form-control @error('c_surname') is-invalid @enderror"
                                       value="{{ old('c_surname') ? old('c_surname') : $basicinformation->c_surname }}">
                                @error('c_surname')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                        <div class="form-group row">
                            <label for="c_mingzi" class="col-sm-4 col-form-label">Ming</label>
                            <div class="col-sm-8">
                                <input type="text" name="c_mingzi" class="form-control @error('c_mingzi') is-invalid @enderror"
                                       value="{{ old('c_mingzi') ? old('c_mingzi') : $basicinformation->c_mingzi }}">
                                @error('c_mingzi')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                    </div>
                </div>
                <div class="form-group row">
                    <label for="button_ajax_load" class="col-sm-2 col-form-label"></label>
                    <div class="col-sm-4">
                        <button type="button" id="button_ajax_load" class="btn btn-info">生成拼音</button>
                    </div>
                    <label for="button_ajax_load" class="col-sm-2 col-form-label"></label>
                    <div class="col-sm-4">
                    </div>
                </div>
                <div class="form-group row">
                    <label for="c_surname_proper" class="col-sm-2 col-form-label">外文姓</label>
                    <div class="col-sm-4">
                        <input type="text" name="c_surname_proper" class="form-control @error('c_surname_proper') is-invalid @enderror"
                               value="{{ old('c_surname_proper') ? old('c_surname_proper') : $basicinformation->c_surname_proper }}">
                        @error('c_surname_proper')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <label for="c_mingzi_proper" class="col-sm-2 col-form-label">外文名</label>
                    <div class="col-sm-4">
                        <input type="text" name="c_mingzi_proper" class="form-control @error('c_mingzi_proper') is-invalid @enderror"
                               value="{{ old('c_mingzi_proper') ? old('c_mingzi_proper') : $basicinformation->c_mingzi_proper }}">
                        @error('c_mingzi_proper')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
                <div class="form-group row">
                    <label for="c_surname_rm" class="col-sm-2 col-form-label">外文羅馬字轉寫姓</label>
                    <div class="col-sm-4">
                        <input type="text" name="c_surname_rm" class="form-control @error('c_surname_rm') is-invalid @enderror"
                               value="{{ old('c_surname_rm') ? old('c_surname_rm') : $basicinformation->c_surname_rm }}">
                        @error('c_surname_rm')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <label for="c_mingzi_rm" class="col-sm-2 col-form-label">外文羅馬字轉寫名</label>
                    <div class="col-sm-4">
                        <input type="text" name="c_mingzi_rm" class="form-control @error('c_mingzi_rm') is-invalid @enderror"
                               value="{{ old('c_mingzi_rm') ? old('c_mingzi_rm') : $basicinformation->c_mingzi_rm }}">
                        @error('c_mingzi_rm')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
                <div class="form-group row">
                    <label for="c_name_chn" class="col-sm-2 col-form-label">姓名(中)</label>
                    <div class="col-sm-10">
                        <input type="text" name="c_name_chn" class="form-control" readonly
                               value="{{ $basicinformation->c_name_chn }}"
                               style="background-color: #f5f5f5; cursor: not-allowed;">
                        <span class="help-block">
                            <small class="text-muted">此欄位由「姓」和「名」自動合併生成，無需手動填寫</small>
                        </span>
                    </div>
                </div>
                <div class="form-group row">
                    <label for="c_name" class="col-sm-2 col-form-label">姓名(拼音)</label>
                    <div class="col-sm-10">
                        <input type="text" name="c_name" class="form-control" readonly
                               value="{{ $basicinformation->c_name }}"
                               style="background-color: #f5f5f5; cursor: not-allowed;">
                        <span class="help-block">
                            <small class="text-muted">此欄位由「Xing」和「Ming」自動合併生成，無需手動填寫</small>
                        </span>
                    </div>
                </div>
                <div class="form-group row">
                    <label for="c_name_proper" class="col-sm-2 col-form-label">外文全名</label>
                    <div class="col-sm-10">
                        <input type="text" name="c_name_proper" class="form-control" readonly
                               value="{{ $basicinformation->c_name_proper }}"
                               style="background-color: #f5f5f5; cursor: not-allowed;">
                        <span class="help-block">
                            <small class="text-muted">此欄位由「外文名」和「外文姓」自動合併生成（名+姓順序），無需手動填寫</small>
                        </span>
                    </div>
                </div>
                <div class="form-group row">
                    <label for="c_name_rm" class="col-sm-2 col-form-label">外文羅馬字轉寫姓名</label>
                    <div class="col-sm-10">
                        <input type="text" name="c_name_rm" class="form-control" readonly
                               value="{{ $basicinformation->c_name_rm }}"
                               style="background-color: #f5f5f5; cursor: not-allowed;">
                        <span class="help-block">
                            <small class="text-muted">此欄位由「外文羅馬字轉寫姓」和「外文羅馬字轉寫名」自動合併生成，無需手動填寫</small>
                        </span>
                    </div>
                </div>
                <div class="form-group row">
                    <label for="c_female" class="col-sm-2 col-form-label">性别（原female）</label>
                    <div class="col-sm-4">
                        <select class="form-control select2" name="c_female">
                            <option value="0"></option>
                            <option value="0" {{ $basicinformation->c_female == 0? 'selected': '' }}>0-男
                            </option>
                            <option value="1" {{ $basicinformation->c_female == 1? 'selected': '' }}>1-女
                            </option>
                        </select>
                    </div>
                    <label for="c_ethnicity_code" class="col-sm-2 col-form-label">種族/部族</label>
                    <div class="col-sm-4">
                        <select-vue name="c_ethnicity_code" model="ethnicity" selected="{{ $basicinformation->c_ethnicity_code }}"></select-vue>
                    </div>
                </div>
                <div class="form-group row">
                    <label for="c_dy" class="col-sm-2 col-form-label">朝代(dy)</label>
                    <div class="col-sm-10">
                        <select-vue name="c_dy" model="dynasty" selected="{{ $basicinformation->c_dy }}"></select-vue>
                    </div>
                </div>

                <div class="form-group row">
                    <label for="c_firstyear" class="col-sm-2 col-form-label">生年(birth year)</label>
                    <div class="col-sm-10">
                        <div class="d-flex align-items-center flex-wrap">
                            <div class="d-flex align-items-center flex-wrap mr-3" style="min-width: 12ch; flex: 1 1 12ch;">
                                <input type="number" name="c_birthyear" class="form-control"
                                       style="width: 12ch; min-width: 12ch;"
                                       value="{{ $basicinformation->c_birthyear }}" onchange="indexYear()">
                            </div>
                            <div class="d-flex align-items-center flex-wrap mr-3" style="min-width: 36ch; flex: 1 1 36ch;">
                                <label class="mb-0 mr-2" for="c_fy_nh_code">年号</label>
                                <div class="mr-2" style="min-width: 16ch; flex: 1 1 16ch;">
                                    <select-vue name="c_by_nh_code" model="nianhao" selected="{{ $basicinformation->c_by_nh_code }}"></select-vue>
                                </div>
                                <input type="number" name="c_by_nh_year" class="form-control mr-2"
                                       style="width: 8ch; min-width: 8ch;"
                                       value="{{ $basicinformation->c_by_nh_year }}">
                                <span>年</span>
                            </div>
                            <div class="d-flex align-items-center flex-wrap mr-3" style="min-width: 28ch; flex: 1 1 28ch;">
                                <label class="mb-0 mr-2" for="c_by_range">時限</label>
                                <div class="flex-grow-1" style="min-width: 16ch;">
                                    <select-vue name="c_by_range" model="range" selected="{{ $basicinformation->c_by_range }}"></select-vue>
                                </div>
                            </div>
                            <div class="d-flex align-items-center flex-wrap" style="min-width: 56ch; flex: 1 1 56ch;">
                                <div class="custom-control custom-checkbox mr-4">
                                    <input type="hidden" name="c_by_intercalary" value="0">
                                    <input type="checkbox"
                                           class="custom-control-input"
                                           id="c_by_intercalary"
                                           name="c_by_intercalary"
                                           value="1"
                                           {{ $basicinformation->c_by_intercalary == 1 ? 'checked' : '' }}>
                                    <label class="custom-control-label" for="c_by_intercalary">閏月</label>
                                </div>
                                <div class="d-flex flex-column mr-2" style="width: 8ch; min-width: 8ch;">
                                    <input type="number" class="form-control lunar-month intercalary-month"
                                           data-field="c_by_month"
                                           name="c_by_month"
                                           min="1"
                                           max="12"
                                           step="1"
                                           value="{{ $basicinformation->c_by_month }}">
                                    <div class="invalid-feedback">請輸入 1-12 或留空</div>
                                </div>
                                <span class="mr-2">月</span>
                                <div class="d-flex flex-column mr-2" style="width: 8ch; min-width: 8ch;">
                                    <input type="number" name="c_by_day" class="form-control lunar-day"
                                           min="1"
                                           max="30"
                                           value="{{ $basicinformation->c_by_day }}">
                                    <div class="invalid-feedback">請輸入 1-30 或留空</div>
                                </div>
                                <span class="mr-2">日</span>
                                <label class="mb-0 mr-2">日(干支)</label>
                                <div class="flex-grow-1" style="min-width: 12ch;">
                                    <select-vue name="c_by_day_gz" model="ganzhi" selected="{{ $basicinformation->c_by_day_gz }}"></select-vue>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="form-group row">
                    <label for="c_firstyear" class="col-sm-2 col-form-label">卒年(death year)</label>
                    <div class="col-sm-10">
                        <div class="d-flex align-items-center flex-wrap">
                            <div class="d-flex align-items-center flex-wrap mr-3" style="min-width: 12ch; flex: 1 1 12ch;">
                                <input type="number" name="c_deathyear" class="form-control"
                                       style="width: 12ch; min-width: 12ch;"
                                       value="{{ $basicinformation->c_deathyear }}" onchange="indexYear()">
                            </div>
                            <div class="d-flex align-items-center flex-wrap mr-3" style="min-width: 36ch; flex: 1 1 36ch;">
                                <label class="mb-0 mr-2" for="c_dy_nh_code">年号</label>
                                <div class="mr-2" style="min-width: 16ch; flex: 1 1 16ch;">
                                    <select-vue name="c_dy_nh_code" model="nianhao" selected="{{ $basicinformation->c_dy_nh_code }}"></select-vue>
                                </div>
                                <input type="number" name="c_dy_nh_year" class="form-control mr-2"
                                       style="width: 8ch; min-width: 8ch;"
                                       value="{{ $basicinformation->c_dy_nh_year }}">
                                <span>年</span>
                            </div>
                            <div class="d-flex align-items-center flex-wrap mr-3" style="min-width: 28ch; flex: 1 1 28ch;">
                                <label class="mb-0 mr-2" for="c_dy_range">時限</label>
                                <div class="flex-grow-1" style="min-width: 16ch;">
                                    <select-vue name="c_dy_range" model="range" selected="{{ $basicinformation->c_dy_range }}"></select-vue>
                                </div>
                            </div>
                            <div class="d-flex align-items-center flex-wrap" style="min-width: 56ch; flex: 1 1 56ch;">
                                <div class="custom-control custom-checkbox mr-4">
                                    <input type="hidden" name="c_dy_intercalary" value="0">
                                    <input type="checkbox"
                                           class="custom-control-input"
                                           id="c_dy_intercalary"
                                           name="c_dy_intercalary"
                                           value="1"
                                           {{ $basicinformation->c_dy_intercalary == 1 ? 'checked' : '' }}>
                                    <label class="custom-control-label" for="c_dy_intercalary">閏月</label>
                                </div>
                                <div class="d-flex flex-column mr-2" style="width: 8ch; min-width: 8ch;">
                                    <input type="number" class="form-control lunar-month intercalary-month"
                                           data-field="c_dy_month"
                                           name="c_dy_month"
                                           min="1"
                                           max="12"
                                           step="1"
                                           value="{{ $basicinformation->c_dy_month }}">
                                    <div class="invalid-feedback">請輸入 1-12 或留空</div>
                                </div>
                                <span class="mr-2">月</span>
                                <div class="d-flex flex-column mr-2" style="width: 8ch; min-width: 8ch;">
                                    <input type="number" name="c_dy_day" class="form-control lunar-day"
                                           min="1"
                                           max="30"
                                           value="{{ $basicinformation->c_dy_day }}">
                                    <div class="invalid-feedback">請輸入 1-30 或留空</div>
                                </div>
                                <span class="mr-2">日</span>
                                <label class="mb-0 mr-2">日(干支)</label>
                                <div class="flex-grow-1" style="min-width: 12ch;">
                                    <select-vue name="c_dy_day_gz" model="ganzhi" selected="{{ $basicinformation->c_dy_day_gz }}"></select-vue>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="form-group row">
                    <label for="c_index_year" class="col-sm-2 col-form-label">指數年(index year)</label>
                    <div class="col-sm-10">
                        <input type="number" name="c_index_year" class="form-control @error('c_index_year') is-invalid @enderror" readonly
                               value="{{ $basicinformation->c_index_year }}"
                               style="background-color: #f5f5f5; cursor: not-allowed;">
                        <span class="help-block">
                            <small class="text-muted">此欄位由算法定期自動計算生成，無需手動填寫</small>
                        </span>
                        @error('c_index_year')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
                <div class="form-group row">
                    <label for="c_index_year_type_code"
                           class="col-sm-2 col-form-label">指數年推算方法(c_index_year_type_code)</label>
                    <div class="col-sm-4">
                        <input type="text" name="" class="form-control" readonly
                               value="{{ $basicinformation->c_index_year_type_code }}"
                               style="background-color: #f5f5f5; cursor: not-allowed;">
                        <span class="help-block">
                            <small class="text-muted">此欄位由算法定期自動計算生成，無需手動填寫</small>
                        </span>
                    </div>
                    <label for="c_index_year_source_id"
                           class="col-sm-2 col-form-label">指數年推算來源(c_index_year_source_id)</label>
                    <div class="col-sm-4">
                        <input type="text" name="" class="form-control" readonly
                               value="{{ $basicinformation->c_index_year_source_id }}"
                               style="background-color: #f5f5f5; cursor: not-allowed;">
                        <span class="help-block">
                            <small class="text-muted">此欄位由算法定期自動計算生成，無需手動填寫</small>
                        </span>
                    </div>
                </div>
                <div class="form-group row">
                    <label for="c_index_addr_id"
                           class="col-sm-2 col-form-label">指數地址(index_addr)</label>
                    <div class="col-sm-4">
                        <input type="text" name="" class="form-control" readonly
                               value="{{ $basicinformation->c_index_addr_id }}"
                               style="background-color: #f5f5f5; cursor: not-allowed;">
                        <span class="help-block">
                            <small class="text-muted">此欄位由算法定期自動計算生成，無需手動填寫</small>
                        </span>
                    </div>
                    <label for="c_index_addr_type_code"
                           class="col-sm-2 col-form-label">指數地址類型(index_addr_type)</label>
                    <div class="col-sm-4">
                        <input type="text" name="" class="form-control" readonly
                               value="{{ $basicinformation->c_index_addr_type_code }}"
                               style="background-color: #f5f5f5; cursor: not-allowed;">
                        <span class="help-block">
                            <small class="text-muted">此欄位由算法定期自動計算生成，無需手動填寫</small>
                        </span>
                    </div>
                </div>
                <div class="form-group row">
                    <label for="c_death_age" class="col-sm-2 col-form-label">享年(death_age)</label>
                    <div class="col-sm-4">
                        <input type="number" name="c_death_age" class="form-control @error('c_death_age') is-invalid @enderror"
                               value="{{ $basicinformation->c_death_age }}">
                        @error('c_death_age')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <label for="c_death_age_range" class="col-sm-2 col-form-label">范围(c_death_age_range)</label>
                    <div class="col-sm-4">
                        <select class="form-control select2" name="c_death_age_range" id="c_death_age_range">
                            {{--<option value="null"></option>--}}
                            @foreach($yearRange as $item )
                                @if($item->c_range_code === $basicinformation->c_death_age_range)
                                    <option value="{{ $item->c_range_code }}"
                                            selected>{{ $item->c_range_code.' '.$item->c_approx.' '.$item->c_approx_chn }}</option>
                                @else
                                    <option value="{{ $item->c_range_code }}">{{ $item->c_range_code.' '.$item->c_approx.' '.$item->c_approx_chn }}</option>
                                @endif
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="form-group row">
                    <label for="c_fl_earliest_year"
                           class="col-sm-2 col-form-label">在世始年(fl_earliest_year)</label>
                    <div class="col-sm-10">
                        <div class="d-flex align-items-center flex-wrap">
                            <input type="text" name="c_fl_earliest_year" class="form-control mr-3"
                                   style="width: 120px;"
                                   value="{{ $basicinformation->c_fl_earliest_year }}">
                            <label class="mb-0 mr-2" for="c_fl_ey_nh_code">年号</label>
                            <div class="mr-2" style="min-width: 140px;">
                                <select-vue name="c_fl_ey_nh_code" model="nianhao" selected="{{ $basicinformation->c_fl_ey_nh_code }}"></select-vue>
                            </div>
                            <input type="text" name="c_fl_ey_nh_year" class="form-control mr-2"
                                   style="width: 80px;"
                                   value="{{ $basicinformation->c_fl_ey_nh_year }}">
                            <span class="mr-3">年</span>
                            <div class="w-100 my-2"></div>
                            <label class="mb-0 mr-2" for="c_fl_ey_notes">在世始年注</label>
                            <input type="text" name="c_fl_ey_notes" id="c_fl_ey_notes" class="form-control"
                                   value="{{ $basicinformation->c_fl_ey_notes }}">
                        </div>

                    </div>
                </div>
                <div class="form-group row">
                    <label for="c_fl_latest_year"
                           class="col-sm-2 col-form-label">在世終年(fl_latest_year)</label>
                    <div class="col-sm-10">
                        <div class="d-flex align-items-center flex-wrap">
                            <input type="text" name="c_fl_latest_year" class="form-control mr-3"
                                   style="width: 120px;"
                                   value="{{ $basicinformation->c_fl_latest_year }}">
                            <label class="mb-0 mr-2" for="c_fl_ly_nh_code">年号</label>
                            <div class="mr-2" style="min-width: 140px;">
                                <select-vue name="c_fl_ly_nh_code" model="nianhao" selected="{{ $basicinformation->c_fl_ly_nh_code }}"></select-vue>
                            </div>
                            <input type="text" name="c_fl_ly_nh_year" class="form-control mr-2"
                                   style="width: 80px;"
                                   value="{{ $basicinformation->c_fl_ly_nh_year }}">
                            <span class="mr-3">年</span>
                            <div class="w-100 my-2"></div>
                            <label class="mb-0 mr-2" for="c_fl_ly_notes">在世終年注</label>
                            <input type="text" name="c_fl_ly_notes" id="c_fl_ly_notes" class="form-control"
                                   value="{{ $basicinformation->c_fl_ly_notes }}">
                        </div>

                    </div>
                </div>
                <div class="form-group row">
                    <label for="c_choronym_code" class="col-sm-2 col-form-label">郡望(choronym_code)</label>
                    <div class="col-sm-10">
                        <select-vue name="c_choronym_code" model="choronym" selected="{{ $basicinformation->c_choronym_code }}"></select-vue>
                    </div>
                </div>
                <div class="form-group row">
                    <label for="c_household_status_code" class="col-sm-2 col-form-label">戶籍(c_household_status)</label>
                    <div class="col-sm-10">
                        <select-vue name="c_household_status_code" model="household" selected="{{ $basicinformation->c_household_status_code }}"></select-vue>
                    </div>
                </div>
                <div class="form-group row">
                    <label for="c_notes" class="col-sm-2 col-form-label">注</label>
                    <div class="col-sm-10">
                        <textarea class="form-control" name="c_notes" id="" cols="30"
                                  rows="5">{{ $basicinformation->c_notes }}</textarea>
                    </div>
                </div>
                
                <div class="form-group row">
                    <label for="" class="col-sm-2 col-form-label">建檔</label>
                    <div class="col-sm-10">
                        <input type="text" name="" class="form-control"
                               value="{{ $basicinformation->c_created_by.'/'.$basicinformation->c_created_date }}"
                               disabled>
                    </div>
                </div>
                <div class="form-group row">
                    <label for="" class="col-sm-2 col-form-label">更新</label>
                    <div class="col-sm-10">
                        <input type="text" name="" class="form-control"
                               value="{{ $basicinformation->c_modified_by.'/'.$basicinformation->c_modified_date }}"
                               disabled>
                    </div>
                </div>
                @auth
                    @if(Auth::user()->isActive())
                        <div class="form-group row">
                            <div class="offset-sm-2 col-sm-10">
                                <button type="submit" class="btn btn-secondary" id="basic-info-submit">Submit</button>
                            </div>
                        </div>
                    @endif
                @endauth

            </form>
            @auth
                @if(Auth::user()->isActive())
                    <div class="btn-group float-right">
                        <a href=""
                           onclick="
                                       let msg = '您真的确定要删除吗？\n\n请确认！';
                                       if (confirm(msg)===true){
                                       event.preventDefault();
                                       document.getElementById('delete-form').submit();
                                       }else{
                                       return false;
                                       }
                                       "
                           class="btn btn-danger">Delete</a>

                    </div>
                @endif
            @endauth
            @auth
                @if(Auth::user()->isActive())
                    <div class="btn-group float-right">
                        <a href="../../basicinformation/{{$basicinformation->c_personid}}/Duplicate_Collateral_Info" class="btn btn-success" style="margin-right:40px;">Duplicate Collateral Info</a>
                    </div>
                    <div class="btn-group float-right">
                        <a href="../../basicinformation/{{$basicinformation->c_personid}}/saveas" class="btn btn-success" style="margin-right:40px;">Duplicate Basic Info</a>
                    </div>
                @endif
            @endauth
            @auth
                @if(Auth::user()->isActive())
                    <form id="delete-form" action="{{ route('basicinformation.destroy', ['basicinformation' => $basicinformation->c_personid]) }}" method="POST" style="display: none;">
                        {{ method_field('DELETE') }}
                        {{ csrf_field() }}
                    </form>
                @endif
            @endauth
        </div>
    </div>
@endsection
@section('js')
    <script>
    onViteReady(function() {
        var $basicInfoForm = $('#basic-info-form');
        var $submitButton = $('#basic-info-submit');
        var pristineSnapshot = $basicInfoForm.serialize();

        function evaluateFormDirty() {
            var isDirty = $basicInfoForm.serialize() !== pristineSnapshot;
            $submitButton.prop('disabled', !isDirty);
        }

        evaluateFormDirty();

        $basicInfoForm.on('change input', 'input, select, textarea', function () {
            evaluateFormDirty();
        });

        $basicInfoForm.on('submit', function () {
            pristineSnapshot = $basicInfoForm.serialize();
            $submitButton.prop('disabled', true);
        });

        $(".select2").select2();

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

        function indexYear() {
            let birth = $('input[name=c_birthyear]').val();
            let death = $('input[name=c_deathyear]').val();
            if(birth && death){
                if(death < birth) return;
                let deathage = death - birth + 1;
                $('input[name=c_death_age]').val(deathage);
                let indexyear = deathage > 60 ? parseInt(birth) + 60 : death;
                $('input[name=c_index_year]').val(indexyear);
            }
            // let index =
        }
    });
    </script>
<!-- Javascript -->
<script type="text/javascript">
onViteReady(function(){

    var DoAjax = function(requestUrl, sentData, sHandler, eHandler, pageNotFoundHandler){
        $.ajax({
            type: 'GET',
            url: requestUrl,
            cache: false,
            data: sentData,
            success: sHandler,
            error: eHandler,
            statusCode: {
              404: pageNotFoundHandler
            }
        });
    };

    /* Simulate succeed ajax */
    $("#button_ajax_load").click(function(){

        /*修改這兩行參數就可以更換ajax查詢*/
        var c_textid = $("input[name='c_surname_chn']").val();
        var c_textid2 = $("input[name='c_mingzi_chn']").val();
        var url = "/api/select/search/pinyin?q=" + c_textid + "";
        var url2 = "/api/select/search/pinyin?q=" + c_textid2 + "";
        /* disable trigger button, preventing multiple requests */
        $(this).attr("disabled", true);

        /* show requesting message */
        $("#div_ajax_show").html("requsting....");
        $("#input_ajax_data").val("");

        /* wait 2 seconds before sending ajax */
        setTimeout(function(){

            DoAjax(url, {todo : "exSucceed"},
                function(data, textStatus, jqXHR){
                    //console.log(data);
                    /*在這裡添加錄入表單更新的欄位與資料*/
                    $("#input_ajax_data").val(data);
                    $("input[name='c_surname']").val(data);
                    $("input[name='c_surname']").css("background","#FFFFBB");
                });

            /* enable trigger button */
            $("#button_ajax_load").attr("disabled", false);
        }, 2);

        setTimeout(function(){

            DoAjax(url2, {todo : "exSucceed"},
                function(data2, textStatus, jqXHR){
                    //console.log(data2);
                    /*在這裡添加錄入表單更新的欄位與資料*/
                    $("#input_ajax_data").val(data2);
                    $("input[name='c_mingzi']").val(data2);
                    $("input[name='c_mingzi']").css("background","#FFFFBB");
                    $("#pinyin_info").css("display","block");
                });

            /* enable trigger button */
            $("#button_ajax_load").attr("disabled", false);
        }, 2);

    });

    $(window).on('beforeunload', function(){
        var c_mingzi_chn = $("input[name='c_mingzi_chn']").val();
        var c_mingzi = $("input[name='c_mingzi']").val();
        if(c_mingzi_chn == '' || c_mingzi == '') {
            console.log('要離開視窗了，請您確認[名]和[Ming]是否填寫。');
            $("#check_info").css("display","block");
            $("input[name='c_mingzi_chn']").css("border-color","#a94442");
            $("input[name='c_mingzi_chn']").css("background","#FFECEC");
            $("input[name='c_mingzi']").css("border-color","#a94442");
            $("input[name='c_mingzi']").css("background","#FFECEC");
            return '要離開視窗了，請您確認[名]和[Ming]是否填寫。';
        }
    });

});
</script>
<!-- Javascript End -->
@endsection
