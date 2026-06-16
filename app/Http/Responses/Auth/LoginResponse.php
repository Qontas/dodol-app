<?php

namespace App\Http\Responses\Auth;

use Filament\Http\Responses\Auth\Contracts\LoginResponse as LoginResponseContract;
use Illuminate\Http\RedirectResponse;

class LoginResponse implements LoginResponseContract
{
    public function toResponse($request): RedirectResponse
    {
        // Role-aware (sama dgn route "/dashboard"): super_admin→/admin,
        // owner→owner.dashboard, operator→operator.dashboard.
        return redirect($request->user()->homePath());
    }
}
