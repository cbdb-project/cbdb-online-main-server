@extends('layouts.dashboard')

@section('content')
    <!-- SELECT2 EXAMPLE -->
    <div class="box box-default">
        <div class="box-header with-border">
            <h3 class="box-title">编码表</h3>

            <div class="box-tools pull-right">
                <button type="button" class="btn btn-box-tool" data-widget="collapse"><i class="fa fa-minus"></i></button>
            </div>
        </div>
        <!-- /.box-header -->
        <div class="box-body table-responsive no-padding">
            <table class="table table-hover table-condensed">
                <thead>
                <tr>
                    <th>表名</th>
                </tr>
                </thead>
                <tbody>
                @foreach($data as $item)
                    <tr><td><a href="/codes/{{ $item }}">{{ $item }}</a></td></tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <!-- /.box-body -->
    </div>
    <!-- /.box -->
@endsection
@section('js')

@endsection