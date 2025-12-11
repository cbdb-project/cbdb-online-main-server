<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'Laravel') }}</title>

    <!-- Google Font: Source Sans Pro -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <!-- Ionicons -->
    <link rel="stylesheet" href="https://code.ionicframework.com/ionicons/2.0.1/css/ionicons.min.css">
    <!-- AdminLTE -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">

    <!-- Custom styles (不包含 v2 的 AdminLTE CSS，避免冲突) -->
    <style>
        html, body {
            overscroll-behavior-x: none;
            touch-action: pan-y;
        }
        .content-alert {
            padding: 10px;
        }
        .table-scroll-x {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        .table-scroll-x table {
            min-width: 720px;
        }
        .view-table-responsive table {
            min-width: 100%;
            width: max-content;
        }
        .view-table-responsive th,
        .view-table-responsive td {
            min-width: 160px;
            max-width: 320px;
            white-space: normal;
            word-break: break-word;
        }

        /* 使用 AdminLTE v2 的蓝色主题 #3c8dbc */
        :root {
            --primary: #3c8dbc;
            --primary-hover: #357ca5;
            --primary-dark: #2e6c9e;
        }

        /* Sidebar primary 颜色 */
        .sidebar-dark-primary .nav-sidebar > .nav-item > .nav-link.active,
        .sidebar-light-primary .nav-sidebar > .nav-item > .nav-link.active {
            background-color: #3c8dbc;
            color: #fff;
        }

        /* Sidebar hover 效果 */
        .sidebar-dark-primary .nav-sidebar > .nav-item:hover > .nav-link,
        .sidebar-dark-primary .nav-sidebar > .nav-item > .nav-link:focus {
            background-color: #357ca5;
            color: #fff;
        }

        /* Brand link hover */
        .brand-link:hover {
            color: #fff;
        }

        /* User header 背景色 */
        .user-header {
            background-color: #3c8dbc !important;
        }

        /* 链接颜色 */
        a {
            color: #3c8dbc;
        }

        a:hover {
            color: #357ca5;
        }

        /* 按钮颜色 */
        .btn-primary {
            background-color: #3c8dbc;
            border-color: #3c8dbc;
        }

        .btn-primary:hover,
        .btn-primary:focus,
        .btn-primary:active {
            background-color: #357ca5;
            border-color: #2e6c9e;
        }

        /* Badge primary */
        .badge-primary {
            background-color: #3c8dbc;
        }

        /* 面包屑 active */
        .breadcrumb-item.active {
            color: #6c757d;
        }

        /* 收縮狀態 hover 展開時，讓文字與常態展開一致可換行且保持預期寬度 */
        .sidebar-mini.sidebar-collapse .main-sidebar:hover .nav-sidebar .nav-link p {
            display: inline-block;
            width: calc(100% - 34px);
            white-space: normal;
            word-break: break-word;
        }

        /* 控制側邊欄在 hover 展開時的高度與捲動，避免底部出現過多空白 */
        .main-sidebar .sidebar {
            height: calc(100vh - 3.5rem);
            overflow-y: auto;
        }
        .sidebar-mini.sidebar-collapse .main-sidebar:hover .sidebar {
            height: calc(100vh - 3.5rem);
            overflow-y: auto;
            overflow-x: hidden;
        }

        /* Brand logo 只在 sidebar 收缩时显示 */
        .sidebar-mini .brand-image {
            display: none;
        }
        .sidebar-mini.sidebar-collapse .brand-image {
            display: inline;
        }

        /* Pagination 链接颜色统一为 v2 蓝色主题 */
        .pagination .page-link {
            color: #3c8dbc;
        }
        .pagination .page-link:hover {
            color: #357ca5;
        }
        .pagination .page-item.active .page-link {
            background-color: #3c8dbc;
            border-color: #3c8dbc;
        }

        /* 修复移动版翻页按钮位置 */
        @media (max-width: 767.98px) {
            .float-right {
                float: none !important;
                display: flex;
                justify-content: center;
                margin-top: 1rem;
            }
        }

        /* 修复移动版下方留白问题 */
        @media (max-width: 767.98px) {
            .wrapper {
                min-height: 100vh;
            }
            .content-wrapper {
                min-height: calc(100vh - 3.5rem - 3.5rem) !important;
            }
            .main-sidebar {
                bottom: 0 !important;
            }
        }
    </style>
</head>
<body class="hold-transition sidebar-mini">
<div class="wrapper" id="app">

    <!-- Navbar -->
    @include('layouts.header-v3')
    <!-- /.navbar -->

    <!-- Main Sidebar Container -->
    @include('layouts.sidebar-v3')

    <!-- Content Wrapper. Contains page content -->
    <div class="content-wrapper">
        <!-- Flash messages -->
        <div class="content-alert">
            @include('flash::message')
        </div>

        <!-- Content Header (Page header) -->
        <div class="content-header">
            <div class="container-fluid">
                <div class="row mb-2">
                    <div class="col-sm-6">
                        <h1 class="m-0">{{ $page_title ?? 'Page Title' }}</h1>
                        @if(!empty($page_description))
                            <small class="text-muted">{{ $page_description }}</small>
                        @endif
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            @if(isset($breadcrumb_home))
                                <li class="breadcrumb-item"><a href="/basicinformation"><i class="fas fa-tachometer-alt"></i> {{ $breadcrumb_home }}</a></li>
                            @endif
                            <li class="breadcrumb-item active">{{ $page_title ?? '' }}</li>
                            {!! $archer ?? '' !!}
                        </ol>
                    </div>
                </div>
            </div>
        </div>
        <!-- /.content-header -->

        <!-- Main content -->
        <section class="content">
            <div class="container-fluid">
                @yield('content')
            </div>

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

    <!-- Query Profile Modal -->
    @if(!empty($queryProfileSummary['count']) && $canViewQueryDetails)
        <div class="modal fade" id="query-profile-modal" tabindex="-1" role="dialog" aria-labelledby="queryProfileModalLabel">
            <div class="modal-dialog modal-lg" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h4 class="modal-title" id="queryProfileModalLabel">SQL 查詢明細</h4>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted">
                            共 {{ $queryProfileSummary['count'] }} 筆查詢，累計 {{ number_format($queryProfileSummary['time_ms'], 2) }} ms。
                            以下數據依執行順序列出，時間單位為毫秒。
                        </p>
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped table-sm">
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
        <!-- Control sidebar content goes here -->
    </aside>
    <!-- /.control-sidebar -->
</div>
<!-- ./wrapper -->

<!-- jQuery -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<!-- Bootstrap 4 -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.0/dist/js/bootstrap.bundle.min.js"></script>
<!-- AdminLTE App -->
<script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/js/adminlte.min.js"></script>

<!-- Custom Scripts (暫停載入 v2 編譯資產，避免與 AdminLTE v3 衝突) -->
{{-- <script src="{{ mix('js/app.js') }}"></script> --}}
@yield('js')
@stack('scripts')
<script>
    $('#flash-overlay-modal').modal();
</script>
</body>
</html>
