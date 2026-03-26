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
        <div class="col-sm-10 col-md-8 col-lg-5">
            <div class="text-center mb-4">
                <a href="/home" class="text-decoration-none text-dark">
                    <div class="h4 mb-1">{{ config('app.name', 'Laravel') }}</div>
                    <small class="text-muted">系統登入 · Sign in</small>
                </a>
            </div>
            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <h1 class="h5 text-center mb-4">歡迎回來 · Welcome back</h1>
                    <form action="{{ route('login') }}" method="post" novalidate>
                        {{ csrf_field() }}
                        <input type="hidden" name="redirect" value="{{ old('redirect', request('redirect', session('url.intended'))) }}">
                        <div class="form-group mb-3">
                            <label for="email" class="form-label small text-muted">電子郵件 / Email</label>
                            <input type="email" id="email" name="email" value="{{ old('email') }}" class="form-control{{ $errors->has('email') ? ' is-invalid' : '' }}" placeholder="name@example.com / 電子郵件" required>
                            @if ($errors->has('email'))
                                <div class="invalid-feedback">
                                    {{ $errors->first('email') }}
                                </div>
                            @endif
                        </div>
                        <div class="form-group mb-3">
                            <label for="password" class="form-label small text-muted">密碼 / Password</label>
                            <input id="password" type="password" name="password" class="form-control{{ $errors->has('password') ? ' is-invalid' : '' }}" placeholder="請輸入密碼 / Password" required>
                            @if ($errors->has('password'))
                                <div class="invalid-feedback">
                                    {{ $errors->first('password') }}
                                </div>
                            @endif
                        </div>
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="remember" name="remember" {{ old('remember') ? 'checked' : '' }}>
                                <label class="form-check-label" for="remember">記住我 / Remember me</label>
                            </div>
                            <a href="{{ url('/password/reset') }}" class="small">忘記密碼？ / Forgot password?</a>
                        </div>
                        <button type="submit" class="btn btn-primary btn-block">登入 / Sign in</button>
                    </form>
                </div>
                <div class="card-footer text-center bg-white">
                    <span class="small text-muted">還沒有帳號？ / Need an account?</span>
                    <a href="{{ route('register') }}" class="ml-1">立即註冊 / Register</a>
                </div>
            </div>
        </div>
    </div>
</div>

</body>
</html>
