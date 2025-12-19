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
        .select2-container--bootstrap4 {
            width: 100% !important;
            max-width: 100%;
        }

        /* Banner 链接布局优化 */
        .text-left > [class*="col-"] {
            margin-bottom: 10px;
        }

        /* 浅色模式：降低藍色連結飽和度，更柔和（排除按鈕） */
        body:not(.dark-mode) .content a:not(.btn),
        body:not(.dark-mode) .main-footer a:not(.btn),
        body:not(.dark-mode) .main-header a:not(.btn):not(.nav-link) {
            color: #2d8cc2;  /* 原 #007bff，降低飽和度約 38% */
        }

        body:not(.dark-mode) .content a:not(.btn):hover,
        body:not(.dark-mode) .main-footer a:not(.btn):hover,
        body:not(.dark-mode) .main-header a:not(.btn):not(.nav-link):hover {
            color: #2068a5;  /* 原 #0056b3，降低飽和度約 38% */
        }

        /* Dark mode：使用更亮的藍色以提高可讀性（排除按鈕） */
        body.dark-mode .content a:not(.btn),
        body.dark-mode .main-footer a:not(.btn),
        body.dark-mode .main-header a:not(.btn):not(.nav-link) {
            color: #5dade2;  /* 明亮的藍色，在深色背景下清晰可見 */
        }

        body.dark-mode .content a:not(.btn):hover,
        body.dark-mode .main-footer a:not(.btn):hover,
        body.dark-mode .main-header a:not(.btn):not(.nav-link):hover {
            color: #85c1e9;  /* hover 時更亮 */
        }

        /* Dark mode：覆蓋硬編碼的背景色，確保可讀性 */
        body.dark-mode {
            /* 禁用輸入框的淺灰色背景 (#f5f5f5) -> 深色 */
            --disabled-bg-light: #f5f5f5;
            --disabled-bg-dark: #3a3a3a;
        }

        /* 覆蓋內聯樣式的淺色背景 */
        body.dark-mode input[style*="background-color: #f5f5f5"],
        body.dark-mode input[style*="background-color: rgb(245, 245, 245)"],
        body.dark-mode select[style*="background-color: #f5f5f5"],
        body.dark-mode textarea[style*="background-color: #f5f5f5"],
        body.dark-mode .form-control[style*="background-color: #f5f5f5"] {
            background-color: #3a3a3a !important;
            color: #e0e0e0 !important;
            border-color: #555 !important;
        }

        /* 其他常見的淺色背景 */
        body.dark-mode [style*="background-color: #fff"],
        body.dark-mode [style*="background-color: white"],
        body.dark-mode [style*="background-color: #fafafa"],
        body.dark-mode [style*="background-color: #eee"] {
            background-color: #2d2d2d !important;
        }

        /* 高亮色調整 */
        body.dark-mode [style*="background-color: #dff0d8"] {
            background-color: #2d4a2d !important;  /* 淺綠 -> 深綠 */
        }

        body.dark-mode [style*="background-color: #ffebee"] {
            background-color: #4a2d2d !important;  /* 淺紅 -> 深紅 */
        }

        /* Dark mode：Select2 控件樣式 */
        body.dark-mode .select2-container--bootstrap4 .select2-selection {
            background-color: #343a40 !important;
            border-color: #495057 !important;
            color: #e0e0e0 !important;
        }

        body.dark-mode .select2-container--bootstrap4 .select2-selection--single .select2-selection__rendered {
            color: #e0e0e0 !important;
        }

        body.dark-mode .select2-container--bootstrap4 .select2-selection--single .select2-selection__placeholder {
            color: #adb5bd !important;
        }

        /* Select2 下拉菜單 */
        body.dark-mode .select2-dropdown {
            background-color: #343a40 !important;
            border-color: #495057 !important;
        }

        body.dark-mode .select2-container--bootstrap4 .select2-results__option {
            color: #e0e0e0 !important;
        }

        /* Select2 hover 和選中狀態 */
        body.dark-mode .select2-container--bootstrap4 .select2-results__option--highlighted {
            background-color: #495057 !important;
            color: #fff !important;
        }

        body.dark-mode .select2-container--bootstrap4 .select2-results__option[aria-selected="true"] {
            background-color: #3a4248 !important;
        }

        /* Select2 搜索框 */
        body.dark-mode .select2-search--dropdown .select2-search__field {
            background-color: #2b3035 !important;
            border-color: #495057 !important;
            color: #e0e0e0 !important;
        }

        body.dark-mode .select2-search--dropdown .select2-search__field:focus {
            border-color: #5dade2 !important;
        }

        /* Select2 多選模式 */
        body.dark-mode .select2-container--bootstrap4 .select2-selection--multiple {
            background-color: #343a40 !important;
            border-color: #495057 !important;
        }

        body.dark-mode .select2-container--bootstrap4 .select2-selection--multiple .select2-selection__choice {
            background-color: #495057 !important;
            border-color: #6c757d !important;
            color: #e0e0e0 !important;
        }

        body.dark-mode .select2-container--bootstrap4 .select2-selection--multiple .select2-selection__choice__remove {
            color: #e0e0e0 !important;
        }

        body.dark-mode .select2-container--bootstrap4 .select2-selection--multiple .select2-selection__choice__remove:hover {
            color: #ff6b6b !important;
        }

        /* Select2 禁用狀態 */
        body.dark-mode .select2-container--bootstrap4.select2-container--disabled .select2-selection {
            background-color: #2b3035 !important;
            color: #6c757d !important;
            cursor: not-allowed;
>>>>>>> 94f5819 (feat: 添加完整的 dark mode 切换功能)
        }
    </style>
