<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertInertia(fn ($p) => $p->component('Auth/Login'));
    }

    public function test_login_page_tells_when_google_is_not_configured(): void
    {
        config(['services.google.client_id' => null]);
        $this->get('/login')->assertInertia(fn ($p) => $p->where('googleConfigured', false));
        $this->get('/auth/google')->assertRedirect('/login')->assertSessionHas('error');

        config(['services.google.client_id' => 'id', 'services.google.client_secret' => 'secret']);
        $this->get('/login')->assertInertia(fn ($p) => $p->where('googleConfigured', true));
    }

    public function test_google_redirect_uses_the_host_the_user_came_from(): void
    {
        // 戻り先URLは相対パスで設定し、アクセスしたURL（localhost / 公開URL）に合わせる
        config(['services.google.client_id' => 'id', 'services.google.client_secret' => 'secret', 'services.google.redirect' => '/auth/google/callback']);
        $location = $this->get('https://xxxx.ngrok-free.app/auth/google')->headers->get('Location');
        $this->assertStringContainsString(urlencode('https://xxxx.ngrok-free.app/auth/google/callback'), $location);
        $this->assertStringContainsString('hd=g.kurashiki-laser.co.jp', $location);
    }

    public function test_google_callback_logs_in_workspace_user(): void
    {
        $this->mockGoogleUser('taro@g.kurashiki-laser.co.jp');

        $this->get('/auth/google/callback')->assertRedirect('/');
        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['email' => 'taro@g.kurashiki-laser.co.jp', 'google_id' => 'g-1']);
    }

    public function test_google_callback_rejects_other_domains(): void
    {
        $this->mockGoogleUser('someone@gmail.com');

        $this->get('/auth/google/callback')->assertRedirect('/login')->assertSessionHas('error');
        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    public function test_dev_login_is_disabled_unless_configured(): void
    {
        $this->post('/auth/dev', ['email' => 'x@example.com'])->assertNotFound();

        config(['navi.dev_login' => true]);
        $this->post('/auth/dev', ['email' => 'x@example.com'])->assertRedirect('/');
        $this->assertAuthenticated();
    }

    public function test_admin_pages_require_admin(): void
    {
        $this->actingAs($this->user())->get('/review')->assertForbidden();
        $this->actingAs($this->user(admin: true))->get('/review')->assertOk();

        config(['navi.admin_emails' => ['boss@g.kurashiki-laser.co.jp']]);
        $boss = User::factory()->create(['email' => 'boss@g.kurashiki-laser.co.jp']);
        $this->actingAs($boss)->get('/drive')->assertOk();
    }

    private function mockGoogleUser(string $email): void
    {
        $user = (new SocialiteUser)->map(['id' => 'g-1', 'name' => '倉敷 太郎', 'email' => $email, 'avatar' => null]);
        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->andReturn($user);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }
}
