<?php

namespace App\Http\Controllers;

use App\Http\Presenters\CasePresenter;
use App\Jobs\RunDriveTask;
use App\Models\ProcessedReportFile;
use App\Models\TroubleCase;
use App\Models\VendorFolder;
use App\Services\QuoteSuggester;
use App\Services\ReportLinker;
use App\Services\VendorReportIngestor;
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
            'quoteSuggestions' => Cache::get(QuoteSuggester::CACHE_RESULT),
            'vendor' => Cache::get(VendorReportIngestor::CACHE_RESULT),
            'vendorFolders' => VendorFolder::orderBy('name')->get(['id', 'name', 'mode', 'machine_id', 'scanned_at']),
            'machines' => CasePresenter::machineOptions(),
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

    /** 見積PDFの紐づけ先候補を確定する／「該当なし」にする */
    public function assignQuote(Request $request, QuoteSuggester $suggester): RedirectResponse
    {
        $data = $request->validate([
            'file_id' => ['required', 'string'],
            'case_id' => ['nullable', 'required_unless:dismiss,true', 'string', 'exists:trouble_cases,id'],
            'dismiss' => ['nullable', 'boolean'],
        ]);

        if ($request->boolean('dismiss')) {
            $suggester->dismiss($data['file_id']);

            return back()->with('success', '該当なしにしました（次回以降この見積は候補に出ません）');
        }

        return $suggester->assign($data['file_id'], TroubleCase::findOrFail($data['case_id']))
            ? back()->with('success', '見積書番号と見積書PDFを対応履歴に登録しました')
            : back()->with('error', '候補が見つかりません。候補づくりをもう一度実行してください');
    }

    /** 業者別フォルダの対応表を Drive から読み直す */
    public function syncVendorFolders(VendorReportIngestor $ingestor): RedirectResponse
    {
        $n = $ingestor->syncFolders()->count();

        return back()->with('success', "業者別フォルダを読み込みました（{$n}件）");
    }

    /** 業者別フォルダの対応表を更新する */
    public function updateVendorFolder(Request $request, VendorFolder $folder): RedirectResponse
    {
        $data = $request->validate([
            'mode' => ['required', 'in:filename,fixed,skip'],
            'machine_id' => ['nullable', 'required_if:mode,fixed', 'string', 'exists:machines,id'],
        ], [], ['machine_id' => '機種']);

        $folder->update(['mode' => $data['mode'], 'machine_id' => $data['mode'] === VendorFolder::MODE_FIXED ? $data['machine_id'] : null]);

        return back()->with('success', "「{$folder->name}」の対応を保存しました");
    }
}
