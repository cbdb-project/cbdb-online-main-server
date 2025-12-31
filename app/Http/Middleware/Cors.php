<?php

/**
 * User: ja
 * Date: 2020/09/09
 * Time: 10:00
 */

namespace App\Http\Middleware;

use Closure;

class Cors {
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next) {
        $response = $next($request);

        // 使用 headers->set() 方法以兼容 StreamedResponse
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('Access-Control-Allow-Headers', 'Origin, Content-Type, Cookie, Accept, multipart/form-data, application/json');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PATCH, PUT, OPTIONS');
        $response->headers->set('Access-Control-Allow-Credentials', 'false');

        return $response;
    }
}
