<?php
    namespace App\Http\Controllers;
    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\Auth;
    use App\Services\AuditService;

    class AuthController extends Controller
    {
        public function showLogin()
        {
            return view('auth.login');
        }

        public function login(Request $request)
        {
            $credentials = $request->validate([
                'email'    => 'required|email',
                'password' => 'required',
            ]);

            if (Auth::attempt($credentials, $request->boolean('remember'))) {
                $request->session()->regenerate();
                AuditService::record('login', 'Autentikasi', 'Berhasil masuk ke sistem');
                return redirect()->intended('/dashboard');
            }

            AuditService::record('login_failed', 'Autentikasi', 'Percobaan login gagal untuk ' . $request->email);

            return back()->withErrors([
                'email' => 'Email atau password salah.',
            ])->onlyInput('email');
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
