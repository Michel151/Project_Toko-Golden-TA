<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AccountPasswordService
{
    public function replace(User $user, string $password, bool $temporary): User
    {
        return DB::transaction(function () use ($user, $password, $temporary) {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $locked->forceFill([
                'password' => Hash::make($password),
                'remember_token' => Str::random(60),
                'must_change_password' => $temporary,
                'password_version' => (int) $locked->password_version + 1,
            ])->save();
            AuditService::record($temporary ? 'password_reset' : 'password_changed', 'Pengguna',
                $temporary ? 'Reset password sementara untuk '.$locked->name : 'Mengganti password akun '.$locked->name, $locked);

            return $locked;
        });
    }
}
