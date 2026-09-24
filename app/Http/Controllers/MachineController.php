<?php

namespace App\Http\Controllers;

use App\Http\Presenters\CasePresenter;
use App\Models\Machine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** 機械マスター登録 */
class MachineController extends Controller
{
    public function index(Request $request): Response
    {
        $q = trim((string) $request->query('q'));

        return Inertia::render('Machines/Index', [
            'filters' => ['q' => $q],
            'machines' => Machine::withCount(['cases' => fn ($c) => $c->published()])
                ->when($q, fn ($query) => $query->where(fn ($w) => $w->where('id', 'like', "%{$q}%")->orWhere('model', 'like', "%{$q}%")->orWhere('maker', 'like', "%{$q}%")))
                ->orderBy('site')->orderBy('model')->get()
                ->map(fn ($m) => [...CasePresenter::machine($m), 'cases_count' => $m->cases_count, 'source' => $m->source]),
            'sites' => array_keys(config('navi.drive.site_folders')),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, null);
        Machine::create([...$data, 'source' => 'user', 'submitted_by' => $request->user()->email]);

        return back()->with('success', "機種「{$data['model']} {$data['id']}」を登録しました");
    }

    public function update(Request $request, Machine $machine): RedirectResponse
    {
        $machine->update($this->validated($request, $machine));

        return back()->with('success', '機種情報を更新しました');
    }

    private function validated(Request $request, ?Machine $machine): array
    {
        $data = $request->validate([
            'id' => [$machine ? 'prohibited' : 'required', 'string', 'max:50', 'regex:/^[A-Za-z0-9\-]+$/', Rule::unique('machines', 'id')],
            'model' => ['required', 'string', 'max:100'],
            'maker' => ['nullable', 'string', 'max:100'],
            'label' => ['nullable', 'string', 'max:100'],
            'site' => ['nullable', 'string', 'max:50'],
            'category' => ['nullable', 'string', 'max:50'],
            'manuals' => ['nullable', 'array'],
            'manuals.*.title' => ['nullable', 'string', 'max:200'],
            'manuals.*.url' => ['required', 'url', 'max:2000'],
        ], [], ['id' => '機械番号', 'model' => '型式', 'manuals.*.url' => '取説URL']);

        $data['manuals'] = array_values(array_map(
            fn ($m) => ['title' => $m['title'] ?: '取扱説明書', 'url' => $m['url']],
            $data['manuals'] ?? []
        ));

        return $data;
    }
}
