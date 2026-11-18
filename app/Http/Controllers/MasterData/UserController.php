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
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    // Menyiapkan daftar akun untuk halaman Master Pengguna.
    public function index()
    {
        $users = User::latest()->latest('id')->paginate(25)->withQueryString();
        return view('master.users.index', compact('users'));
    }

    // Membuka form tambah pengguna.
    public function create()
    {
        return view('master.users.form');
    }

    // Membuat akun baru dengan identitas, peran, dan kata sandi.
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
            // Simpan kata sandi dalam bentuk hash.
            'password' => Hash::make($request->password),
            'role'     => $request->role,
        ]);

        return redirect()->route('master.users.index')->with('success', 'Pengguna berhasil ditambahkan.');
    }

    // Membuka form ubah untuk pengguna yang dipilih.
    public function edit(User $user)
    {
        return view('master.users.form', compact('user'));
    }

    // Menyimpan perubahan identitas, peran, dan kata sandi bila diisi.
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

        $data = $request->only('name', 'email', 'role');
        $updated = DB::transaction(function () use ($request, $user, $data) {
            $locked = $this->lockManagedUser($user);
            if ($locked->id === auth()->id() && $data['role'] !== 'owner') {
                throw ValidationException::withMessages(['role' => 'Role akun sendiri dan owner terakhir tidak dapat diturunkan.']);
            }
            $locked->update($data);
            return $request->filled('password')
                ? app(AccountPasswordService::class)->replace($locked, $request->password, $locked->id !== auth()->id())
                : $locked;
        }, 3);
        if ($request->filled('password') && $updated->id === auth()->id()) {
            auth()->setUser($updated);
            $request->session()->regenerate();
            $request->session()->put('auth.password_version.'.$updated->id, (int) $updated->password_version);
        }
        return redirect()->route('master.users.index')->with('success', 'Pengguna berhasil diperbarui.');
    }

    // Memberi kata sandi sementara kepada pengguna yang dipilih.
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

    // Menampilkan hasil pembuatan kata sandi sementara.
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

    // Mengubah apakah sebuah akun boleh masuk ke aplikasi.
    public function updateStatus(Request $request, User $user)
    {
        $request->validate(['active' => 'required|boolean']);
        $active = $request->boolean('active');

        if (! $active && $user->id === $request->user()->id) {
            return redirect()->route('master.users.index')->with('error', 'Anda tidak bisa menonaktifkan akun sendiri.');
        }

        DB::transaction(function () use ($user, $active) {
            $locked = $this->lockManagedUser($user);
            if ($locked->isActive() === $active) {
                return;
            }

            $locked->forceFill([
                'deactivated_at' => $active ? null : now(),
                'remember_token' => Str::random(60),
                'password_version' => $locked->password_version + 1,
            ])->save();
        }, 3);

        return redirect()->route('master.users.index', $request->only('page'))->with('success', $active
            ? 'Akun berhasil diaktifkan kembali.'
            : 'Akun berhasil dinonaktifkan.');
    }

    // Menghapus akun yang memenuhi syarat penghapusan.
    public function destroy(User $user)
    {
        try {
            $result = DB::transaction(function () use ($user) {
                app(\App\Services\MovingAverageCostService::class)->lock();
                $locked = $this->lockManagedUser($user);
                if ($locked->id === auth()->id()) return 'self';
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
        if ($result === 'history') {
            return back()->with('error', 'Pengguna tidak dapat dihapus karena masih digunakan dalam riwayat transaksi atau aktivitas sistem. Riwayat pengguna harus tetap tersimpan.');
        }
        return redirect()->route('master.users.index')->with('success', 'Pengguna berhasil dihapus.');
    }

    // Mengambil akun yang akan dikelola dan memeriksa apakah pelaksana masih berwenang.
    private function lockManagedUser(User $user): User
    {
        // Lock both accounts so a concurrent change cannot revoke the acting owner's authority mid-operation.
        $actorId = auth()->id();
        $users = User::whereKey([$actorId, $user->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $actor = $users->get($actorId);
        abort_unless($actor?->isOwner() && $actor->isActive(), 403);

        return $users->get($user->id) ?? abort(404);
    }
}
