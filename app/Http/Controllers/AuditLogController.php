<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\AuditLogFormatter;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request, AuditLogFormatter $formatter)
    {
        $query = AuditLog::with('user')
            ->when($request->filled('search'), fn ($q) => $q->where('description', 'like', '%'.$request->search.'%'))
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->user_id))
            ->when($request->filled('module'), fn ($q) => $q->where('module', $request->module))
            ->when($request->filled('action'), fn ($q) => $q->where('action', $request->action))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->to));

        $auditLogs = $query->latest()->paginate(30)->withQueryString();
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
}
