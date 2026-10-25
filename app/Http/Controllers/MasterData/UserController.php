<?php

namespace App\Http\Controllers\MasterData;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\DeletionConstraint;
use App\Support\PasswordPolicy;
use App\Services\AccountPasswordService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

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
            'password' => PasswordPolicy::rules(),
            'role'     => 'required|in:owner,kasir,staf_gudang',
        ], PasswordPolicy::messages());

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
            $rules['password'] = PasswordPolicy::rules();
        }

        $request->validate($rules, PasswordPolicy::messages());

        if ($user->isOwner() && $request->role !== 'owner'
            && ($user->id === auth()->id() || User::where('role', 'owner')->count() <= 1)) {
            return back()->withErrors(['role' => 'Role akun sendiri dan owner terakhir tidak dapat diturunkan.'])
                ->withInput($request->except(['password', 'password_confirmation', 'current_password']));
        }

        $data = $request->only('name', 'email', 'role');
        $updated = DB::transaction(function () use ($request, $user, $data) {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $locked->update($data);
            return $request->filled('password')
                ? app(AccountPasswordService::class)->replace($locked, $request->password, $locked->id !== auth()->id())
                : $locked;
        });
        if ($request->filled('password') && $updated->id === auth()->id()) {
            auth()->setUser($updated);
            $request->session()->regenerate();
            $request->session()->put('auth.password_version.'.$updated->id, (int) $updated->password_version);
        }
        return redirect()->route('master.users.index')->with('success', 'Pengguna berhasil diperbarui.');
    }

    public function resetPassword(Request $request, User $user, AccountPasswordService $passwords)
    {
        abort_if($user->id === $request->user()->id, 422, 'Untuk akun sendiri, gunakan menu Ubah Password.');
        do {
            $temporary = Str::password(16);
        } while (! preg_match('/[a-z]/', $temporary) || ! preg_match('/[A-Z]/', $temporary));
        $passwords->replace($user, $temporary, true);

        return redirect()->route('master.users.reset-password-result')->with([
            'temporary_password'=>Crypt::encryptString($temporary),
            'password_reset_user'=>['name'=>$user->name, 'email'=>$user->email],
        ]);
    }

    public function resetResult(Request $request)
    {
        $encrypted = $request->session()->get('temporary_password');
        if (! is_string($encrypted)) {
            return redirect()->route('master.users.index')->with('error', 'Password sementara sudah tidak ditampilkan. Reset kembali jika diperlukan.');
        }
        $temporary = Crypt::decryptString($encrypted);
        return response()->view('master.users.password-reset-result', [
            'temporaryPassword'=>$temporary, 'resetUser'=>$request->session()->get('password_reset_user'),
        ])->header('Cache-Control', 'no-store, private')->header('Pragma', 'no-cache');
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
