<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class HistoricalMapsController extends Controller {
    public function __construct() {
        $this->middleware('auth');
    }

    /**
     * 歷史地圖頁面。
     */
    public function index(): View {
        return view('maps.index');
    }

    /**
     * 舊版 public/maps 入口導向正式端點，保留 query string。
     */
    public function legacyRedirect(Request $request): RedirectResponse {
        return redirect()->route('app.maps.index', $request->query());
    }
}
