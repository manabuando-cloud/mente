<?php

namespace App\Http\Controllers;

use App\Http\Presenters\CasePresenter;
use App\Models\TroubleCase;
use App\Services\SlackNotifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * AI自動取込みの確認待ちレビュー（旧: PendingCases シートを手で approved にして promoteApprovedCases）。
 * 画面上で内容を直して「承認」すると即座に正式な対応履歴になる。
 */
class ReviewController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->query('status', TroubleCase::REVIEW_PENDING);

        return Inertia::render('Review/Index', [
            'status' => $status,
            'cases' => CasePresenter::many(
                TroubleCase::with(['machine', 'photos'])->where('review_status', $status)->orderByDesc('date')->limit(100)->get()
            ),
            'counts' => TroubleCase::whereIn('review_status', [TroubleCase::REVIEW_PENDING, TroubleCase::REVIEW_REJECTED])
                ->selectRaw('review_status, count(*) as n')->groupBy('review_status')->pluck('n', 'review_status'),
        ]);
    }

    public function decide(Request $request, TroubleCase $case, SlackNotifier $slack): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject,reopen'],
            'review_note' => ['nullable', 'string', 'max:2000'],
        ]);
        abort_if($case->review_status === TroubleCase::REVIEW_PUBLISHED, 422, 'すでに公開済みです');

        $case->update([
            'review_status' => match ($data['decision']) {
                'approve' => TroubleCase::REVIEW_PUBLISHED,
                'reject' => TroubleCase::REVIEW_REJECTED,
                'reopen' => TroubleCase::REVIEW_PENDING,
            },
            'review_note' => $data['review_note'] ?? $case->review_note,
        ]);

        if ($data['decision'] === 'approve') {
            $slack->notifyCase($case);
        }

        return back()->with('success', match ($data['decision']) {
            'approve' => '承認して対応履歴に反映しました',
            'reject' => '却下しました',
            'reopen' => '確認待ちに戻しました',
        });
    }
}
