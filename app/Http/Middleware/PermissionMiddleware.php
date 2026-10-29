<?php

namespace App\Http\Middleware;

use App\Support\AccessPermissions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PermissionMiddleware
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $name = $request->route()->getName() ?? '';
        $permission = AccessPermissions::forRoute($name);
        $allowed = $permission !== null && $request->user()?->hasPermission($permission);
        if (in_array($name, ['sale.product.data', 'sale.stock.data', 'sale.drafts.index', 'sale.drafts.update', 'purchase.orders.supplier-products'], true)) {
            $allowed = $allowed || $request->user()?->hasPermission("$feature.edit");
        }
        abort_unless($permission !== null && str_starts_with($permission, "$feature.")
            && $allowed, 403, 'Anda tidak memiliki izin untuk tindakan ini.');

        return $next($request);
    }
}
