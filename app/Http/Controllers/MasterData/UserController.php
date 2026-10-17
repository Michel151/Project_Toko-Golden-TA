<?php

namespace App\Http\Controllers\MasterData;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

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
            'password' => 'required|string|min:6|confirmed',
            'role'     => 'required|in:owner,kasir,staf_gudang',
        ]);

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
            $rules['password'] = 'string|min:6|confirmed';
        }

        $request->validate($rules);

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

    public function destroy(User $user)
    {
        if ($user->id === auth()->id()) {
            return back()->with('error', 'Anda tidak bisa menghapus akun sendiri.');
        }
        if ($user->isOwner() && User::where('role', 'owner')->count() <= 1) {
            return back()->with('error', 'Owner terakhir tidak dapat dihapus.');
        }
        $user->delete();
        return redirect()->route('master.users.index')->with('success', 'Pengguna berhasil dihapus.');
    }
}
