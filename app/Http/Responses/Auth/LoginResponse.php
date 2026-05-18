<?php

namespace App\Http\Responses\Auth;

use Filament\Http\Responses\Auth\Contracts\LoginResponse as LoginResponseContract;
use Illuminate\Http\RedirectResponse;

class LoginResponse implements LoginResponseContract
{
    public function toResponse($request): RedirectResponse
    {
        return redirect()->route('owner.dashboard');
    }
}
