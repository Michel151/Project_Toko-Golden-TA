<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Stock;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\StockOpname;
use App\Models\StockTransfer;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryStockAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_cashier_can_open_stock_monitoring(): void
    {
        $cashier = User::create([
            'name' => 'Kasir',
            'email' => 'kasir-stock@example.com',
            'password' => 'password',
            'role' => 'kasir',
        ]);

        $this->actingAs($cashier)
            ->get(route('inventory.stock.index'))
            ->assertOk()
            ->assertSee('Monitoring Stok')
            ->assertSee('Monitoring Persediaan')
            ->assertDontSee('Transfer Stok')
            ->assertDontSee('Stock Opname');
    }

    public function test_cashier_can_open_stock_card_but_cannot_open_stock_transfer(): void
    {
        $cashier = User::create([
            'name' => 'Kasir',
            'email' => 'kasir-stock-card@example.com',
            'password' => 'password',
            'role' => 'kasir',
        ]);
        $category = Category::create(['name' => 'Kategori Tes']);
        $unit = Unit::create(['name' => 'Pcs']);
        $product = Product::create([
            'name' => 'Produk Tes',
            'category_id' => $category->id,
            'buy_unit_id' => $unit->id,
            'buy_price' => 10000,
            'sell_price_wholesale' => 12000,
        ]);

        $this->actingAs($cashier)
            ->get(route('inventory.stock-card.show', $product))
            ->assertOk();

        $this->actingAs($cashier)
            ->get(route('inventory.transfers.create'))
            ->assertForbidden();
    }

    public function test_only_owner_sees_inventory_value_in_stock_monitoring(): void
    {
        $product = $this->makeProduct('Barang Uji Akses', Category::create(['name' => 'Tes Nilai']), Unit::create(['name' => 'Pcs']));
        $product->update(['is_expirable' => false]);
        Stock::create([
            'product_id' => $product->id,
            'location_id' => Location::create(['name' => 'Toko'])->id,
            'quantity' => 17,
        ]);
        $value = $product->fresh()->inventoryValue();
        $this->assertGreaterThan(0, $value);
        $formatted = 'Rp '.number_format($value, 0, ',', '.');

        foreach (['owner', 'staf_gudang', 'kasir'] as $role) {
            $user = User::create([
                'name' => $role, 'email' => $role.'-inventory-value@example.com',
                'password' => 'password', 'role' => $role,
            ]);
            foreach (['stock', 'priorities'] as $view) {
                $response = $this->actingAs($user)->get(route('inventory.stock.index', ['view' => $view]))
                    ->assertOk()->assertSee('Jenis Barang')->assertSee('Layak Jual Rendah')
                    ->assertViewHas('canViewInventoryValue', $role === 'owner');
                if ($role === 'owner') {
                    $response->assertSee('Nilai Persediaan')->assertSee($formatted)
                        ->assertViewHas('inventoryValue', $value);
                } else {
                    $response->assertDontSee('Nilai Persediaan')->assertDontSee($formatted)
                        ->assertViewHas('inventoryValue', fn ($value) => $value === null);
                }
            }
        }
    }

    public function test_stock_card_and_movement_links_only_available_documents_the_user_can_open(): void
    {
        $cashier = User::create(['name' => 'Kasir', 'email' => 'references-cashier@test.com', 'password' => 'password', 'role' => 'kasir']);
        $owner = User::create(['name' => 'Owner', 'email' => 'references-owner@test.com', 'password' => 'password', 'role' => 'owner']);
        $location = Location::create(['name' => 'Toko']);
        $warehouse = Location::create(['name' => 'Gudang']);
        $product = $this->makeProduct('Barang Referensi', Category::create(['name' => 'Tes']), Unit::create(['name' => 'Pcs']));
        $sale = ['sale_date' => today(), 'total_amount' => 1000, 'payment_method' => 'tunai', 'status' => 'paid'];
        $ownSale = Sale::create($sale + ['sale_number' => 'INV-SENDIRI', 'created_by' => $cashier->id]);
        $otherSale = Sale::create($sale + ['sale_number' => 'INV-OWNER', 'created_by' => $owner->id]);
        $transfer = StockTransfer::create(['transfer_number' => 'TRF-UJI', 'transfer_date' => today(),
            'from_location_id' => $warehouse->id, 'to_location_id' => $location->id, 'created_by' => $owner->id,
            'product_id' => $product->id, 'quantity' => 2]);
        $opname = StockOpname::create(['opname_number' => 'OPN-UJI', 'opname_date' => today(),
            'location_id' => $location->id, 'created_by' => $owner->id, 'status' => 'completed']);
        $base = ['product_id' => $product->id, 'location_id' => $location->id, 'movement_date' => today(),
            'balance_before' => 0, 'balance_after' => 0, 'created_by' => $owner->id];
        foreach ([
            [$ownSale, 'sale', $ownSale->sale_number, -1],
            [$otherSale, 'sale', $otherSale->sale_number, -1],
            [$transfer, 'transfer_in', $transfer->transfer_number, 2],
            [$opname, 'opname_adjustment', $opname->opname_number, 0],
        ] as [$document, $type, $number, $quantity]) {
            StockMovement::create($base + ['movement_type' => $type, 'quantity' => $quantity,
                'reference_type' => $document->getMorphClass(), 'reference_id' => $document->id, 'reference_number' => $number]);
        }
        StockMovement::create($base + ['movement_type' => 'sale', 'quantity' => -1,
            'reference_type' => Sale::class, 'reference_id' => 999999, 'reference_number' => 'INV-LAMA']);

        $url = route('inventory.stock-card.show', $product);
        $ownSaleUrl = route('sale.sales.show', $ownSale);
        $otherSaleUrl = route('sale.sales.show', $otherSale);
        $transferUrl = route('inventory.transfers.show', $transfer);
        $opnameUrl = route('inventory.opnames.show', $opname);
        $cashierCard = $this->actingAs($cashier)->get($url)->assertOk()
            ->assertSee('href="'.e($ownSaleUrl).'"', false)->assertSee('INV-OWNER');
        foreach ([$otherSaleUrl, $transferUrl, $opnameUrl] as $restricted) {
            $cashierCard->assertDontSee('href="'.e($restricted).'"', false);
            $this->get($restricted)->assertForbidden();
        }
        $ownerCard = $this->actingAs($owner)->get($url)->assertOk()->assertSee('INV-LAMA')
            ->assertDontSee('href="'.e(route('sale.sales.show', 999999)).'"', false);
        foreach ([$ownSaleUrl, $otherSaleUrl, $transferUrl, $opnameUrl] as $visible) {
            $ownerCard->assertSee('href="'.e($visible).'"', false);
        }

        // Kasir dapat diberi akses Mutasi Barang, tetapi tetap hanya membuka nota sendiri.
        \Illuminate\Support\Facades\DB::table('role_permissions')->insert([
            'role' => 'kasir', 'permission' => 'inventory.opnames.view',
        ]);
        $movementUrl = route('inventory.opnames.movements');
        foreach ([$owner, $cashier] as $viewer) {
            $response = $this->actingAs($viewer)->get($movementUrl)->assertOk()
                ->assertSee('href="'.e($ownSaleUrl).'"', false)
                ->assertSee('href="'.e($opnameUrl).'"', false)
                ->assertSee('INV-LAMA')
                ->assertDontSee('href="'.e(route('sale.sales.show', 999999)).'"', false);
            foreach ([$otherSaleUrl, $transferUrl] as $referenceUrl) {
                if ($viewer->isOwner()) {
                    $response->assertSee('href="'.e($referenceUrl).'"', false);
                } else {
                    $response->assertDontSee('href="'.e($referenceUrl).'"', false);
                }
            }
        }
    }

    public function test_stock_monitoring_can_filter_by_expiry_status(): void
    {
        Carbon::setTestNow('2026-09-26');

        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner-stock-expiry@example.com',
            'password' => 'password',
            'role' => 'owner',
        ]);
        $category = Category::create(['name' => 'Minuman']);
        $unit = Unit::create(['name' => 'Pcs']);
        $location = Location::create(['name' => 'Gudang']);

        $expiredProduct = $this->makeProduct('Roti Kedaluwarsa', $category, $unit);
        $expiringProduct = $this->makeProduct('Yogurt Segera Dicek', $category, $unit);
        $safeProduct = $this->makeProduct('Sirup Jangka Panjang', $category, $unit);

        $this->makeStockBatch($expiredProduct, $location, '2026-09-25');
        $this->makeStockBatch($expiringProduct, $location, '2026-10-20');
        $this->makeStockBatch($safeProduct, $location, '2027-02-01');

        $this->actingAs($owner)
            ->get(route('inventory.stock.index', ['expiry_status' => 'expired']))
            ->assertOk()
            ->assertSee('Roti Kedaluwarsa')
            // The global bell remains unfiltered; assert the stock results separately.
            ->assertViewHas('products', fn ($products) => $products->pluck('id')->all() === [$expiredProduct->id]);

        $this->actingAs($owner)
            ->get(route('inventory.stock.index', ['expiry_status' => 'expiry_urgent']))
            ->assertOk()
            ->assertSee('Yogurt Segera Dicek')
            ->assertViewHas('products', fn ($products) => $products->pluck('id')->all() === [$expiringProduct->id]);

        $this->actingAs($owner)
            ->get(route('inventory.stock.index', ['expiry_status' => 'safe']))
            ->assertOk()
            ->assertSee('Sirup Jangka Panjang')
            ->assertViewHas('products', fn ($products) => $products->pluck('id')->all() === [$safeProduct->id]);

        Carbon::setTestNow();
    }

    private function makeProduct(string $name, Category $category, Unit $unit): Product
    {
        return Product::create([
            'name' => $name,
            'category_id' => $category->id,
            'buy_unit_id' => $unit->id,
            'buy_price' => 10000,
            'sell_price_wholesale' => 12000,
            'is_expirable' => true,
            'min_stock_total' => 0,
        ]);
    }

    private function makeStockBatch(Product $product, Location $location, string $expiredDate): void
    {
        Stock::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => 10,
        ]);

        StockBatch::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'received_date' => '2026-09-01',
            'expired_date' => $expiredDate,
            'quantity_initial' => 10,
            'quantity_remaining' => 10,
        ]);
    }
}
