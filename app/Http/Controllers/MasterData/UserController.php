<?php

namespace App\Http\Controllers\MasterData;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\DeletionConstraint;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    public function index()
    {
        $users = User::latest()->paginate(15);
        return view('master.users.index', compact('users'));
    }

    public function create()
    {
        return view('master.users.form');
    }

    public function store(Request $request)
    {
        $request->validate([
            'name'     => 'required|string|max:100',
            'email'    => 'required|email|unique:users,email',
            'password' => $this->passwordRules(),
            'role'     => 'required|in:owner,kasir,staf_gudang',
        ], $this->passwordMessages());

        User::create([
            'name'     => $request->name,
            'email'    => $request->email,
            'password' => Hash::make($request->password),
            'role'     => $request->role,
        ]);

        return redirect()->route('master.users.index')->with('success', 'Pengguna berhasil ditambahkan.');
    }

    public function edit(User $user)
    {
        return view('master.users.form', compact('user'));
    }

    public function update(Request $request, User $user)
    {
        $rules = [
            'name'  => 'required|string|max:100',
            'email' => 'required|email|unique:users,email,' . $user->id,
            'role'  => 'required|in:owner,kasir,staf_gudang',
        ];

        if ($request->filled('password')) {
            $rules['password'] = $this->passwordRules();
        }

        $request->validate($rules, $this->passwordMessages());

        if ($user->isOwner() && $request->role !== 'owner'
            && ($user->id === auth()->id() || User::where('role', 'owner')->count() <= 1)) {
            return back()->withErrors(['role' => 'Role akun sendiri dan owner terakhir tidak dapat diturunkan.'])->withInput();
        }

        $data = $request->only('name', 'email', 'role');
        if ($request->filled('password')) {
            $data['password'] = Hash::make($request->password);
        }

        $user->update($data);
        return redirect()->route('master.users.index')->with('success', 'Pengguna berhasil diperbarui.');
    }

    private function passwordRules(): array
    {
        return ['required', 'string', 'confirmed', Password::min(8)->mixedCase()->numbers()->symbols()];
    }

    private function passwordMessages(): array
    {
        return [
            'password.required' => 'Password wajib diisi.',
            'password.string' => 'Password harus berupa teks.',
            'password.min' => 'Password minimal 8 karakter.',
            'password.confirmed' => 'Konfirmasi password tidak sama.',
            'password.mixed' => 'Password harus mengandung huruf besar dan huruf kecil.',
            'password.numbers' => 'Password harus mengandung angka.',
            'password.symbols' => 'Password harus mengandung simbol, misalnya !, @, atau #.',
        ];
    }

    public function destroy(User $user)
    {
        try {
            $result = DB::transaction(function () use ($user) {
                app(\App\Services\MovingAverageCostService::class)->lock();
                $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                if ($locked->id === auth()->id()) return 'self';
                if ($locked->isOwner() && User::where('role', 'owner')->count() <= 1) return 'last_owner';
                if ($locked->hasTransactionHistory()) return 'history';
                $locked->delete();
                return 'deleted';
            }, 3);
        } catch (QueryException $exception) {
            if (!DeletionConstraint::isReferenced($exception, 'users')) throw $exception;
            $result = 'history';
        }

        if ($result === 'self') {
            return back()->with('error', 'Anda tidak bisa menghapus akun sendiri.');
        }
        if ($result === 'last_owner') {
            return back()->with('error', 'Owner terakhir tidak dapat dihapus.');
        }
        if ($result === 'history') {
            return back()->with('error', 'Pengguna tidak dapat dihapus karena masih digunakan dalam riwayat transaksi atau aktivitas sistem. Riwayat pengguna harus tetap tersimpan.');
        }
        return redirect()->route('master.users.index')->with('success', 'Pengguna berhasil dihapus.');
    }
}
