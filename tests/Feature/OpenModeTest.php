<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** NAVI_AUTH_MODE=open: URLを知っている人なら誰でも使える（名前だけ入力） */
class OpenModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['navi.auth_mode' => 'open', 'navi.admin_passcode' => 'kurashiki-2026']);
    }

    public function test_login_page_shows_name_form(): void
    {
        $this->get('/login')->assertInertia(fn ($p) => $p->component('Auth/Login')->where('mode', 'open')->where('adminPasscodeEnabled', true));
        $this->get('/auth/google')->assertNotFound();
    }

    public function test_anyone_can_start_with_a_name_but_is_not_admin(): void
    {
        $this->post('/auth/open', ['name' => '  安藤　学 '])->assertRedirect('/');
        $this->assertAuthenticated();
        $user = User::sole();
        $this->assertSame('安藤 学', $user->name);
        $this->assertFalse($user->isAdmin());
        $this->get('/review')->assertForbidden();

        // 同じ名前なら同じユーザー
        $this->post('/logout');
        $this->post('/auth/open', ['name' => '安藤 学']);
        $this->assertSame(1, User::count());
    }

    public function test_admin_passcode_grants_admin_and_wrong_passcode_is_rejected(): void
    {
        $this->post('/auth/open', ['name' => '安藤', 'passcode' => 'wrong'])->assertSessionHasErrors('passcode');
        $this->assertGuest();

        $this->post('/auth/open', ['name' => '安藤', 'passcode' => 'kurashiki-2026'])->assertRedirect('/');
        $this->assertTrue(User::sole()->isAdmin());
        $this->get('/review')->assertOk();

        // パスコード無しで入り直すと管理者ではなくなる
        $this->post('/logout');
        $this->post('/auth/open', ['name' => '安藤']);
        $this->assertFalse(User::sole()->fresh()->isAdmin());
    }

    public function test_no_one_is_admin_when_passcode_is_not_configured(): void
    {
        config(['navi.admin_passcode' => null]);
        $this->post('/auth/open', ['name' => '安藤', 'passcode' => ''])->assertRedirect('/');
        $this->assertFalse(User::sole()->isAdmin());
    }

    public function test_open_login_is_disabled_in_google_mode(): void
    {
        config(['navi.auth_mode' => 'google']);
        $this->post('/auth/open', ['name' => '安藤'])->assertNotFound();
    }

    public function test_pages_are_not_indexed(): void
    {
        $this->get('/login')->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }
}
