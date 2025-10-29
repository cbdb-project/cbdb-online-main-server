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
    <style>
        .halloween-banner {
            display: none;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            padding: 0.5rem 1rem;
            background: linear-gradient(90deg, #2d1b4e, #f97316);
            color: #fffef0;
            font-weight: 600;
            letter-spacing: 0.05em;
            position: relative;
            z-index: 1050;
            box-shadow: 0 2px 6px rgba(0,0,0,0.3);
        }
        .halloween-mode .halloween-banner {
            display: flex;
        }
        .halloween-mode .main-header,
        .halloween-mode .main-header .navbar,
        .halloween-mode .main-header .logo {
            background: linear-gradient(135deg, #2d1b4e, #f97316) !important;
        }
        .halloween-mode .sidebar {
            border-right: 3px solid #f97316;
        }
        .halloween-sparkles {
            position: absolute;
            inset: 0;
            pointer-events: none;
            background-image:
                radial-gradient(2px 2px at 10% 20%, rgba(255,255,255,0.35) 0, rgba(255,255,255,0) 60%),
                radial-gradient(3px 3px at 80% 30%, rgba(255,160,0,0.35) 0, rgba(255,160,0,0) 70%),
                radial-gradient(2px 2px at 30% 80%, rgba(255,255,255,0.25) 0, rgba(255,255,255,0) 60%);
            opacity: 0;
            transition: opacity 0.4s ease;
        }
        .halloween-mode .halloween-sparkles {
            opacity: 1;
        }
    </style>

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

    <div id="halloween-banner" class="halloween-banner">
        <span aria-hidden="true">&#x1F383;</span>
        <span>Happy Halloween 2025!</span>
        <span aria-hidden="true">&#x1F383;</span>
        <span class="halloween-sparkles" aria-hidden="true"></span>
    </div>

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
                {{ $page_title or 'Page Title' }}
                <small>{{ $page_description or null }}</small>
            </h1>
            <ol class="breadcrumb">
                <li><a href="/basicinformation"><i class="fa fa-dashboard"></i> Home</a></li>
                <li class="active"><a href="{{ $page_url or '#'}}">{{ $page_title }}</a></li>
                {!! $archer or '' !!}
            </ol>
        </section>

        <!-- Main content -->
        <section class="content">

            <!-- Your Page Content Here -->
            @yield('content')

        </section>
        <!-- /.content -->
    </div>
    <!-- /.content-wrapper -->

    <!-- Footer -->
    @include('layouts.footer')

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
    (function () {
        function shouldShowHalloween() {
            var params = new URLSearchParams(window.location.search);
            if (params.has('halloween_preview')) {
                var value = params.get('halloween_preview');
                if (value === '0' || value === 'false') {
                    return false;
                }
                return true;
            }

            var now = new Date();
            return now.getFullYear() === 2025 &&
                now.getMonth() === 9 &&
                now.getDate() === 31;
        }

        var isHalloween = shouldShowHalloween();

        if (!isHalloween) {
            return;
        }

        document.body.classList.add('halloween-mode');
        var banner = document.getElementById('halloween-banner');
        if (banner) {
            banner.style.display = 'flex';
        }
    })();
</script>
</body>
</html>
