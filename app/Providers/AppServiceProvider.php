<?php

namespace App\Providers;

use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseReceipt;
use App\Models\Sale;
use App\Models\StockOpname;
use App\Models\StockTransfer;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Observers\AuditObserver;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Paginator::useBootstrapFive();
        Paginator::defaultView('components.pagination');

        foreach ([
            Product::class, Category::class, Unit::class, Location::class,
            Supplier::class, User::class, PurchaseOrder::class,
            PurchaseReceipt::class, Sale::class, StockTransfer::class,
            StockOpname::class, \App\Models\PurchaseReturn::class,
        ] as $model) {
            $model::observe(AuditObserver::class);
        }
    }
}
