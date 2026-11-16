<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\ReportService;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function __construct(private ReportService $reports) {}

    public function dashboard(Request $request): JsonResponse
    {
        $ctx = $this->tenant();
        abort_unless($ctx->can('reports.operational') || $ctx->can('reports.financial'), 403);
        $data = $this->reports->dashboard($request->query('from'), $request->query('to'), $request->query('branch_id'));
        if (! $ctx->can('reports.financial')) {
            unset($data['revenue'], $data['today']['revenue'], $data['services']);
            $data['doctors'] = collect($data['doctors'])->map(fn ($d) => collect($d)->except('revenue'))->all();
            $data['branches'] = collect($data['branches'])->map(fn ($b) => collect($b)->except('collected'))->all();
        }

        return $this->ok($data);
    }

    public function show(Request $request, string $report): JsonResponse
    {
        $this->authorizeReport($report);

        return $this->ok($this->reports->rows($report, $request->query('from'), $request->query('to'), $request->query('branch_id')));
    }

    public function export(Request $request, string $report): StreamedResponse
    {
        $this->authorizeReport($report);
        abort_unless($this->tenant()->can('reports.export'), 403);
        $rows = $this->reports->rows($report, $request->query('from'), $request->query('to'), $request->query('branch_id'));
        AuditLogger::log('report.exported', null, null, ['report' => $report, 'rows' => count($rows)]);

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
            if ($rows) {
                fputcsv($out, array_keys((array) $rows[0]));
            }
            foreach ($rows as $row) {
                // Neutralise spreadsheet formula injection.
                fputcsv($out, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'".$v : (is_array($v) ? json_encode($v) : $v), (array) $row));
            }
            fclose($out);
        }, $report.'-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function authorizeReport(string $report): void
    {
        $financial = in_array($report, ['collections', 'outstanding', 'services', 'gst'], true);
        abort_unless($this->tenant()->can($financial ? 'reports.financial' : 'reports.operational'), 403);
    }

    public function audit(Request $request): JsonResponse
    {
        abort_unless($this->tenant()->can('audit.view'), 403);
        $q = AuditLog::where('organization_id', $this->tenant()->id())
            ->when($request->query('user_id'), fn ($q, $u) => $q->where('user_id', $u))
            ->when($request->query('entity'), fn ($q, $e) => $q->where('auditable_type', $e))
            ->when($request->query('entity_id'), fn ($q, $e) => $q->where('auditable_id', $e))
            ->when($request->query('event'), fn ($q, $e) => $q->where('event', $e))
            ->when($this->tenant()->branchIds(), fn ($q, $ids) => $q->where(fn ($w) => $w->whereIn('branch_id', $ids)->orWhereNull('branch_id')))
            ->latest('created_at');

        return $this->paginated($q->paginate($this->perPage($request)));
    }
}
