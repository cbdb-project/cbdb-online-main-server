@extends('layouts.app')

@section('content')
    <div class="container">
        <div class="row">
            <div class="col-md-8 col-md-offset-2">
                <div class="panel panel-default">
                    <div class="panel-heading">Edit Person Information</div>

                    <div class="panel-body">
                        <form action="/biogbasicinformation" method="post">
                            {!! csrf_field() !!}
                            <div class="form-group">
                                <label for="c_choronym_desc">拼音</label>
                                <input type="text" name="c_choronym_desc" class="form-control" placeholder="Title" id="title">
                            </div>
                            <div class="form-group">
                                <label for="c_choronym_chn">名称</label>
                                <input type="text" name="c_choronym_chn" class="form-control" placeholder="Title" id="title">
                            </div>
                            <div class="form-group"><button class="btn btn-success pull-right" type="submit">Submit</button></div>

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
