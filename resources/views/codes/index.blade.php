@extends('layouts.dashboard-v3')

@section('content')
    <div class="card card-default">
        <div class="card-header">
            <h3 class="card-title">全部表格</h3>

            <div class="card-tools">
                <button type="button" class="btn btn-tool" data-card-widget="collapse">
                    <i class="fas fa-minus"></i>
                </button>
            </div>
        </div>
        <div class="card-body table-responsive p-0">
            <table class="table table-hover table-sm">
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
    </div>
@endsection

@section('js')
@endsection
