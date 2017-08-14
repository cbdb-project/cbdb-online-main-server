@extends('layouts.app')

@section('content')
<div class="container">
    <div class="row">
        <div class="col-md-8 col-md-offset-2">
            <div class="panel panel-default">
                <div class="panel-heading">人名查询</div>

                <div class="panel-body">
                    <div class="form-group">
                        <button class="btn btn-block btn-default">最近新增人名</button>
                    </div>
                    <div class="text-center">查询人物</div>
                    <div class="input-group">
                        <input type="text" class="form-control search-key" placeholder="Search">
                        <div class="input-group-btn">
                            <button class="btn btn-default search-name" type="submit">
                                <i class="glyphicon glyphicon-search"></i>
                            </button>
                        </div>
                    </div>
                    <div class="container">
                        @foreach ($names as $name)
                            <div>
                                <a href="{{ route('biogbasicinformation.edit',$name->c_personid) }}">{{ $name->c_name_chn.'('.$name->c_name.')' }}</a>
                            </div>
                        @endforeach
                    </div>

                    {{ $names->links() }}
                </div>
            </div>
        </div>
    </div>
</div>
@section('js')
    {{--<script type="text/javascript">--}}
        {{--$('.search-name').click(function () {--}}
            {{--var q = $('.search-key').val();--}}
            {{--$.get( "/api/biognames", { q: q } )--}}
                {{--.done(function( data ) {--}}
                    {{--console.log(data );--}}
                {{--});--}}
        {{--});--}}
    {{--</script>--}}
@endsection
@endsection
