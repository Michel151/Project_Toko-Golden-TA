<?php

namespace App\Http\Controllers;

use App\Exports\ReportExport;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\AuditLogFormatter;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class AuditLogController extends Controller
{
    private const EXPORT_HEADINGS = ['Waktu', 'Pengguna', 'Peran', 'Modul', 'Aksi', 'Aktivitas', 'Alamat IP'];

    public function index(Request $request, AuditLogFormatter $formatter)
    {
        $auditLogs = $this->filteredLogs($request)->paginate(30)->withQueryString();
        $auditDetails = $formatter->format($auditLogs->getCollection());
        $users = User::orderBy('name')->get(['id', 'name']);
        $modules = AuditLog::query()->distinct()->orderBy('module')->pluck('module');
        $todayCount = AuditLog::whereDate('created_at', today())->count();
        $loginCount = AuditLog::whereDate('created_at', today())->where('action', 'login')->count();
        $failedLoginCount = AuditLog::whereDate('created_at', today())->where('action', 'login_failed')->count();

        return view('audit-logs.index', compact(
            'auditLogs', 'auditDetails', 'users', 'modules', 'todayCount', 'loginCount', 'failedLoginCount'
        ));
    }

    public function pdf(Request $request)
    {
        $rows = $this->exportRows($request);
        $headings = self::EXPORT_HEADINGS;

        return Pdf::loadView('audit-logs.pdf', compact('rows', 'headings'))->setPaper('a4', 'landscape')
            ->stream('Audit-Log-'.now('Asia/Jakarta')->toDateString().'.pdf');
    }

    public function excel(Request $request)
    {
        $rows = $this->exportRows($request);
        $export = new ReportExport('Audit Log Sistem', self::EXPORT_HEADINGS, $rows,
            sheetTitle: 'Audit Log', showGridlines: true, reportPeriod: 'Total aktivitas: '.count($rows));

        return Excel::download($export, 'Audit-Log-'.now('Asia/Jakarta')->toDateString().'.xlsx');
    }

    private function exportRows(Request $request): array
    {
        return $this->filteredLogs($request)
            ->get(['user_id', 'action', 'module', 'description', 'ip_address', 'created_at'])
            ->map(fn (AuditLog $log) => [
                $log->created_at->format('d/m/Y H:i:s'),
                $log->user->name ?? 'Tamu/Sistem',
                $log->user->role ?? '-',
                $log->module,
                $log->action_label,
                $log->description,
                $log->ip_address ?? '-',
            ])->all();
    }

    private function filteredLogs(Request $request): Builder
    {
        return AuditLog::with('user:id,name,role')
            ->when($request->filled('search'), fn ($q) => $q->where('description', 'like', '%'.$request->search.'%'))
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->user_id))
            ->when($request->filled('module'), fn ($q) => $q->where('module', $request->module))
            ->when($request->filled('action'), fn ($q) => $q->where('action', $request->action))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->to))
            ->latest()->latest('id');
    }
}
