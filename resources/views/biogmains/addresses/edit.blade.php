@extends('layouts.app')

@section('content')
    <div class="container">
        <div class="row">
            <div class="col-md-8 col-md-offset-2">
                @include('biogmains.banner')
                <div class="panel panel-default">
                    <div class="panel-heading">地址 Addresses</div>

                    <div class="panel-body">

                        <form action="/basicinformation/" method="post">
                            {{ method_field('PUT') }}
                            {{ csrf_field() }}
                            <div class="form-group">
                                <label for="c_persionid">person id</label>
                                <input type="text" name="c_personid" class="form-control" value="" disabled>
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
@endsection
@section('js')
    <script type="text/javascript">

    </script>
@endsection