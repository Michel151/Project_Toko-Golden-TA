<?php

namespace App\Models;

use App\Support\AccessPermissions;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'password_version',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'must_change_password' => 'boolean',
            'password_version' => 'integer',
        ];
    }

    // Helper methods untuk cek role
    public function isOwner(): bool
    {
        return $this->role === 'owner';
    }

    public function isKasir(): bool
    {
        return $this->role === 'kasir';
    }

    public function isStafGudang(): bool
    {
        return $this->role === 'staf_gudang';
    }

    public function hasRole(string|array $roles): bool
    {
        if (is_array($roles)) {
            return in_array($this->role, $roles);
        }

        return $this->role === $roles;
    }

    public function hasPermission(string $permission): bool
    {
        if ($this->isOwner()) {
            return true;
        }
        if (! in_array($permission, AccessPermissions::keys(), true)) {
            return false;
        }
        // Share one lookup within a request; never keep permissions across requests.
        $request = request();
        $key = 'access.permissions.'.$this->role;
        if (! $request->attributes->has($key)) {
            $request->attributes->set($key, DB::table('role_permissions')
                ->where('role', $this->role)->pluck('permission')->all());
        }
        $permissions = $request->attributes->get($key);
        $feature = substr($permission, 0, strrpos($permission, '.'));

        return in_array($permission, $permissions, true)
            && in_array("$feature.view", $permissions, true);
    }

    public function canAccessRoute(string $name): bool
    {
        $permission = AccessPermissions::forRoute($name);

        return $permission !== null && $this->hasPermission($permission);
    }

    // Relationships
    public function hasTransactionHistory(): bool
    {
        $references = [
            'purchase_orders' => ['created_by'],
            'purchase_receipts' => ['received_by'],
            'sales' => ['created_by'],
            'stock_transfers' => ['created_by'],
            'stock_opnames' => ['created_by'],
            'purchase_returns' => ['created_by', 'approved_by', 'dispatched_by'],
            'expenses' => ['created_by', 'confirmed_by'],
            'monthly_taxes' => ['created_by', 'confirmed_by'],
            'monthly_tax_revisions' => ['created_by'],
            'inventory_cost_settings' => ['enabled_by'],
            'inventory_cost_entries' => ['created_by'],
            'stock_movements' => ['created_by'],
            'product_import_runs' => ['user_id'],
            'audit_logs' => ['user_id'],
        ];

        foreach ($references as $table => $columns) {
            if (DB::table($table)->where(function ($query) use ($columns) {
                foreach ($columns as $column) $query->orWhere($column, $this->id);
            })->exists()) return true;
        }

        return false;
    }

    public function purchaseOrders()
    {
        return $this->hasMany(PurchaseOrder::class, 'created_by');
    }

    public function purchaseReceipts()
    {
        return $this->hasMany(PurchaseReceipt::class, 'received_by');
    }

    public function sales()
    {
        return $this->hasMany(Sale::class, 'created_by');
    }

    public function stockTransfers()
    {
        return $this->hasMany(StockTransfer::class, 'created_by');
    }

    public function stockOpnames()
    {
        return $this->hasMany(StockOpname::class, 'created_by');
    }
}
