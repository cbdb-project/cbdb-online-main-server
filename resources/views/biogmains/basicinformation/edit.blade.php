@extends('layouts.app')

@section('content')
    <div class="container">
        <div class="row">
            <div class="col-md-8 col-md-offset-2">
                @include('biogmains.banner')
                <div class="panel panel-default">
                    <div class="panel-heading">基本资料</div>

                    <div class="panel-body">
                        <form action="/basicinformation/{{ $basicinformation->c_personid }}" class="form-horizontal"
                              method="post">
                            {{ method_field('PATCH') }}
                            {{ csrf_field() }}
                            <div class="form-group">
                                <label for="c_persionid" class="col-sm-2 control-label">person id</label>
                                <div class="col-sm-10">
                                    <input type="text" name="c_personid" class="form-control"
                                           value="{{ $basicinformation->c_personid }}" disabled>
                                </div>
                            </div>
                            <div class="form-group">
                                <div class="{{ $errors->has('c_surname_chn') ? ' has-error' : '' }}">
                                    <label for="c_surname_chn" class="col-sm-2 control-label">姓</label>
                                    <div class="col-sm-4">
                                        <input type="text" name="c_surname_chn" class="form-control"
                                               value="{{ old('c_surname_chn') ? old('c_surname_chn') : $basicinformation->c_surname_chn }}">
                                        @if ($errors->has('c_surname_chn'))
                                            <span class="help-block">
                                            <strong>{{ $errors->first('c_surname_chn') }}</strong>
                                        </span>
                                        @endif
                                    </div>
                                </div>
                                <label for="c_surname" class="col-sm-2 control-label">Xing</label>
                                <div class="col-sm-4">
                                    <input type="text" name="c_surname" class="form-control"
                                           value="{{ old('c_surname') ? old('c_surname') : $basicinformation->c_surname }}">
                                    @if ($errors->has('c_surname'))
                                        <span class="help-block">
                                        <strong>{{ $errors->first('c_surname') }}</strong>
                                    </span>
                                    @endif
                                </div>
                            </div>
                            <div class="form-group">
                                <div class="{{ $errors->has('c_mingzi_chn') ? ' has-error' : '' }}">
                                    <label for="c_mingzi_chn" class="col-sm-2 control-label">名</label>
                                    <div class="col-sm-4">
                                        <input type="text" name="c_mingzi_chn" class="form-control"
                                               value="{{ old('c_mingzi_chn') ? old('c_mingzi_chn') : $basicinformation->c_mingzi_chn }}">
                                        @if ($errors->has('c_mingzi_chn'))
                                            <span class="help-block">
                                        <strong>{{ $errors->first('c_mingzi_chn') }}</strong>
                                    </span>
                                        @endif
                                    </div>
                                </div>
                                <div class="{{ $errors->has('c_mingzi') ? ' has-error' : '' }}">
                                    <label for="c_mingzi" class="col-sm-2 control-label">Ming</label>
                                    <div class="col-sm-4">
                                        <input type="text" name="c_mingzi" class="form-control"
                                               value="{{ old('c_mingzi') ? old('c_mingzi') : $basicinformation->c_mingzi }}">
                                        @if ($errors->has('c_mingzi'))
                                            <span class="help-block">
                                        <strong>{{ $errors->first('c_mingzi') }}</strong>
                                    </span>
                                        @endif
                                    </div>
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="c_surname_proper" class="col-sm-2 control-label">外文姓</label>
                                <div class="col-sm-4">
                                    <input type="text" name="c_surname_proper" class="form-control"
                                           value="{{ old('c_surname_proper') ? old('c_surname_proper') : $basicinformation->c_surname_proper }}">
                                    @if ($errors->has('c_surname_proper'))
                                        <span class="help-block">
                                        <strong>{{ $errors->first('c_surname_proper') }}</strong>
                                    </span>
                                    @endif
                                </div>
                                <label for="c_mingzi_proper" class="col-sm-2 control-label">外文名</label>
                                <div class="col-sm-4">
                                    <input type="text" name="c_mingzi_proper" class="form-control"
                                           value="{{ old('c_mingzi_proper') ? old('c_mingzi_proper') : $basicinformation->c_mingzi_proper }}">
                                    @if ($errors->has('c_mingzi_proper'))
                                        <span class="help-block">
                                        <strong>{{ $errors->first('c_mingzi_proper') }}</strong>
                                    </span>
                                    @endif
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="c_surname_rm" class="col-sm-2 control-label">外文Xing(羅馬字)</label>
                                <div class="col-sm-4">
                                    <input type="text" name="c_surname_rm" class="form-control"
                                           value="{{ old('c_surname_rm') ? old('c_surname_rm') : $basicinformation->c_surname_rm }}">
                                    @if ($errors->has('c_surname_rm'))
                                        <span class="help-block">
                                        <strong>{{ $errors->first('c_surname_rm') }}</strong>
                                    </span>
                                    @endif
                                </div>
                                <label for="c_mingzi_rm" class="col-sm-2 control-label">外文Ming(羅馬字)</label>
                                <div class="col-sm-4">
                                    <input type="text" name="c_mingzi_rm" class="form-control"
                                           value="{{ old('c_mingzi_rm') ? old('c_mingzi_rm') : $basicinformation->c_mingzi_rm }}">
                                    @if ($errors->has('c_mingzi_rm'))
                                        <span class="help-block">
                                        <strong>{{ $errors->first('c_mingzi_rm') }}</strong>
                                    </span>
                                    @endif
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="c_name_chn" class="col-sm-2 control-label">姓名(中)</label>
                                <div class="col-sm-10">
                                    <input type="text" name="c_name_chn" class="form-control"
                                           value="{{ $basicinformation->c_name_chn }}" disabled>
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="c_name" class="col-sm-2 control-label">姓名(英)</label>
                                <div class="col-sm-10">
                                    <input type="text" name="c_name" class="form-control"
                                           value="{{ $basicinformation->c_name }}" disabled>
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="" class="col-sm-2 control-label">外文全名</label>
                                <div class="col-sm-10">
                                    <input type="text" name="" class="form-control"
                                           value="{{ $basicinformation->c_name_proper }}" disabled>
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="" class="col-sm-2 control-label">外文XingMing</label>
                                <div class="col-sm-10">
                                    <input type="text" name="" class="form-control"
                                           value="{{ $basicinformation->c_name_rm }}" disabled>
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="c_female" class="col-sm-2 control-label">性别（原female）</label>
                                <div class="col-sm-4">
                                    <select class="form-control" name="c_female">
                                        <option value="0"></option>
                                        <option value="0" {{ $basicinformation->c_female == 0? 'selected': '' }}>0-男
                                        </option>
                                        <option value="1" {{ $basicinformation->c_female == 1? 'selected': '' }}>1-女
                                        </option>
                                    </select>
                                </div>
                                <label for="c_ethnicity_code" class="col-sm-2 control-label">種族/部族</label>
                                <div class="col-sm-4">
                                    <select-vue name="c_ethnicity_code" model="ethnicity" selected="{{ $basicinformation->c_ethnicity_code }}"></select-vue>
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="c_dy" class="col-sm-2 control-label">朝代(dy)</label>
                                <div class="col-sm-10">
                                    <select-vue name="c_dy" model="dynasty" selected="{{ $basicinformation->c_dy }}"></select-vue>
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="c_birthyear" class="col-sm-2 control-label">生年(birth year)</label>
                                <div class="col-sm-10">
                                    <div class="col-sm-6">
                                        <input type="number" name="c_birthyear" class="form-control"
                                               value="{{ $basicinformation->c_birthyear }}">
                                    </div>
                                    <div class="form-group col-sm-6">
                                        <label class="col-sm-3 control-label" for="">年号</label>
                                        <div class="col-sm-9">
                                            <select-vue name="c_by_nh_code" model="nianhao" selected="{{ $basicinformation->c_by_nh_code }}"></select-vue>
                                        </div>
                                    </div>
                                    <div class="clearfix"></div>
                                    <div class="col-sm-4">
                                        <input type="text" name="c_by_nh_year" class="form-control"
                                               value="{{ $basicinformation->c_by_nh_year }}">
                                    </div>
                                    <div class="col-sm-2">
                                        <label for="">年</label>
                                    </div>
                                    <label for="">时限</label>
                                    <input type="text" name="" class="form-control"
                                           value="{{ $basicinformation->c_by_range }}" disabled>
                                    <label for="">閏</label>
                                    <input type="text" name="" class="form-control"
                                           value="{{ $basicinformation->c_by_intercalary }}" disabled>
                                    <input type="text" name="" class="form-control"
                                           value="{{ $basicinformation->c_by_month }}" disabled>
                                    <label for="">月</label>
                                    <input type="text" name="" class="form-control"
                                           value="{{ $basicinformation->c_by_day }}" disabled>
                                    <label for="">日</label>
                                    <label for="">日(干支) </label>
                                    <input type="text" name="" class="form-control"
                                           value="{{ $basicinformation->c_by_day_gz }}" disabled>
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="c_deathyear" class="col-sm-2 control-label">卒年(death year)</label>
                                <div class="col-sm-10 form-inline">
                                    <input type="text" name="c_deathyear" class="form-control"
                                           value="{{ $basicinformation->c_deathyear }}" disabled>
                                    <label for="">年号</label>
                                    <input type="text" name="" class="form-control"
                                           value="{{ $basicinformation->deathYearNH->c_nianhao_chn }}" disabled>
                                    <input type="text" name="" class="form-control"
                                           value="{{ $basicinformation->c_dy_nh_year }}" disabled>
                                    <label for="">年</label>
                                    <input type="text" name="" class="form-control"
                                           value="{{ $basicinformation->c_dy_range }}" disabled>
                                    <label for="">閏</label>
                                    <input type="text" name="" class="form-control"
                                           value="{{ $basicinformation->c_dy_intercalary }}" disabled>
                                    <input type="text" name="" class="form-control"
                                           value="{{ $basicinformation->c_dy_month }}" disabled>
                                    <label for="">月</label>
                                    <input type="text" name="" class="form-control"
                                           value="{{ $basicinformation->c_dy_day }}" disabled>
                                    <label for="">日</label>
                                    <label for="">日(干支) </label>
                                    <input type="text" name="" class="form-control"
                                           value="{{ $basicinformation->c_dy_day_gz }}" disabled>
                                </div>
                            </div>
                            <div class="form-group{{ $errors->has('c_index_year') ? ' has-error' : '' }}">
                                <label for="c_index_year" class="col-sm-2 control-label">指數年(index year)</label>
                                <div class="col-sm-10">
                                    <input type="number" name="c_index_year" class="form-control"
                                           value="{{ $basicinformation->c_index_year }}">
                                    @if ($errors->has('c_index_year'))
                                        <span class="help-block">
                                        <strong>{{ $errors->first('c_index_year') }}</strong>
                                    </span>
                                    @endif
                                </div>
                            </div>
                            <div class="form-group{{ $errors->has('c_death_age') ? ' has-error' : '' }}">
                                <label for="c_death_age" class="col-sm-2 control-label">享年(death_age)</label>
                                <div class="col-sm-4">
                                    <input type="number" name="c_death_age" class="form-control"
                                           value="{{ $basicinformation->c_death_age }}">
                                    @if ($errors->has('c_death_age'))
                                        <span class="help-block">
                                        <strong>{{ $errors->first('c_death_age') }}</strong>
                                    </span>
                                    @endif
                                </div>
                                <label for="" class="col-sm-2 control-label">范围</label>
                                <div class="col-sm-4">
                                    <select class="form-control" name="c_death_age_range">
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
                            <div class="form-group">
                                <label for="c_fl_earliest_year"
                                       class="col-sm-2 control-label">在世始年(fl_earliest_year)</label>
                                <div class="col-sm-10 form-inline">
                                    <input type="text" name="c_fl_earliest_year" class="form-control"
                                           value="{{ $basicinformation->c_fl_earliest_year }}" disabled>
                                    <label for="">年号</label>
                                    <input type="text" name="" class="form-control" value="" disabled>
                                    <input type="text" name="" class="form-control" value="" disabled>
                                    <label for="">年</label>
                                    在世始年注
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="c_fl_latest_year"
                                       class="col-sm-2 control-label">在世終年(fl_latest_year)</label>
                                <div class="col-sm-10 form-inline">
                                    <input type="text" name="c_fl_latest_year" class="form-control"
                                           value="{{ $basicinformation->c_fl_latest_year }}" disabled>
                                    <label for="">年号</label>
                                    <input type="text" name="" class="form-control" value="" disabled>
                                    <input type="text" name="" class="form-control" value="" disabled>
                                    <label for="">年</label>
                                    在世始年注
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="c_choronym_code" class="col-sm-2 control-label">郡望(choronym_code)</label>
                                <div class="col-sm-10">
                                    <select-vue name="c_choronym_code" model="choronym" selected="{{ $basicinformation->c_choronym_code }}"></select-vue>
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="c_household_status_code" class="col-sm-2 control-label">戶籍(c_household_status)</label>
                                <div class="col-sm-10">
                                    <input type="text" name="c_household_status_code" class="form-control"
                                           value="{{ $basicinformation->c_household_status_code }}" disabled>
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="c_notes" class="col-sm-2 control-label">注</label>
                                <div class="col-sm-10">
                                    <textarea class="form-control" name="c_notes" id="" cols="30"
                                              rows="5">{{ $basicinformation->c_notes }}</textarea>
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="TTSMQ_db_ID"
                                       class="col-sm-2 control-label">中央研究院明清人名權威檔案(TTS-MQ權威號) </label>
                                <div class="col-sm-10">
                                    <input type="text" name="TTSMQ_db_ID" class="form-control"
                                           value="{{ $basicinformation->TTSMQ_db_ID }}">
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="MQWWLink" class="col-sm-2 control-label">明清婦女著作(MQWW Link)</label>
                                <div class="col-sm-10">
                                    <input type="text" name="MQWWLink" class="form-control"
                                           value="{{ $basicinformation->MQWWLink }}">
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="KyotoLink" class="col-sm-2 control-label">京都大學唐代人物知識(Kyoto Link)</label>
                                <div class="col-sm-10">
                                    <input type="text" name="KyotoLink" class="form-control"
                                           value="{{ $basicinformation->KyotoLink }}">
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="" class="col-sm-2 control-label">建檔</label>
                                <div class="col-sm-10">
                                    <input type="text" name="" class="form-control"
                                           value="{{ $basicinformation->c_created_by.'/'.$basicinformation->c_created_date }}"
                                           disabled>
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="" class="col-sm-2 control-label">更新</label>
                                <div class="col-sm-10">
                                    <input type="text" name="" class="form-control"
                                           value="{{ $basicinformation->c_modified_by.'/'.$basicinformation->c_modified_date }}"
                                           disabled>
                                </div>
                            </div>
                            <div class="form-group">
                                <div class="col-sm-offset-2 col-sm-10">
                                    <button type="submit" class="btn btn-default">Submit</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
@section('js')
    <script>
        $("select[name^='c_']").select2();
    </script>

@endsection
@endsection
