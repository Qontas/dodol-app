<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class OperatorDashboardController extends Controller
{
    public function index(): View
    {
        $user = auth()->user();

        return view('operator.dashboard', compact('user'));
    }
}
