<!DOCTYPE html>
<!--
This is a starter template page. Use this page to start your new project from
scratch. This page gets rid of all links and provides the needed markup only.
-->
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <!-- CSRF Token -->
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'Laravel') }}</title>
    <!-- Tell the browser to be responsive to screen width -->
    <meta content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no" name="viewport">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.5.0/css/font-awesome.min.css">
    <!-- Ionicons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/ionicons/2.0.1/css/ionicons.min.css">

    <!-- Styles -->
    <link href="{{ mix('css/app.css') }}" rel="stylesheet">

    <!-- HTML5 Shim and Respond.js IE8 support of HTML5 elements and media queries -->
    <!-- WARNING: Respond.js doesn't work if you view the page via file:// -->
    <!--[if lt IE 9]>
    <script src="https://oss.maxcdn.com/html5shiv/3.7.3/html5shiv.min.js"></script>
    <script src="https://oss.maxcdn.com/respond/1.4.2/respond.min.js"></script>
    <![endif]-->
</head>
<!--
BODY TAG OPTIONS:
=================
Apply one or more of the following classes to get the
desired effect
|---------------------------------------------------------|
| SKINS         | skin-blue                               |
|               | skin-black                              |
|               | skin-purple                             |
|               | skin-yellow                             |
|               | skin-red                                |
|               | skin-green                              |
|---------------------------------------------------------|
|LAYOUT OPTIONS | fixed                                   |
|               | layout-boxed                            |
|               | layout-top-nav                          |
|               | sidebar-collapse                        |
|               | sidebar-mini                            |
|---------------------------------------------------------|
-->
<body class="hold-transition skin-blue sidebar-mini">
<div class="wrapper" id="app">

    <!-- Header -->
@include('layouts.header')

<!-- Sidebar -->
@include('layouts.sidebar')

<!-- Content Wrapper. Contains page content -->
    <div class="content-wrapper">
        <div class="content-alert">
            @include('flash::message')
        </div>
        <!-- Content Header (Page header) -->
        <section class="content-header">
            <h1>
                {{ $page_title ?? 'Page Title' }}
                <small>{{ $page_description ?? null }}</small>
            </h1>
            <ol class="breadcrumb">
                @if(isset($breadcrumb_home))
                    <li><a href="/basicinformation"><i class="fa fa-dashboard"></i> {{ $breadcrumb_home }}</a></li>
                @endif
                <li class="active"><a href="{{ $page_url ?? '#'}}">{{ $page_title }}</a></li>
                {!! $archer ?? '' !!}
            </ol>
        </section>

        <!-- Main content -->
        <section class="content">

            <!-- Your Page Content Here -->
            @yield('content')

            @php
                $canViewQueryDetails = Auth::check() && Auth::user()->isAdmin();
            @endphp

            @if(!empty($queryProfileSummary['count']))
                <p class="text-muted" style="margin-top: 20px;">
                    本次查詢共 {{ $queryProfileSummary['count'] }} 筆，耗時 {{ number_format($queryProfileSummary['time_ms'], 2) }} ms
                    @if($canViewQueryDetails)
                        <a href="#" data-toggle="modal" data-target="#query-profile-modal" style="margin-left: 8px;">查看詳細</a>
                    @endif
                </p>
            @endif

        </section>
        <!-- /.content -->
    </div>
    <!-- /.content-wrapper -->

    <!-- Footer -->
@include('layouts.footer')

