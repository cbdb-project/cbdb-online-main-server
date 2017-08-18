@extends('layouts.app')

@section('content')
    <div class="container">
        <div class="row">
            <div class="col-md-8 col-md-offset-2">
                @include('biogmains.banner')
                <div class="panel panel-default">
                    <div class="panel-heading">基本资料</div>

                    <div class="panel-body">

                        <form action="/basicinformation/{{ $basicinformation->c_personid }}" method="post">
                            {{ method_field('PATCH') }}
                            {{ csrf_field() }}
                            <div class="form-group">
                                <label for="c_persionid">person id</label>
                                <input type="text" name="c_personid" class="form-control" value="{{ $basicinformation->c_personid }}" disabled>
                            </div>
                            <div class="form-group col-md-6{{ $errors->has('c_surname_chn') ? ' has-error' : '' }}">
                                <label for="c_surname_chn">姓</label>
                                <div class="form-group">
                                    <input type="text" name="c_surname_chn" class="form-control" value="{{ old('c_surname_chn') ? old('c_surname_chn') : $basicinformation->c_surname_chn }}">
                                    @if ($errors->has('c_surname_chn'))
                                        <span class="help-block">
                                        <strong>{{ $errors->first('c_surname_chn') }}</strong>
                                    </span>
                                    @endif
                                </div>
                            </div>
                            <div class="form-group col-md-6">
                                <label for="c_surname">Xing</label>
                                <div class="form-group">
                                    <input type="text" name="c_surname" class="form-control" value="{{ $basicinformation->c_surname }}">
                                </div>
                            </div>
                            <div class="form-group col-md-6">
                                <label for="c_mingzi_chn">名</label>
                                <input type="text" name="c_mingzi_chn" class="form-control" value="{{ $basicinformation->c_mingzi_chn }}">
                            </div>
                            <div class="form-group col-md-6">
                                <label for="c_mingzi">Ming</label>
                                <input type="text" name="c_mingzi" class="form-control" value="{{ $basicinformation->c_mingzi }}">
                            </div>
                            <div class="form-group col-md-6">
                                <label for="c_surname_proper">外文姓</label>
                                <div class="form-group">
                                    <input type="text" name="c_surname_proper" class="form-control" value="{{ $basicinformation->c_surname_proper }}">
                                </div>
                            </div>
                            <div class="form-group col-md-6">
                                <label for="c_mingzi_proper">外文名</label>
                                <div class="form-group">
                                    <input type="text" name="c_mingzi_proper" class="form-control" value="{{ $basicinformation->c_mingzi_proper }}">
                                </div>
                            </div>
                            <div class="form-group col-md-6">
                                <label for="c_surname_rm">外文Xing(羅馬字)</label>
                                <div class="form-group">
                                    <input type="text" name="c_surname_rm" class="form-control" value="{{ $basicinformation->c_surname_rm }}">
                                </div>
                            </div>
                            <div class="form-group col-md-6">
                                <label for="c_mingzi_rm">外文Ming(羅馬字)</label>
                                <div class="form-group">
                                    <input type="text" name="c_mingzi_rm" class="form-control" value="{{ $basicinformation->c_mingzi_rm }}">
                                </div>
                            </div>
                            <div class="form-group col-md-6">
                                <label for="">姓名(中)</label>
                                <div class="form-group">
                                    <input type="text" name="" class="form-control" value="{{ $basicinformation->c_name_chn }}" disabled>
                                </div>
                            </div>
                            <div class="form-group col-md-6">
                                <label for="">姓名(英)</label>
                                <div class="form-group">
                                    <input type="text" name="" class="form-control" value="{{ $basicinformation->c_name }}" disabled>
                                </div>
                            </div>
                            <div class="form-group col-md-6">
                                <label for="c_name_proper">外文全名</label>
                                <div class="form-group">
                                    <input type="text" name="c_name_proper" class="form-control" value="{{ $basicinformation->c_name_proper }}" disabled>
                                </div>
                            </div>
                            <div class="form-group col-md-6">
                                <label for="c_name_rm">外文XingMing</label>
                                <div class="form-group">
                                    <input type="text" name="c_name_rm" class="form-control" value="{{ $basicinformation->c_name_rm }}" disabled>
                                </div>
                            </div>
                            <div class="form-group col-md-6">
                                <label for="c_female">性别（原female）</label>
                                <select class="form-control" name="c_female">
                                    <option value="0"></option>
                                    <option value="0" {{ $basicinformation->c_female == 0? 'selected': '' }}>0-男</option>
                                    <option value="1" {{ $basicinformation->c_female == 1? 'selected': '' }}>1-女</option>
                                </select>
                            </div>
                            <div class="form-group col-md-6">
                                <label for="c_ethnicity_code">種族/部族</label>
                                <div class="form-group">
                                    <input type="text" name="c_ethnicity_code" class="form-control" value="{{ $basicinformation->c_ethnicity_code.' '.$basicinformation->ethnicity->c_name_chn }}" disabled>
                                </div>
                            </div>
                            <div class="form-group col-md-6">
                                <label for="c_dy">朝代(dy)</label>
                                <div class="form-group">
                                    <input type="text" name="c_dy" class="form-control" value="{{ $basicinformation->dynasty->c_dynasty_chn }}" disabled>
                                </div>
                            </div>
                            <div class="clearfix"></div>
                            <div class="form-group">
                                <div class="form-inline">
                                    <label for="c_birthyear">生年(birth year)</label>
                                    <input type="text" name="c_birthyear" class="form-control" value="{{ $basicinformation->c_birthyear }}" disabled>
                                    <label for="">年号</label>
                                    <input type="text" name="" class="form-control" value="{{ $basicinformation->birthYearNH->c_nianhao_chn }}" disabled>
                                    <input type="text" name="" class="form-control" value="{{ $basicinformation->c_by_nh_year }}" disabled>
                                    <label for="">年</label>
                                    <label for="">时限</label>
                                    <input type="text" name="" class="form-control" value="{{ $basicinformation->c_by_range }}" disabled>
                                    <label for="">閏</label>
                                    <input type="text" name="" class="form-control" value="{{ $basicinformation->c_by_intercalary }}" disabled>
                                    <input type="text" name="" class="form-control" value="{{ $basicinformation->c_by_month }}" disabled>
                                    <label for="">月</label>
                                    <input type="text" name="" class="form-control" value="{{ $basicinformation->c_by_day }}" disabled>
                                    <label for="">日</label>
                                    <label for="">日(干支) </label>
                                    <input type="text" name="" class="form-control" value="{{ $basicinformation->c_by_day_gz }}" disabled>
                                </div>
                            </div>
                            <div class="form-group">
                                <div class="form-inline">
                                    <label for="c_deathyear">卒年(death year)</label>
                                    <input type="text" name="c_deathyear" class="form-control" value="{{ $basicinformation->c_deathyear }}" disabled>
                                    <label for="">年号</label>
                                    <input type="text" name="" class="form-control" value="{{ $basicinformation->deathYearNH->c_nianhao_chn }}" disabled>
                                    <input type="text" name="" class="form-control" value="{{ $basicinformation->c_dy_nh_year }}" disabled>
                                    <label for="">年</label>
                                    <input type="text" name="" class="form-control" value="{{ $basicinformation->c_dy_range }}" disabled>
                                    <label for="">閏</label>
                                    <input type="text" name="" class="form-control" value="{{ $basicinformation->c_dy_intercalary }}" disabled>
                                    <input type="text" name="" class="form-control" value="{{ $basicinformation->c_dy_month }}" disabled>
                                    <label for="">月</label>
                                    <input type="text" name="" class="form-control" value="{{ $basicinformation->c_dy_day }}" disabled>
                                    <label for="">日</label>
                                    <label for="">日(干支) </label>
                                    <input type="text" name="" class="form-control" value="{{ $basicinformation->c_dy_day_gz }}" disabled>
                                </div>
                            </div>
                            <div class="form-group col-md-6">
                                <label for="c_index_year">指數年(index year)</label>
                                <div class="form-group">
                                    <input type="text" name="c_index_year" class="form-control" value="{{ $basicinformation->c_index_year }}" disabled>
                                </div>
                            </div>
                            <div class="form-group col-md-6">
                                <label for="c_death_age">享年(death_age)</label>
                                <div class="form-group">
                                    <input type="text" name="c_death_age" class="form-control" value="{{ $basicinformation->c_death_age }}" disabled>
                                </div>
                            </div>
                            <div class="clearfix"></div>
                            <div class="form-group">
                                <div class="form-inline">
                                    <label for="c_death_age">在世始年(fl_earliest_year)</label>
                                    <input type="text" name="c_death_age" class="form-control" value="{{ $basicinformation->c_death_age }}" disabled>
                                </div>
                            </div>
                            <div class="form-group">
                                <div class="form-inline">
                                    <label for="c_death_age">在世終年(fl_latest_year)</label>
                                    <input type="text" name="c_death_age" class="form-control" value="{{ $basicinformation->c_death_age }}" disabled>
                                </div>
                            </div>
                            <div class="form-group col-md-6">
                                <label for="c_choronym_code">郡望(choronym_code)</label>
                                <div class="form-group">
                                    <input type="text" name="c_choronym_code" class="form-control" value="{{ $basicinformation->choronym->c_choronym_chn }}" disabled>
                                </div>
                            </div>
                            <div class="form-group col-md-6">
                                <label for="c_household_status_code">戶籍(c_household_status)</label>
                                <div class="form-group">
                                    <input type="text" name="c_household_status_code" class="form-control" value="{{ $basicinformation->c_household_status_code }}" disabled>
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="c_notes">注</label>
                                <div class="form-group">
                                    <textarea class="form-control" name="c_notes" id="" cols="30" rows="5">{{ $basicinformation->c_notes }}</textarea>
                                </div>
                            </div>
                            <div class="form-group col-md-6">
                                <label for="TTSMQ_db_ID">中央研究院明清人名權威檔案(TTS-MQ權威號)	</label>
                                <div class="form-group">
                                    <input type="text" name="TTSMQ_db_ID" class="form-control" value="{{ $basicinformation->TTSMQ_db_ID }}" disabled>
                                </div>
                            </div>
                            <div class="form-group col-md-6">
                                <label for="MQWWLink">明清婦女著作(MQWW Link)</label>
                                <div class="form-group">
                                    <input type="text" name="MQWWLink" class="form-control" value="{{ $basicinformation->MQWWLink }}" disabled>
                                </div>
                            </div>
                            <div class="form-group col-md-6">
                                <label for="KyotoLink">京都大學唐代人物知識(Kyoto Link)</label>
                                <div class="form-group">
                                    <input type="text" name="KyotoLink" class="form-control" value="{{ $basicinformation->KyotoLink }}" disabled>
                                </div>
                            </div>
                            <div class="form-group col-md-6">
                                <label for="c_household_status_code">建檔</label>
                                <div class="form-group">
                                    <input type="text" name="c_household_status_code" class="form-control" value="{{ $basicinformation->c_created_by.'/'.$basicinformation->c_created_date }}" disabled>
                                </div>
                            </div>
                            <div class="form-group col-md-6">
                                <label for="c_household_status_code">更新</label>
                                <div class="form-group">
                                    <input type="text" name="c_household_status_code" class="form-control" value="{{ $basicinformation->c_modified_by.'/'.$basicinformation->c_modified_date }}" disabled>
                                </div>
                            </div>
                            <div class="form-group">
                                <button class="btn btn-primary btn-block pull-right" type="submit">Submit</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
@section('js')
    <script type="text/javascript">

    </script>
@endsection
@endsection
