<?php

namespace App\Http\Controllers;

use App\Http\Presenters\CasePresenter;
use App\Http\Requests\CaseRequest;
use App\Models\CasePhoto;
use App\Models\Machine;
use App\Models\TroubleCase;
use App\Services\SimilarCaseFinder;
use App\Services\SlackNotifier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CaseController extends Controller
{
    /** 症状検索 */
    public function index(Request $request): Response
    {
        $filters = $request->only(['q', 'machine', 'site', 'from', 'to', 'sort']);

        $cases = $this->searchQuery($filters)
            ->with(['machine', 'photos', 'ratings'])
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Cases/Index', [
            'filters' => $filters,
            'cases' => [
                'data' => CasePresenter::many($cases->items(), $request->user()),
                'total' => $cases->total(),
                'current_page' => $cases->currentPage(),
                'last_page' => $cases->lastPage(),
                'prev_url' => $cases->previousPageUrl(),
                'next_url' => $cases->nextPageUrl(),
            ],
            'machines' => CasePresenter::machineOptions(),
            'sites' => Machine::whereNotNull('site')->distinct()->orderBy('site')->pluck('site'),
        ]);
    }

    /** 検索結果をCSVで出力（Excelで開けるようUTF-8 BOM付き） */
    public function export(Request $request): StreamedResponse
    {
        $query = $this->searchQuery($request->only(['q', 'machine', 'site', 'from', 'to', 'sort']))->with('machine');
        $columns = [
            'ID' => 'id', '対応日' => fn ($c) => $c->date?->format('Y-m-d'), '機種' => fn ($c) => $c->machine?->model,
            '機械番号' => 'machine_id', '拠点' => fn ($c) => $c->machine?->site, '担当者' => 'engineer', '症状' => 'symptom',
            '原因' => 'cause', '対処' => 'action', 'エラーコード' => 'codes',
            '交換部品' => fn ($c) => collect($c->parts ?? [])->map(fn ($p) => $p['n'].(isset($p['id']) ? "（{$p['id']}）" : '').(isset($p['q']) ? " ×{$p['q']}" : ''))->implode(' / '), '費用' => 'cost',
            '停止日数' => 'days', '状況' => 'status', '報告書番号' => 'report_no', '見積書番号' => 'quote_no',
            '報告書PDF' => 'report_url', '見積書PDF' => 'quote_url', '備考' => 'note', '登録者' => 'submitted_by',
        ];

        return response()->streamDownload(function () use ($query, $columns) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_keys($columns), escape: '');
            foreach ($query->lazy(500) as $c) {
                fputcsv($out, array_map(fn ($col) => self::csvCell(is_string($col) ? $c->{$col} : $col($c)), array_values($columns)), escape: '');
            }
            fclose($out);
        }, '対応履歴_'.now()->format('Ymd_His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Excelで数式として解釈される先頭文字を無害化する（CSVインジェクション対策） */
    private static function csvCell(mixed $v): mixed
    {
        return is_string($v) && preg_match('/^[=+@\t\r]/', $v) ? "'".$v : $v;
    }

    private function searchQuery(array $filters): Builder
    {
        return TroubleCase::published()
            ->keyword($filters['q'] ?? null)
            ->when($filters['machine'] ?? null, fn ($q, $v) => $q->where('machine_id', $v))
            ->when($filters['site'] ?? null, fn ($q, $v) => $q->whereHas('machine', fn ($m) => $m->where('site', $v)))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate('date', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('date', '<=', $v))
            ->when(($filters['sort'] ?? '') === 'rating',
                fn ($q) => $q->withSum('ratings as score', 'value')->orderByDesc('score')->orderBy('id'),
                fn ($q) => $q->orderByDesc('date')->orderByDesc('created_at')->orderBy('id'));
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Cases/Form', [
            'case' => null,
            'machines' => CasePresenter::machineOptions(),
            'defaults' => [
                'machine_id' => $request->query('machine'),
                'symptom' => $request->query('symptom'),
                'date' => now('Asia/Tokyo')->format('Y-m-d'),
                'engineer' => $request->user()->name,
            ],
        ]);
    }

    public function store(CaseRequest $request, SlackNotifier $slack): RedirectResponse
    {
        $case = DB::transaction(function () use ($request) {
            $case = TroubleCase::create([
                ...$request->caseAttributes(),
                'submitted_by' => $request->user()->email,
                'review_status' => TroubleCase::REVIEW_PUBLISHED,
                'source' => 'manual',
            ]);
            $this->storePhotos($request, $case);

            return $case;
        });

        $notified = $slack->notifyCase($case);

        return redirect()->route('cases.show', $case)->with('success', '登録しました'.($notified ? '（Slackに通知しました）' : ''));
    }

    public function show(Request $request, TroubleCase $case, SimilarCaseFinder $finder): Response
    {
        $case->load(['machine', 'photos', 'ratings']);

        return Inertia::render('Cases/Show', [
            'case' => CasePresenter::one($case, $request->user()),
            'similar' => CasePresenter::many(
                $finder->find($case->symptom.' '.$case->codes, $case->machine_id, 5, $case->id),
                $request->user(),
            ),
        ]);
    }

    public function edit(TroubleCase $case): Response
    {
        $case->load(['machine', 'photos']);

        return Inertia::render('Cases/Form', [
            'case' => CasePresenter::one($case),
            'machines' => CasePresenter::machineOptions(),
            'defaults' => null,
        ]);
    }

    public function update(CaseRequest $request, TroubleCase $case): RedirectResponse
    {
        DB::transaction(function () use ($request, $case) {
            $case->update($request->caseAttributes());
            $this->storePhotos($request, $case);
            $case->photos()->whereIn('id', $request->input('remove_photo_ids', []))->get()->each(fn ($p) => $this->deletePhoto($p));
        });

        $backToReview = $case->review_status === TroubleCase::REVIEW_PENDING && $request->user()->isAdmin();

        return ($backToReview ? redirect()->route('review.index') : redirect()->route('cases.show', $case))
            ->with('success', '更新しました');
    }

    public function destroy(TroubleCase $case): RedirectResponse
    {
        DB::transaction(function () use ($case) {
            $case->photos->each(fn ($p) => $this->deletePhoto($p));
            $case->ratings()->delete();
            $case->delete();
        });

        return redirect()->route('cases.index')->with('success', '削除しました');
    }

    private function storePhotos(CaseRequest $request, TroubleCase $case): void
    {
        foreach ($request->file('photos', []) as $file) {
            $path = $file->store("photos/{$case->id}", config('navi.photos_disk'));
            $case->photos()->create(['path' => $path, 'original_name' => $file->getClientOriginalName()]);
        }
    }

    private function deletePhoto(CasePhoto $photo): void
    {
        if (! preg_match('#^https?://#', $photo->path)) {
            Storage::disk(config('navi.photos_disk'))->delete($photo->path);
        }
        $photo->delete();
    }
}
