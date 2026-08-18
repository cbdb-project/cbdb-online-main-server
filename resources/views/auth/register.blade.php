<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <!-- CSRF Token -->
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'Laravel') }}</title>
    <link rel="icon" href="/favicon.ico">
    <link rel="icon" type="image/png" sizes="192x192" href="/icon-192.png">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <!-- Tell the browser to be responsive to screen width -->
    <meta content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no" name="viewport">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.5.0/css/font-awesome.min.css">
    <!-- Ionicons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/ionicons/2.0.1/css/ionicons.min.css">

    <!-- Styles -->
    @vite(['resources/js/app.js'])

    <!-- HTML5 Shim and Respond.js IE8 support of HTML5 elements and media queries -->
    <!-- WARNING: Respond.js doesn't work if you view the page via file:// -->
    <!--[if lt IE 9]>
    <script src="https://oss.maxcdn.com/html5shiv/3.7.3/html5shiv.min.js"></script>
    <script src="https://oss.maxcdn.com/respond/1.4.2/respond.min.js"></script>
    <![endif]-->
</head>
<body class="bg-light">
<div class="container min-vh-100 d-flex align-items-center justify-content-center py-4" id="app">
    <div class="row w-100 justify-content-center">
        <div class="col-sm-10 col-md-8 col-lg-6">
            <div class="text-center mb-4">
                <a href="/home" class="text-decoration-none text-dark">
                    <div class="h4 mb-1">{{ config('app.name', 'Laravel') }}</div>
                    <small class="text-muted">{{ __('common.create_account') }}</small>
                </a>
            </div>

            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <h1 class="h5 text-center mb-4">{{ __('common.join_us') }}</h1>

                    {{-- 保險起見用 Route::has 包住：正常情況下註冊關閉時這個視圖根本不會被渲染
                         （GET /register 與 POST /register 都由 Auth::routes() 一起產生／一起消失），
                         但若有人直接 view('auth.register')，沒保護就會 500 而不是顯示空表單。 --}}
                    <form action="{{ Route::has('register') ? route('register') : '' }}" method="post" novalidate>
                        {{ csrf_field() }}
                        <div class="form-group mb-3">
                            <label for="name" class="form-label small text-muted">{{ __('common.name') }}</label>
                            <input id="name" type="text" class="form-control{{ $errors->has('name') ? ' is-invalid' : '' }}" name="name" value="{{ old('name') }}" placeholder="{{ __('common.name') }}" required autofocus>
                            @if ($errors->has('name'))
                                <div class="invalid-feedback">
                                    {{ $errors->first('name') }}
                                </div>
                            @endif
                        </div>
                        <div class="form-group mb-3">
                            <label for="email" class="form-label small text-muted">{{ __('common.email') }}</label>
                            <input id="email" type="email" class="form-control{{ $errors->has('email') ? ' is-invalid' : '' }}" name="email" value="{{ old('email') }}" placeholder="name@example.com" required>
                            @if ($errors->has('email'))
                                <div class="invalid-feedback">
                                    {{ $errors->first('email') }}
                                </div>
                            @endif
                        </div>
                        <div class="form-group mb-3">
                            <label for="institution" class="form-label small text-muted">{{ __('common.institution') }}</label>
                            <input id="institution" type="text" class="form-control{{ $errors->has('institution') ? ' is-invalid' : '' }}" name="institution" value="{{ old('institution') }}" placeholder="{{ __('common.institution_placeholder') }}">
                            @if ($errors->has('institution'))
                                <div class="invalid-feedback">
                                    {{ $errors->first('institution') }}
                                </div>
                            @endif
                        </div>
                        <div class="form-group mb-3">
                            <label for="password" class="form-label small text-muted">{{ __('common.password') }}</label>
                            <input id="password" type="password" class="form-control{{ $errors->has('password') ? ' is-invalid' : '' }}" name="password" placeholder="{{ __('common.choose_password') }}" required>
                            @if ($errors->has('password'))
                                <div class="invalid-feedback">
                                    {{ $errors->first('password') }}
                                </div>
                            @endif
                        </div>
                        <div class="form-group mb-4">
                            <label for="password-confirm" class="form-label small text-muted">{{ __('common.confirm_password') }}</label>
                            <input id="password-confirm" type="password" class="form-control" name="password_confirmation" placeholder="{{ __('common.confirm_password') }}" required>
                        </div>
                        <button type="submit" class="btn btn-primary btn-block">{{ __('common.create_account') }}</button>
                    </form>
                </div>
                <div class="card-footer text-center bg-white">
                    <span class="small text-muted">{{ __('common.have_account') }}</span>
                    <a href="{{ route('login') }}" class="ml-1">{{ __('common.sign_in') }}</a>
                </div>
            </div>
        </div>
    </div>
</div>

</body>
</html>
