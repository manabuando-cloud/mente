<?php

namespace App\Http\Controllers;

use App\Http\Presenters\CasePresenter;
use App\Models\Consultation;
use App\Models\TroubleCase;
use App\Services\ConsultationService;
use App\Services\Gemini\GeminiException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ConsultationController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Consult/Index', [
            'machines' => CasePresenter::machineOptions(),
            'history' => Consultation::with('machine')->latest()->limit(20)->get()->map(fn ($c) => [
                'id' => $c->id,
                'symptom' => $c->symptom,
                'machine' => $c->machine?->displayName() ?? $c->machine_id,
                'created_at' => $c->created_at?->toIso8601String(),
            ]),
            'current' => $request->query('id') ? $this->present(Consultation::find($request->query('id')), $request) : null,
            'defaults' => ['machine_id' => $request->query('machine'), 'symptom' => $request->query('symptom')],
        ]);
    }

    public function store(Request $request, ConsultationService $service): RedirectResponse
    {
        $data = $request->validate([
            'machine_id' => ['nullable', 'string', 'exists:machines,id'],
            'symptom' => ['required', 'string', 'max:3000'],
        ], [], ['symptom' => '症状', 'machine_id' => '機種']);

        try {
            $consultation = $service->consult($data['symptom'], $data['machine_id'] ?? null, $request->user());
        } catch (GeminiException $e) {
            return back()->withInput()->with('error', 'AI相談に失敗しました: '.$e->getMessage());
        }

        return redirect()->route('consult.index', ['id' => $consultation->id]);
    }

    private function present(?Consultation $c, Request $request): ?array
    {
        if (! $c) {
            return null;
        }
        $similar = TroubleCase::with(['machine', 'photos', 'ratings'])->whereIn('id', $c->similar_case_ids ?? [])->get()
            ->sortBy(fn ($x) => array_search($x->id, $c->similar_case_ids ?? [], true));

        return [
            'id' => $c->id,
            'machine_id' => $c->machine_id,
            'symptom' => $c->symptom,
            'answer' => $c->answer,
            'created_at' => $c->created_at?->toIso8601String(),
            'similar' => CasePresenter::many($similar, $request->user()),
        ];
    }
}
