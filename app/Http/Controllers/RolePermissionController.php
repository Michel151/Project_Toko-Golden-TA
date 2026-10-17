<?php

namespace App\Http\Controllers;

use App\Services\AuditService;
use App\Support\AccessPermissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class RolePermissionController extends Controller
{
    public function index(Request $request)
    {
        $role = $request->query('role', 'kasir');
        abort_unless(is_string($role) && array_key_exists($role, AccessPermissions::ROLES), 404);

        return view('permissions.index', [
            'role' => $role,
            'roles' => AccessPermissions::ROLES,
            'features' => AccessPermissions::features(),
            'selected' => DB::table('role_permissions')->where('role', $role)->pluck('permission')->all(),
        ]);
    }

    public function update(Request $request, string $role)
    {
        abort_unless(array_key_exists($role, AccessPermissions::ROLES), 404);
        $data = $request->validate([
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['required', 'string', 'distinct', Rule::in(AccessPermissions::keys())],
        ]);
        $permissions = $data['permissions'] ?? [];
        foreach ($permissions as $permission) {
            $feature = substr($permission, 0, strrpos($permission, '.'));
            if (! in_array("$feature.view", $permissions, true)) {
                return back()->withErrors(['permissions' => 'Izin melihat harus dipilih untuk fitur yang memiliki izin tindakan.'])->withInput();
            }
        }
        DB::transaction(function () use ($role, $permissions) {
            $previous = DB::table('role_permissions')->where('role', $role)->pluck('permission')->all();
            DB::table('role_permissions')->where('role', $role)->delete();
            foreach ($permissions as $permission) {
                DB::table('role_permissions')->insert(compact('role', 'permission'));
            }
            AuditService::record('updated', 'Hak Akses', 'Mengubah hak akses '.AccessPermissions::ROLES[$role],
                oldValues: ['role' => $role, 'permissions' => $previous],
                newValues: ['role' => $role, 'permissions' => $permissions]);
        });

        return redirect()->route('permissions.index', ['role' => $role])
            ->with('success', 'Hak akses '.AccessPermissions::ROLES[$role].' berhasil diperbarui.');
    }
}
