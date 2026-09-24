<?php

namespace App\Http\Controllers;

use App\Http\Presenters\CasePresenter;
use App\Models\Machine;
use App\Services\DashboardStats;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardStats $stats): Response
    {
        $filters = $request->only(['machine', 'site', 'category']);

        return Inertia::render('Dashboard', [
            'filters' => $filters,
            'stats' => $stats->build($filters),
            'recent' => CasePresenter::many(
                $stats->query($filters)->with(['machine', 'photos', 'ratings'])->orderByDesc('date')->orderByDesc('created_at')->limit(10)->get(),
                $request->user(),
            ),
            'machines' => CasePresenter::machineOptions(),
            'sites' => Machine::whereNotNull('site')->distinct()->orderBy('site')->pluck('site'),
            'categories' => Machine::whereNotNull('category')->distinct()->orderBy('category')->pluck('category'),
        ]);
    }
}
