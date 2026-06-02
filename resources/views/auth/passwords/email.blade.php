<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'Laravel') }}</title>
    <meta content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no" name="viewport">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.5.0/css/font-awesome.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/ionicons/2.0.1/css/ionicons.min.css">
    @vite(['resources/js/app.js'])
</head>
<body class="bg-light">
<div class="container min-vh-100 d-flex align-items-center justify-content-center py-4">
    <div class="row w-100 justify-content-center">
        <div class="col-sm-10 col-md-8 col-lg-6">
            <div class="text-center mb-4">
                <a href="/home" class="text-decoration-none text-dark">
                    <div class="h4 mb-1">{{ config('app.name', 'Laravel') }}</div>
                    <small class="text-muted">{{ __('common.reset_password_subtitle') }}</small>
                </a>
            </div>

            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <h1 class="h5 text-center mb-4">{{ __('common.send_reset_link_title') }}</h1>

                    @if (session('status'))
                        <div class="alert alert-success" role="alert">
                            {{ session('status') }}
                        </div>
                    @endif

                    <form method="POST" action="{{ route('password.email') }}" novalidate>
                        {{ csrf_field() }}

                        <div class="form-group mb-3">
                            <label for="email" class="form-label small text-muted">{{ __('common.email') }}</label>
                            <input id="email" type="email" class="form-control{{ $errors->has('email') ? ' is-invalid' : '' }}" name="email" value="{{ old('email') }}" placeholder="name@example.com" required autofocus>
                            @if ($errors->has('email'))
                                <div class="invalid-feedback">
                                    {{ $errors->first('email') }}
                                </div>
                            @endif
                        </div>

                        <button type="submit" class="btn btn-primary btn-block">{{ __('common.send_reset_link_btn') }}</button>
                    </form>
                </div>
                <div class="card-footer text-center bg-white">
                    <a href="{{ route('login') }}" class="small">{{ __('common.back_to_login') }}</a>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
