<?php

namespace App\Services;

use App\Models\{Product, ProductUnit, Supplier, SupplierImportRun};
use Illuminate\Support\Facades\{Cache, DB};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SupplierImportService
{
    public function prepare(array $sheets, bool $canReadProducts): array
    {
        $plan = app(SupplierImportTrialService::class)->preview($sheets, $canReadProducts, true, true);
        $names = []; $codes = [];
        foreach ($plan['suppliers'] as &$supplier) {
            $code = $this->key($supplier['code']);
            if (isset($codes[$code])) {
                $plan['errors'][] = ['sheet'=>'Master Supplier','row'=>1,'message'=>'Kode supplier ganda setelah spasi dirapikan: '.$supplier['code']];
                $plan['error_count']++;
            }
            $codes[$code] = true;
            $name = $this->key($supplier['name']);
            if (isset($names[$name]) && (!$supplier['id'] || !$names[$name])) {
                $plan['errors'][] = ['sheet'=>'Master Supplier','row'=>1,'message'=>'Nama supplier ganda dalam file: '.$supplier['name']];
                $plan['error_count']++;
            }
            $names[$name] = $supplier['id'] ?? 0;
            $supplier['status'] = $supplier['id'] ? 'Terdaftar (tidak diubah)' : 'Supplier baru';
        }
        unset($supplier);
        foreach ($plan['supplier_links'] as &$link) {
            $link['status'] = $link['supplier_id'] ? 'Terdaftar (tidak diubah)' : 'Supplier baru';
            $link['relationship_status'] = $link['existing_price'] !== null ? 'Sudah terhubung (tidak diubah)' : 'Hubungan baru';
        }
        unset($link);
        $plan['errors'] = array_slice($plan['errors'], 0, 200);
        return $plan;
    }

    public function stage(array $sheets, array $plan, int $userId): SupplierImportRun
    {
        if ($plan['error_count']) $this->invalid($plan);
        SupplierImportRun::where('user_id', $userId)->where('status', 'pending')->where('expires_at', '<', now())->delete();
        return SupplierImportRun::create([
            'token'=>(string) Str::uuid(), 'user_id'=>$userId,
            'payload'=>['sheets'=>$sheets, 'bindings'=>$this->bindings($plan)], 'expires_at'=>now()->addHour(),
        ]);
    }

    public function commit(string $token, int $userId, bool $canReadProducts): array
    {
        return Cache::store('file')->lock('supplier-import', 900)->block(10, function () use ($token, $userId, $canReadProducts) {
            return DB::transaction(function () use ($token, $userId, $canReadProducts) {
                $run = SupplierImportRun::where('token', $token)->where('user_id', $userId)->lockForUpdate()->firstOrFail();
                if ($run->status === 'completed') return $run->result;
                if ($run->expires_at->isPast() || !$run->payload) {
                    throw ValidationException::withMessages(['file'=>'Pratinjau sudah kedaluwarsa. Unggah ulang file sebelum menyimpan.']);
                }
                $payload = $run->payload;
                $ids = array_unique(array_column($payload['bindings']['links'], 'product_id'));
                Product::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
                ProductUnit::whereIn('product_id', $ids)->orderBy('id')->lockForUpdate()->get();
                Supplier::orderBy('id')->lockForUpdate()->get();
                $plan = $this->prepare($payload['sheets'], $canReadProducts);
                if ($plan['error_count']) $this->invalid($plan);
                $current = $this->bindings($plan);
                $changed = $current['links'] !== $payload['bindings']['links'];
                foreach ($payload['bindings']['suppliers'] as $code => $id) {
                    if ($id !== null && ($current['suppliers'][$code] ?? null) !== $id) $changed = true;
                }
                if ($changed) {
                    throw ValidationException::withMessages(['file'=>'Referensi supplier, barang, atau satuan berubah sejak pratinjau. Unggah dan periksa ulang file.']);
                }

                $result = ['suppliers_created'=>0, 'suppliers_reused'=>0, 'links_created'=>0, 'links_skipped'=>0];
                $suppliers = [];
                foreach ($plan['suppliers'] as $row) {
                    if ($row['id']) {
                        $supplier = Supplier::findOrFail($row['id']);
                        $result['suppliers_reused']++;
                    } else {
                        $supplier = Supplier::create(['name'=>preg_replace('/\s+/u', ' ', trim($row['name'])), 'address'=>$row['address'], 'phone'=>$row['phone']]);
                        $result['suppliers_created']++;
                    }
                    $suppliers[$this->key($row['code'])] = $supplier->id;
                }
                foreach ($plan['supplier_links'] as $row) {
                    $pair = ['supplier_id'=>$suppliers[$this->key($row['supplier_code'])], 'product_id'=>(int) $row['product_code']];
                    if (DB::table('product_supplier')->where($pair)->lockForUpdate()->exists()) {
                        $result['links_skipped']++;
                        continue;
                    }
                    DB::table('product_supplier')->insert($pair + ['buy_price'=>$row['purchase_price'], 'created_at'=>now(), 'updated_at'=>now()]);
                    $result['links_created']++;
                }
                $run->update(['status'=>'completed', 'result'=>$result, 'payload'=>null]);
                return $result;
            }, 3);
        });
    }

    public function display(array $plan): array
    {
        $plan['suppliers'] = array_slice($plan['suppliers'], 0, 200);
        $plan['supplier_links'] = array_slice($plan['supplier_links'], 0, 200);
        return $plan;
    }

    private function bindings(array $plan): array
    {
        $suppliers = [];
        foreach ($plan['suppliers'] as $row) $suppliers[$this->key($row['code'])] = $row['id'];
        $links = array_map(fn ($row) => [
            'product_id'=>(int) $row['product_code'], 'barcode'=>$row['barcode'], 'supplier_code'=>$this->key($row['supplier_code']),
            'unit'=>$row['unit'], 'input_conversion'=>$row['input_conversion'], 'purchase_unit'=>$row['existing_unit'],
            'purchase_conversion'=>$row['purchase_conversion'], 'purchase_price'=>$row['purchase_price'],
        ], $plan['supplier_links']);
        return compact('suppliers', 'links');
    }

    private function invalid(array $plan): never
    {
        throw ValidationException::withMessages(['file'=>array_map(fn ($issue) => $issue['sheet'].' baris '.$issue['row'].': '.$issue['message'], $plan['errors'])]);
    }

    private function key(string $value): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($value)));
    }
}
