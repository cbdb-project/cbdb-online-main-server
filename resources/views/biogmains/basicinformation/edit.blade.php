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
                            <div class="form-group">
                                <button class="btn btn-primary pull-right" type="submit">Submit</button>
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
