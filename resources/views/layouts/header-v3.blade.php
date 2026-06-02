<!-- Navbar -->
<nav class="main-header navbar navbar-expand navbar-white navbar-light">
    <!-- Left navbar links -->
    <ul class="navbar-nav">
        <li class="nav-item">
            <a class="nav-link" data-widget="pushmenu" href="#" role="button"><i class="fas fa-bars"></i></a>
        </li>
        <li class="nav-item d-none d-sm-inline-block">
            <a href="/basicinformation" class="nav-link">{{ __('common.home') }}</a>
        </li>
    </ul>

    <!-- Breadcrumb -->
    <ol class="breadcrumb float-sm-left ml-2 mb-0 bg-transparent">
        @if(isset($breadcrumbs) && is_array($breadcrumbs))
            @foreach($breadcrumbs as $index => $crumb)
                @if($index < count($breadcrumbs) - 1)
                    <li class="breadcrumb-item">
                        <a href="{{ $crumb['url'] }}">{{ $crumb['label'] }}</a>
                    </li>
                @else
                    <li class="breadcrumb-item active">{{ $crumb['label'] }}</li>
                @endif
            @endforeach
        @else
            {!! $archer ?? '' !!}
            @if(isset($page_title))
                <li class="breadcrumb-item active">{{ $page_title }}</li>
            @endif
        @endif
    </ol>

    <!-- Right navbar links -->
    <ul class="navbar-nav ml-auto">
        <!-- Dark Mode Toggle -->
        <li class="nav-item">
            <a class="nav-link" href="#" role="button" id="darkModeToggle" title="{{ __('common.dark_mode_toggle') }}"
               onclick="event.preventDefault(); window.toggleDarkMode();">
                <i class="fas fa-moon" id="darkModeIcon"></i>
            </a>
        </li>

        <!-- Language Toggle -->
        <li class="nav-item">
            <form id="__locale-form" action="{{ route('locale.switch', [], false) }}" method="POST" style="display:inline">
                @csrf
                <input type="hidden" name="locale"
                       value="{{ app()->getLocale() === 'zh-TW' ? 'en' : 'zh-TW' }}">
                <button type="button" class="btn btn-link px-2"
                        title="{{ app()->getLocale() === 'zh-TW' ? __('nav.language_switch_to_en') : __('nav.language_switch_to_zh') }}"
                        style="font-weight:600; letter-spacing:0.05em; color:inherit;"
                        onclick="__submitLocaleForm()">
                    {{ app()->getLocale() === 'zh-TW' ? __('nav.language_switch_to_en') : __('nav.language_switch_to_zh') }}
                </button>
            </form>
            <script>
            function __submitLocaleForm() {
                // Check if any other form on the page has unsaved changes.
                // <select> is excluded: defaultValue is unreliable for PHP-pre-selected
                // options and would trigger false positives on untouched edit forms.
                var editForms = document.querySelectorAll('form:not(#__locale-form)');
                var dirty = Array.from(editForms).some(function(f) {
                    return Array.from(f.elements).some(function(el) {
                        if (!el.name || el.name === '_token' || el.name === '_method') return false;
                        if (el.type === 'hidden' || el.tagName === 'SELECT') return false;
                        if (el.type === 'checkbox' || el.type === 'radio') return el.checked !== el.defaultChecked;
                        return el.value !== el.defaultValue;
                    });
                });
                if (dirty) {
                    var msg = {!! Js::from(__('nav.locale_switch_unsaved_warning')) !!};
                    if (!window.confirm(msg)) return;
                }
                document.getElementById('__locale-form').submit();
            }
            </script>
        </li>

        @if (Auth::guest())
            <li class="nav-item">
                <a href="{{ route('login', ['redirect' => request()->getRequestUri()]) }}" class="nav-link">Login</a>
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
                            <a href="{{ route('profile.edit') }}" class="btn btn-default btn-flat">{{ __('common.profile_settings') }}</a>
                        </div>
                        <div class="float-right">
                            <a href="{{ route('logout') }}"
                               onclick="event.preventDefault(); document.getElementById('logout-form').submit();"
                               class="btn btn-default btn-flat">{{ __('common.sign_out') }}</a>
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