</head>
<body class="hold-transition sidebar-mini layout-fixed">
<script>
    // 在 body 解析后立即应用 dark mode，避免闪烁
    // 这个脚本会在页面内容渲染前执行
    if (localStorage.getItem('darkMode') === 'true') {
        document.body.classList.add('dark-mode');
    }
</script>
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

<!-- Dark Mode Toggle Script -->
<script>
    // 全局切換函數，供內聯 onclick 調用（參考 logout 按鈕的實現方式）
    window.toggleDarkMode = function() {
        const body = document.body;
        const darkModeIcon = document.getElementById('darkModeIcon');
        const navbar = document.querySelector('.main-header');

        console.log('Dark mode toggle clicked!');

        // 切換 dark-mode class
        body.classList.toggle('dark-mode');
        const isNowDark = body.classList.contains('dark-mode');

        console.log('Dark mode is now:', isNowDark);

        // 保存到 localStorage
        localStorage.setItem('darkMode', isNowDark);

        // 更新 navbar 樣式
        if (navbar) {
            if (isNowDark) {
                navbar.classList.remove('navbar-white', 'navbar-light');
                navbar.classList.add('navbar-dark');
            } else {
                navbar.classList.remove('navbar-dark');
                navbar.classList.add('navbar-white', 'navbar-light');
            }
        }

        // 更新圖標
        if (darkModeIcon) {
            if (isNowDark) {
                darkModeIcon.classList.remove('fa-moon');
                darkModeIcon.classList.add('fa-sun');
            } else {
                darkModeIcon.classList.remove('fa-sun');
                darkModeIcon.classList.add('fa-moon');
            }
        }
    };

    // 初始化：更新 navbar 和圖標樣式
    // 注意：body 的 dark-mode class 已在頁面加載早期應用，這裡只需更新其他元素
    (function() {
        const isDarkMode = document.body.classList.contains('dark-mode');
        console.log('Dark mode is active:', isDarkMode);

        if (isDarkMode) {
            const darkModeIcon = document.getElementById('darkModeIcon');
            const navbar = document.querySelector('.main-header');

            // 更新 navbar 樣式
            if (navbar) {
                navbar.classList.remove('navbar-white', 'navbar-light');
                navbar.classList.add('navbar-dark');
            }

            // 更新圖標
            if (darkModeIcon) {
                darkModeIcon.classList.remove('fa-moon');
                darkModeIcon.classList.add('fa-sun');
            }
        }

        console.log('Dark mode initialized');
    })();
</script>
</body>
</html>
