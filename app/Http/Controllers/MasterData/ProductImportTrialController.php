<?php

namespace App\Http\Controllers\MasterData;

use App\Exports\ProductImportTrialTemplateExport;
use App\Http\Controllers\Controller;
use App\Services\ProductImportTrialService;
use App\Services\MultiUnitProductImportService;
use App\Models\ProductImportRun;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class ProductImportTrialController extends Controller
{
    public function create(Request $request, MultiUnitProductImportService $service)
    {
        $token=$request->query('preview') ?: old('token');
        if($token){
            $run=ProductImportRun::where('token',$token)->where('user_id',auth()->id())->where('status','pending')->first();
            if($run && $run->payload && $run->expires_at->isFuture()){
                $preview=$service->prepare($run->payload);
                $preview['products']=array_slice($preview['products'],0,100);
                $preview['stocks']=array_slice($preview['stocks'],0,200);
                if($preview['error_count']) $run=null;
                return view('master.products.import-trial',['preview'=>$preview,'run'=>$run,'canStore'=>true]);
            }
            if($request->query('preview')) abort(404);
        }
        return view('master.products.import-trial');
    }

    public function template()
    {
        return Excel::download(new ProductImportTrialTemplateExport, 'template-import-barang.xlsx');
    }

    public function preview(Request $request, MultiUnitProductImportService $service)
    {
        $request->validate(['file' => ['required', 'file', 'mimes:xlsx', 'max:5120']]);
        $sheets = app(\App\Services\TrialWorkbookReader::class)->read($request->file('file'), 3, 5);

        // Old five-sheet files remain preview-only; supplier import is now separate.
        $preview=app(ProductImportTrialService::class)->preview($sheets,auth()->user()->hasPermission('master.suppliers.view'));
        $canStore=count($sheets)===3;
        $run=null;
        if($canStore){
            $plan=$service->prepare($sheets);
            $preview['errors']=$plan['errors']; $preview['error_count']=$plan['error_count'];
            if(!$plan['error_count']) $run=$service->stage($sheets,auth()->id());
        }
        return view('master.products.import-trial',compact('preview','run','canStore'));
    }

    public function store(Request $request, MultiUnitProductImportService $service)
    {
        $data=$request->validate(['token'=>'required|uuid','confirmed'=>'required|accepted']);
        try {
            $result=$service->commit($data['token'],auth()->id());
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['file'=>'Impor lain sedang diproses. Tunggu sebentar, lalu konfirmasi kembali; belum ada data dari impor ini yang disimpan.']);
        }
        return redirect()->route('master.products.index')->with('success',
            "Impor multi satuan selesai: {$result['created']} barang baru, {$result['units']} satuan, {$result['stocks']} stok lokasi, {$result['batches']} rincian kedaluwarsa. Konfirmasi ulang tidak menggandakan data.");
    }
}
