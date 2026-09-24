<?php

namespace Tests;

use App\Models\User;
use App\Services\Drive\DriveClient;
use App\Services\Drive\FakeDriveClient;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
    }

    protected function user(bool $admin = false): User
    {
        return User::factory()->create(['email' => fake()->unique()->userName().'@g.kurashiki-laser.co.jp', 'is_admin' => $admin]);
    }

    protected function fakeDrive(): FakeDriveClient
    {
        $fake = new FakeDriveClient;
        $this->app->instance(DriveClient::class, $fake);

        return $fake;
    }

    /** Gemini generateContent の応答を作る */
    protected static function geminiResponse(string|array $content, int $status = 200): PromiseInterface
    {
        $text = is_array($content) ? json_encode($content, JSON_UNESCAPED_UNICODE) : $content;

        return Http::response(['candidates' => [['content' => ['parts' => [['text' => $text]]]]]], $status);
    }
}
