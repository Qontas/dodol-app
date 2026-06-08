<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login - Cemilan Qontas</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="font-sans text-slate-900 antialiased bg-slate-50 flex items-center justify-center min-h-screen">
    <div class="w-full max-w-md px-6">
        <div class="text-center mb-8">
            <h1 class="text-3xl font-extrabold text-amber-600 tracking-tight">Cemilan Qontas</h1>
            <p class="text-slate-500 mt-2 font-medium">Sistem Distribusi Dodol</p>
        </div>

        <div class="bg-white rounded-2xl shadow-xl border border-slate-100 overflow-hidden">
            <div class="px-8 py-8">
                <x-auth-session-status class="mb-4" :status="session('status')" />

                <form method="POST" action="{{ route('login') }}">
                    @csrf

                    <div>
                        <label for="email" class="block text-sm font-semibold text-slate-700">Email</label>
                        <input id="email" class="block mt-2 w-full rounded-lg border-slate-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 sm:text-sm" type="email" name="email" :value="old('email')" required autofocus autocomplete="username" placeholder="Masukkan email anda..." />
                        <x-input-error :messages="$errors->get('email')" class="mt-2" />
                    </div>

                    <div class="mt-6">
                        <label for="password" class="block text-sm font-semibold text-slate-700">Password</label>
                        <input id="password" class="block mt-2 w-full rounded-lg border-slate-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 sm:text-sm" type="password" name="password" required autocomplete="current-password" placeholder="••••••••" />
                        <x-input-error :messages="$errors->get('password')" class="mt-2" />
                    </div>

                    <div class="flex items-center justify-between mt-6">
                        <label for="remember_me" class="inline-flex items-center cursor-pointer">
                            <input id="remember_me" type="checkbox" class="rounded border-slate-300 text-amber-600 shadow-sm focus:ring-amber-500" name="remember">
                            <span class="ms-2 text-sm text-slate-600">Ingat Saya</span>
                        </label>
                    </div>

                    <div class="mt-8">
                        <button type="submit" class="w-full flex justify-center py-3 px-4 border border-transparent rounded-lg shadow-sm text-sm font-bold text-white bg-amber-600 hover:bg-amber-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-amber-500 transition-all duration-200">
                            Masuk ke Panel
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="text-center mt-8 text-xs text-slate-400 font-medium">
            &copy; {{ date('Y') }} Cemilan Qontas Distribusi.
        </div>
    </div>
</body>
</html>
