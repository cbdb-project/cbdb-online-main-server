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

    <!-- Vite 加载完成前的回调队列 -->
    <script>
        window.viteReadyCallbacks = [];
        window.onViteReady = function(fn) {
            if (window.viteReady) {
                fn();
            } else {
                window.viteReadyCallbacks.push(fn);
            }
        };
    </script>

    <!-- Modern World: Vite bundles (AdminLTE v3 + jQuery + Bootstrap + Vue 3) -->
    @vite(['resources/js/app.js'])
    @stack('styles')

    <!-- Custom styles (功能性样式，不包含 AdminLTE 主题覆盖) -->
    <style>
        /* 基础布局样式 */
        html, body {
            overscroll-behavior-x: none;
            touch-action: pan-y;
        }

        .wrapper {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .content-wrapper {
            flex: 1 0 auto;
        }

        .content-alert {
            padding: 10px;
        }

        /* 表格响应式滚动 */
        .table-scroll-x {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        .table-scroll-x table {
            min-width: 720px;
        }

        /* View 表格响应式样式 */
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

        /* Navbar breadcrumb 分隔符 */
        .navbar .breadcrumb-item + .breadcrumb-item::before {
            content: "/";
            padding-right: 0.5rem;
            padding-left: 0.5rem;
            color: #6c757d;
        }

        /* Desktop: 固定側邊欄並獨立滾動，右側內容獨立滾動 */
        @media (min-width: 992px) {
            .main-sidebar {
                position: fixed;
                height: 100vh;
                overflow-y: auto;
            }

            .content-wrapper,
            .main-footer {
                margin-left: 250px;
            }

            body.sidebar-collapse .content-wrapper,
            body.sidebar-collapse .main-footer {
                margin-left: 80px;
            }
        }

        /* Select2 高度修复 - 与 Bootstrap 4 form-control 保持一致 */
        .select2-container--bootstrap4 .select2-selection--single {
            height: calc(1.5em + .75rem + 2px) !important;
            padding: .375rem .75rem !important;
            font-size: 1rem !important;
            line-height: 1.5 !important;
        }
        .select2-container--bootstrap4 .select2-selection--single .select2-selection__rendered {
            padding-left: 0 !important;
            padding-right: 0 !important;
            line-height: 1.5 !important;
        }
        .select2-container--bootstrap4 .select2-selection--single .select2-selection__arrow {
            height: calc(1.5em + .75rem + 2px) !important;
            top: 0 !important;
            right: 0 !important;
        }

        /* Banner 链接布局优化 */
        .text-left > [class*="col-"] {
            margin-bottom: 10px;
        }
    </style>
</head>
<body class="hold-transition sidebar-mini layout-fixed">
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
        @if(isset($page_title) || isset($page_description))
        <div class="content-header">
            <div class="container-fluid">
                <h1 class="m-0">{{ $page_title ?? 'Page Title' }}</h1>
                @if(!empty($page_description))
                    <small class="text-muted">{{ $page_description }}</small>
                @endif
            </div>
        </div>
        @endif
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

<!-- Modern World: jQuery, Bootstrap, AdminLTE 全部通过 Vite 构建 -->
<!-- Legacy World (AdminLTE v2) 資產已棄用，避免與 AdminLTE v3 衝突 -->
{{-- <script src="{{ mix('js/app.js') }}"></script> --}}
@yield('js')
@stack('scripts')
<script>
    onViteReady(function() {
        $('#flash-overlay-modal').modal();
    });
</script>
</body>
</html>
