<?php

namespace App\Http\Controllers;

use App\Models\{Product, PurchaseReceipt, Sale};
use App\Services\MovingAverageCostService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryCostController extends Controller
{
    public function index(Request $request, MovingAverageCostService $costs)
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:150']]);
        $products = Product::with(['costBalance', 'baseProductUnit.unit', 'buyUnit'])->when($filters['q'] ?? null,
            fn ($query, $search) => $query->where('name', 'like', '%'.$search.'%'))->orderBy('name')->orderBy('id')->paginate(25)->withQueryString();
        $owned = []; $issues = [];
        foreach ($products as $product) {
            try { $owned[$product->id] = $costs->ownedQuantity($product->id); }
            catch (ValidationException $error) { $issues[$product->id] = $error->getMessage(); }
        }
        return view('inventory.costing', compact('products', 'owned', 'issues'));
    }

    public function show(Product $product)
    {
        $product->load('costBalance', 'baseProductUnit.unit', 'buyUnit');
        $entries = DB::table('inventory_cost_entries')->where('product_id', $product->id)->orderByDesc('id')->paginate(50);
        $receiptTypes = array_unique([PurchaseReceipt::class, (new PurchaseReceipt)->getMorphClass()]);
        $saleTypes = array_unique([Sale::class, (new Sale)->getMorphClass()]);
        $receiptDocuments = PurchaseReceipt::query()
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_receipts.purchase_order_id')
            ->whereIn('purchase_receipts.id', $entries->getCollection()->whereIn('reference_type', $receiptTypes)->pluck('reference_id'))
            ->get(['purchase_receipts.id', 'purchase_orders.id as order_id', 'purchase_orders.po_number'])->keyBy('id');
        $saleNumbers = Sale::whereIn('id', $entries->getCollection()->whereIn('reference_type', $saleTypes)->pluck('reference_id'))
            ->pluck('sale_number', 'id');
        foreach ($entries as $entry) {
            $entry->reference_url = null;
            if (in_array($entry->reference_type, $receiptTypes, true)) {
                $document = $receiptDocuments->get($entry->reference_id);
                $entry->reference_label = $document?->po_number ?? '-';
                if ($document) $entry->reference_url = route('purchase.orders.show', $document->order_id);
            } elseif (in_array($entry->reference_type, $saleTypes, true)) {
                $entry->reference_label = $saleNumbers->get($entry->reference_id) ?? '-';
                if ($saleNumbers->has($entry->reference_id)) $entry->reference_url = route('sale.sales.show', $entry->reference_id);
            } else {
                $entry->reference_label = $entry->reference_type && $entry->reference_id
                    ? class_basename($entry->reference_type).' #'.$entry->reference_id : '-';
            }
        }
        return view('inventory.costing-history', compact('product', 'entries'));
    }
}
