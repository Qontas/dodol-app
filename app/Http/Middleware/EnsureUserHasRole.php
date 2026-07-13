<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        if (! $user->is_active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')
                ->with('status', 'Akun nonaktif. Hubungi owner.');
        }

        // Operator dengan owner nonaktif → ikut terkunci tiap request (reversible:
        // begitu owner aktif lagi, operator lolos normal). Tak mengubah is_active operator.
        if ($user->isOperator() && $user->owner && ! $user->owner->is_active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')
                ->with('status', 'Akun owner Anda dinonaktifkan. Hubungi admin.');
        }

        if (! in_array($user->role, $roles, true)) {
            abort(403, 'Akses ditolak. Role tidak sesuai.');
        }

        return $next($request);
    }
}
