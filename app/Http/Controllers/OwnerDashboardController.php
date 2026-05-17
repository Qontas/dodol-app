<?php

namespace App\Http\Controllers;

use App\Models\Cluster;
use App\Models\Kiosk;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\View\View;

class OwnerDashboardController extends Controller
{
    public function index(): View
    {
        $user = auth()->user();

        $stats = [
            'total_kios' => Kiosk::count(),
            'total_cluster' => Cluster::count(),
            'total_supplier' => Supplier::count(),
            'total_operator' => User::where('role', 'operator')->count(),
        ];

        return view('owner.dashboard', compact('user', 'stats'));
    }
}
