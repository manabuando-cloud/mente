<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

/** Google Workspace アカウントでのログイン（旧GAS: 「組織内の全員」相当） */
class GoogleLoginController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('Auth/Login', [
            'mode' => config('navi.auth_mode') === 'open' ? 'open' : 'google',
            'devLogin' => $this->devLoginEnabled(),
            'domain' => config('navi.allowed_domain'),
            'adminPasscodeEnabled' => filled(config('navi.admin_passcode')),
            'googleConfigured' => filled(config('services.google.client_id')) && filled(config('services.google.client_secret')),
        ]);
    }

    public function redirect(): RedirectResponse
    {
        abort_if(config('navi.auth_mode') === 'open', 404);
        if (blank(config('services.google.client_id')) || blank(config('services.google.client_secret'))) {
            return redirect()->route('login')->with('error', 'Googleログインの設定（GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET）がまだです。');
        }

        $driver = Socialite::driver('google');
        if ($domain = config('navi.allowed_domain')) {
            $driver->with(['hd' => $domain]);
        }

        return $driver->redirect();
    }

    public function callback(): RedirectResponse
    {
        abort_if(config('navi.auth_mode') === 'open', 404);

        try {
            $g = Socialite::driver('google')->user();
        } catch (Throwable $e) {
            report($e);

            return redirect()->route('login')->with('error', 'Googleログインに失敗しました。もう一度お試しください。');
        }

        $email = strtolower((string) $g->getEmail());
        $domain = config('navi.allowed_domain');
        // hd パラメータはUI上のヒントに過ぎないため、サーバー側で必ず検証する
        if ($domain && ! str_ends_with($email, '@'.strtolower($domain))) {
            return redirect()->route('login')->with('error', "{$domain} のアカウントでログインしてください。");
        }

        $user = User::updateOrCreate(['email' => $email], [
            'name' => $g->getName() ?: strstr($email, '@', true),
            'google_id' => $g->getId(),
            'avatar' => $g->getAvatar(),
            'email_verified_at' => now(),
        ]);

        Auth::login($user, remember: true);

        return redirect()->intended(route('dashboard'));
    }

    /** ローカル開発用: Google OAuth を設定せずにログインする */
    public function devLogin(Request $request): RedirectResponse
    {
        abort_unless($this->devLoginEnabled(), 404);

        $email = strtolower($request->string('email')->trim()->value() ?: 'dev@example.com');
        $user = User::firstOrCreate(['email' => $email], ['name' => strstr($email, '@', true), 'is_admin' => true]);
        Auth::login($user, remember: true);

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    private function devLoginEnabled(): bool
    {
        return config('navi.dev_login') && app()->environment('local', 'testing');
    }
}
