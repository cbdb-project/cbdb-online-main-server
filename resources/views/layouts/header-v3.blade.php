<!-- Navbar -->
<nav class="main-header navbar navbar-expand navbar-white navbar-light">
    <!-- Left navbar links -->
    <ul class="navbar-nav">
        <li class="nav-item">
            <a class="nav-link" data-widget="pushmenu" href="#" role="button"><i class="fas fa-bars"></i></a>
        </li>
        <li class="nav-item d-none d-sm-inline-block">
            <a href="/basicinformation" class="nav-link">Home</a>
        </li>
    </ul>

    <!-- Breadcrumb -->
    <ol class="breadcrumb float-sm-left ml-2 mb-0 bg-transparent">
        {!! $archer ?? '' !!}
        @if(isset($page_title))
            <li class="breadcrumb-item active">{{ $page_title }}</li>
        @endif
    </ol>

    <!-- Right navbar links -->
    <ul class="navbar-nav ml-auto">
        @if (Auth::guest())
            <li class="nav-item">
                <a href="{{ route('login') }}" class="nav-link">Login</a>
            </li>
            <li class="nav-item">
                <a href="{{ route('register') }}" class="nav-link">Register</a>
            </li>
        @else
            <!-- User Dropdown Menu -->
            <li class="nav-item dropdown user-menu">
                <a href="#" class="nav-link dropdown-toggle" data-toggle="dropdown">
                    <img src="/images/avatar/{{ Auth::user()->avatar }}" class="user-image img-circle elevation-2" alt="User Image">
                    <span class="d-none d-md-inline">{{ Auth::user()->name }}</span>
                </a>
                <ul class="dropdown-menu dropdown-menu-lg dropdown-menu-right">
                    <!-- User image -->
                    <li class="user-header bg-primary">
                        <img src="/images/avatar/{{ Auth::user()->avatar }}" class="img-circle elevation-2" alt="User Image">
                        <p>
                            {{ Auth::user()->name }}
                            @if (Auth::user()->institution)
                                <small>{{ Auth::user()->institution }}</small>
                            @endif
                        </p>
                    </li>
                    <!-- Menu Footer-->
                    <li class="user-footer">
                        <div class="float-left">
                            <a href="{{ route('profile.edit') }}" class="btn btn-default btn-flat">個人設定</a>
                        </div>
                        <div class="float-right">
                            <a href="{{ route('logout') }}"
                               onclick="event.preventDefault(); document.getElementById('logout-form').submit();"
                               class="btn btn-default btn-flat">Sign out</a>
                            <form id="logout-form" action="{{ route('logout') }}" method="POST" style="display: none;">
                                {{ csrf_field() }}
                            </form>
                        </div>
                    </li>
                </ul>
            </li>
        @endif
    </ul>
</nav>
