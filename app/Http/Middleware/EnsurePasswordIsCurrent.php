<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordIsCurrent
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs('logout')) return $next($request);
        $authenticatedVersion = (int) $request->user()?->password_version;
        $user = $request->user()?->fresh();
        $key = 'auth.password_version.'.($user?->id ?? '');
        if ($user && ! $request->session()->has($key) && Auth::viaRemember()) {
            $request->session()->put($key, $authenticatedVersion);
        }
        if (! $user || (int) $request->session()->get($key, 0) !== (int) $user->password_version) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            $message = 'Password akun sudah berubah. Silakan masuk kembali.';
            return $request->expectsJson()
                ? response()->json(['message'=>$message], 401)
                : redirect()->route('login')->withErrors(['email'=>$message]);
        }
        Auth::setUser($user);
        if ($user->must_change_password && ! $request->routeIs('account.password.*')) {
            $message = 'Anda wajib mengganti password sementara sebelum melanjutkan.';
            return $request->expectsJson()
                ? response()->json(['message'=>$message, 'password_change_required'=>true, 'redirect'=>route('account.password.edit')], 403)
                : redirect()->route('account.password.edit')->with('warning', $message);
        }

        return $next($request);
    }
}
