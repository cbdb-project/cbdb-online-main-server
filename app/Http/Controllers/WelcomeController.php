<?php

namespace App\Http\Controllers;

class WelcomeController extends Controller {
    /**
     * Show the application welcome page.
     *
     * @return \Illuminate\View\View
     */
    public function index() {
        return view('welcome');
    }
}
