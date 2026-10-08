<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * 「URLを知っている人なら誰でも使える」モード（NAVI_AUTH_MODE=open）のログイン。
 * パスワードは無く、登録者・評価を記録するために名前だけ入力してもらう。
 * 管理機能は管理者パスコード（NAVI_ADMIN_PASSCODE）を入力した人だけが使える。
 */
class OpenLoginController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        abort_unless(config('navi.auth_mode') === 'open', 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:50'],
            'passcode' => ['nullable', 'string', 'max:200'],
        ], [], ['name' => 'お名前', 'passcode' => '管理者パスコード']);

        $name = trim(preg_replace('/\s+/u', ' ', $data['name']));
        $expected = (string) config('navi.admin_passcode');
        $wantsAdmin = filled($data['passcode'] ?? null);
        $isAdmin = $wantsAdmin && $expected !== '' && hash_equals($expected, (string) $data['passcode']);

        if ($wantsAdmin && ! $isAdmin) {
            throw ValidationException::withMessages(['passcode' => '管理者パスコードが違います']);
        }

        // 同じ名前の人は同じユーザーとして扱う（メールアドレスは名前から作る内部用の値）
        $user = User::firstOrCreate(
            ['email' => 'open-'.substr(hash('sha256', mb_strtolower($name)), 0, 16).'@open.invalid'],
            ['name' => $name],
        );
        $user->forceFill(['name' => $name, 'is_admin' => $isAdmin])->save();

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }
}
