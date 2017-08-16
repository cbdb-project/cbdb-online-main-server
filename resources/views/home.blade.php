@extends('layouts.app')

@section('content')
<div class="container">
    <div class="row">
        <div class="col-md-8 col-md-offset-2">
            <div class="panel panel-default">
                <div class="panel-heading">人名查询</div>
                <div class="panel-body">
                    <example></example>
                    {{--<div class="form-group">--}}
                        {{--<button class="btn btn-block btn-default">最近新增人名</button>--}}
                    {{--</div>--}}
                    <name-list></name-list>
                    <div class="container">
                        @foreach ($names as $name)
                            <div>
                                <a href="{{ route('basicinformation.edit',$name->c_personid) }}">{{ $name->c_name_chn.'('.$name->c_name.')' }}</a>
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
