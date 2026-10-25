<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AccountPasswordService;
use App\Support\PasswordPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AccountPasswordController extends Controller
{
    public function edit()
    {
        return response()->view('account.password')->header('Cache-Control', 'no-store, private');
    }

    public function update(Request $request, AccountPasswordService $passwords)
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => PasswordPolicy::rules(),
        ], PasswordPolicy::messages());
        $user = DB::transaction(function () use ($request, $data, $passwords) {
            $locked = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            if (! Hash::check($data['current_password'], $locked->password)) {
                throw ValidationException::withMessages(['current_password'=>'Password saat ini tidak sesuai.']);
            }
            if (Hash::check($data['password'], $locked->password)) {
                throw ValidationException::withMessages(['password'=>'Password baru harus berbeda dari password saat ini.']);
            }
            return $passwords->replace($locked, $data['password'], false);
        });
        Auth::setUser($user);
        $request->session()->regenerate();
        $request->session()->put('auth.password_version.'.$user->id, (int) $user->password_version);

        return redirect()->route('dashboard')->with('success', 'Password berhasil diubah. Sesi login lainnya sudah dibatalkan.');
    }
}
