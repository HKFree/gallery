<?php

namespace App\Http\Controllers;

use App\Services\UserdbService;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function index(UserdbService $userdb): View
    {
        return view('home', [
            'tree' => $userdb->homeTree(),
        ]);
    }
}
