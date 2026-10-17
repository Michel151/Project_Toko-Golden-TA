<?php
    namespace App\Http\Controllers;
    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\Auth;
    use App\Services\AuditService;
    use App\Services\LoginThrottleService;

    class AuthController extends Controller
    {
        public function showLogin()
        {
            return view('auth.login');
        }

        public function login(Request $request, LoginThrottleService $throttle)
        {
            $credentials = $request->validate([
                'email'    => 'required|email',
                'password' => 'required|string',
            ]);

            if ($seconds = $throttle->remaining($request)) {
                return $this->loginCooldown($seconds);
            }

            if (Auth::attempt($credentials, $request->boolean('remember'))) {
                $throttle->clear($request);
                $request->session()->regenerate();
                AuditService::record('login', 'Autentikasi', 'Berhasil masuk ke sistem');
                return redirect()->intended('/dashboard');
            }

            AuditService::record('login_failed', 'Autentikasi', 'Percobaan login gagal untuk ' . $request->email);

            if ($seconds = $throttle->failed($request)) {
                return $this->loginCooldown($seconds);
            }

            return back()->withErrors([
                'email' => 'Email atau password salah.',
            ])->onlyInput('email');
        }

        private function loginCooldown(int $seconds)
        {
            return back()->withErrors([
                'email' => "Terlalu banyak percobaan login. Coba lagi dalam {$seconds} detik.",
            ])->with('login_retry_until', now()->timestamp + $seconds)->onlyInput('email');
        }

        public function logout(Request $request)
        {
            AuditService::record('logout', 'Autentikasi', 'Keluar dari sistem');
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            return redirect('/login');
        }
    }