@if(!empty($queryProfileSummary['count']) && $canViewQueryDetails)
    <div class="modal fade" id="query-profile-modal" tabindex="-1" role="dialog" aria-labelledby="queryProfileModalLabel">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                    <h4 class="modal-title" id="queryProfileModalLabel">SQL 查詢明細</h4>
                </div>
                <div class="modal-body">
                    <p class="text-muted">
                        共 {{ $queryProfileSummary['count'] }} 筆查詢，累計 {{ number_format($queryProfileSummary['time_ms'], 2) }} ms。
                        以下數據依執行順序列出，時間單位為毫秒。
                    </p>
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped table-condensed">
                            <thead>
                            <tr>
                                <th style="width: 60px;">#</th>
                                <th style="width: 120px;">耗時 (ms)</th>
                                <th>SQL</th>
                                <th style="width: 220px;">綁定參數</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach(array_slice($queryProfileSummary['queries'], 0, 100) as $index => $query)
                                <tr>
                                    <td>{{ $index + 1 }}</td>
                                    <td>{{ number_format($query['time'], 2) }}</td>
                                    <td><code style="white-space: pre-wrap; word-break: break-all;">{{ $query['sql'] }}</code></td>
                                    <td><code style="white-space: pre-wrap; word-break: break-all;">{{ $query['bindings_json'] }}</code></td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                        @if(count($queryProfileSummary['queries']) > 100)
                            <p class="text-muted">僅顯示前 100 筆查詢。</p>
                        @endif
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal">關閉</button>
                </div>
            </div>
        </div>
    </div>
@endif

    <!-- Control Sidebar -->
    <aside class="control-sidebar control-sidebar-dark">
        <!-- Create the tabs -->
        <ul class="nav nav-tabs nav-justified control-sidebar-tabs">
            <li class="active"><a href="#control-sidebar-home-tab" data-toggle="tab"><i class="fa fa-home"></i></a></li>
            <li><a href="#control-sidebar-settings-tab" data-toggle="tab"><i class="fa fa-gears"></i></a></li>
        </ul>
        <!-- Tab panes -->
        <div class="tab-content">
            <!-- Home tab content -->
            <div class="tab-pane active" id="control-sidebar-home-tab">
                <h3 class="control-sidebar-heading">Recent Activity</h3>
                <ul class="control-sidebar-menu">
                    <li>
                        <a href="javascript:;">
                            <i class="menu-icon fa fa-birthday-cake bg-red"></i>

                            <div class="menu-info">
                                <h4 class="control-sidebar-subheading">Develop Main Server</h4>

                                <p>Will be 23 on Sep 15th</p>
                            </div>
                        </a>
                    </li>
                </ul>
                <!-- /.control-sidebar-menu -->

                <h3 class="control-sidebar-heading">Tasks Progress</h3>
                <ul class="control-sidebar-menu">
                    <li>
                        <a href="javascript:;">
                            <h4 class="control-sidebar-subheading">
                                Main Server
                                <span class="pull-right-container">
                  <span class="label label-danger pull-right">70%</span>
                </span>
                            </h4>

                            <div class="progress progress-xxs">
                                <div class="progress-bar progress-bar-danger" style="width: 70%"></div>
                            </div>
                        </a>
                    </li>
                </ul>
                <!-- /.control-sidebar-menu -->

            </div>
            <!-- /.tab-pane -->
            <!-- Stats tab content -->
            <div class="tab-pane" id="control-sidebar-stats-tab">Stats Tab Content</div>
            <!-- /.tab-pane -->
            <!-- Settings tab content -->
            <div class="tab-pane" id="control-sidebar-settings-tab">
                <form method="post">
                    <h3 class="control-sidebar-heading">General Settings</h3>

                    <div class="form-group">
                        <label class="control-sidebar-subheading">
                            Report panel usage
                            <input type="checkbox" class="pull-right" checked>
                        </label>

                        <p>
                            Some information about this general settings option
                        </p>
                    </div>
                    <!-- /.form-group -->
                </form>
            </div>
            <!-- /.tab-pane -->
        </div>
    </aside>
    <!-- /.control-sidebar -->
    <!-- Add the sidebar's background. This div must be placed
         immediately after the control sidebar -->
    <div class="control-sidebar-bg"></div>
</div>
<!-- ./wrapper -->

<!-- Scripts -->
<script src="{{ mix('js/app.js') }}"></script>
@yield('js')
@stack('scripts')
<script>
    $('#flash-overlay-modal').modal();
</script>
</body>
</html>
