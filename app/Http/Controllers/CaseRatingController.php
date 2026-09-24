<?php

namespace App\Http\Controllers;

use App\Models\CaseRating;
use App\Models\TroubleCase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CaseRatingController extends Controller
{
    /** 👍(1) / 👎(-1) / 取り消し(0) */
    public function __invoke(Request $request, TroubleCase $case): RedirectResponse
    {
        $value = (int) $request->validate(['value' => ['required', 'integer', 'in:-1,0,1']])['value'];
        $key = ['trouble_case_id' => $case->id, 'user_id' => $request->user()->id];

        $value === 0
            ? CaseRating::where($key)->delete()
            : CaseRating::updateOrCreate($key, ['value' => $value]);

        return back();
    }
}
