<?php

namespace App\Http\Controllers;

use App\Jobs\RunDriveTask;
use App\Models\ProcessedReportFile;
use App\Models\TroubleCase;
use App\Services\ReportLinker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

/** Drive連携（PDF自動取込み・報告書/見積書の自動リンク・曖昧候補の手動解決） */
class DriveSyncController extends Controller
{
    public function index(): Response
    {
        $withoutReport = TroubleCase::published()->whereNull('report_url')->count();

        return Inertia::render('Drive/Index', [
            'tasks' => collect(RunDriveTask::COMMANDS)->map(fn ($cmd, $key) => [
                'key' => $key, 'command' => $cmd, 'state' => Cache::get("navi.task.{$key}"),
            ])->values(),
            'reports' => Cache::get(ReportLinker::CACHE_REPORTS),
            'quotes' => Cache::get(ReportLinker::CACHE_QUOTES),
            'stats' => [
                'without_report' => $withoutReport,
                'without_quote_no' => TroubleCase::published()->where(fn ($q) => $q->whereNull('quote_no')->orWhere('quote_no', ''))->count(),
                'processed_files' => ProcessedReportFile::count(),
                'error_files' => ProcessedReportFile::where('result', 'like', 'error%')->count(),
            ],
            'configured' => [
                'drive' => filled(config('navi.drive.credentials')),
                'gemini' => filled(config('navi.gemini.api_key')),
                'slack' => filled(config('navi.slack.webhook_url')),
            ],
        ]);
    }

    public function run(Request $request): RedirectResponse
    {
        $task = $request->validate(['task' => ['required', 'in:'.implode(',', array_keys(RunDriveTask::COMMANDS))]])['task'];
        Cache::put("navi.task.{$task}", ['status' => 'queued', 'at' => now()->toIso8601String()]);
        RunDriveTask::dispatch($task);

        return back()->with('success', '実行を受け付けました。完了までしばらくお待ちください（キューワーカーが必要です）。');
    }

    /** 曖昧だった紐づけを候補から選んで確定する */
    public function resolve(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'in:reports,quotes'],
            'case_id' => ['required', 'string', 'exists:trouble_cases,id'],
            'url' => ['required', 'url'],
        ]);

        TroubleCase::whereKey($data['case_id'])->update([$data['kind'] === 'reports' ? 'report_url' : 'quote_url' => $data['url']]);

        $key = $data['kind'] === 'reports' ? ReportLinker::CACHE_REPORTS : ReportLinker::CACHE_QUOTES;
        if ($last = Cache::get($key)) {
            $last['ambiguous'] = array_values(array_filter($last['ambiguous'], fn ($a) => $a['case_id'] !== $data['case_id']));
            Cache::forever($key, $last);
        }

        return back()->with('success', 'リンクしました');
    }
}
