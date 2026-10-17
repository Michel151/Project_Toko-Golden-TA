<?php

use App\Support\AccessPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_permissions', function (Blueprint $table) {
            $table->string('role');
            $table->string('permission');
            $table->primary(['role', 'permission']);
        });
        foreach (array_keys(AccessPermissions::ROLES) as $role) {
            foreach (AccessPermissions::defaults($role) as $permission) {
                DB::table('role_permissions')->insert(compact('role', 'permission'));
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permissions');
    }
};
