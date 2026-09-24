<?php

namespace App\Http\Controllers;

use App\Http\Presenters\CasePresenter;
use App\Http\Requests\CaseRequest;
use App\Models\CasePhoto;
use App\Models\Machine;
use App\Models\TroubleCase;
use App\Services\SimilarCaseFinder;
use App\Services\SlackNotifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class CaseController extends Controller
{
    /** 症状検索 */
    public function index(Request $request): Response
    {
        $filters = $request->only(['q', 'machine', 'site', 'from', 'to', 'sort']);

        $cases = TroubleCase::published()
            ->with(['machine', 'photos', 'ratings'])
            ->keyword($filters['q'] ?? null)
            ->when($filters['machine'] ?? null, fn ($q, $v) => $q->where('machine_id', $v))
            ->when($filters['site'] ?? null, fn ($q, $v) => $q->whereHas('machine', fn ($m) => $m->where('site', $v)))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate('date', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('date', '<=', $v))
            ->when(($filters['sort'] ?? '') === 'rating',
                fn ($q) => $q->withSum('ratings as score', 'value')->orderByDesc('score'),
                fn ($q) => $q->orderByDesc('date')->orderByDesc('created_at'))
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

        return redirect()->route($case->review_status === TroubleCase::REVIEW_PUBLISHED ? 'cases.show' : 'review.index', $case)
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
