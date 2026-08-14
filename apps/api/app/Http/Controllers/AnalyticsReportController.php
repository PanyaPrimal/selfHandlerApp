<?php

namespace App\Http\Controllers;

use App\Services\Analytics\AnalyticsQuery;
use App\Services\Analytics\AnalyticsWorkspaceService;
use App\Services\Reports\AnalyticsReportRenderer;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AnalyticsReportController extends Controller
{
    public function __construct(
        private readonly AnalyticsQuery $query,
        private readonly AnalyticsWorkspaceService $workspaces,
        private readonly AnalyticsReportRenderer $renderer,
    ) {}

    public function csv(Request $request): Response
    {
        return $this->download($request, 'csv');
    }

    public function pdf(Request $request): Response
    {
        return $this->download($request, 'pdf');
    }

    private function download(Request $request, string $format): Response
    {
        $user = $request->user();
        $user->ensureProfile();
        $query = $this->query->workspace($request, $user);
        $workspace = $this->workspaces->workspace($user, ...$query);
        $content = $format === 'pdf'
            ? $this->renderer->pdf($workspace, app()->getLocale())
            : $this->renderer->csv($workspace, app()->getLocale());
        $extension = $format;
        $filename = trans('reports.filename', [
            'metric' => str_replace(['.', '_'], '-', $query['metric']),
            'from' => $query['from'], 'to' => $query['to'],
        ], app()->getLocale()).'.'.$extension;

        return response($content, 200, [
            'Content-Type' => $format === 'pdf' ? 'application/pdf' : 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache', 'Expires' => '0', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
