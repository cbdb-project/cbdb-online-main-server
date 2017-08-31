@extends('layouts.app')

@section('content')
    <div class="container">
        <div class="row">
            <div class="col-md-8 col-md-offset-2">

                <div class="panel panel-default">
                    <div class="panel-heading">地址编码表</div>
                    <div class="panel-body">
                        <div class="panel-body">
                            <address-code-list></address-code-list>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@section('js')

@endsection
@endsection
