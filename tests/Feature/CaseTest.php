<?php

namespace Tests\Feature;

use App\Models\CaseRating;
use App\Models\Machine;
use App\Models\TroubleCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_case_with_photo_and_notify_slack(): void
    {
        Storage::fake('public');
        Http::fake(['hooks.slack.test/*' => Http::response('ok')]);
        $machine = Machine::factory()->create(['id' => 'B1508I0077', 'model' => 'TruBend5230(B23)']);
        $user = $this->user();

        $response = $this->actingAs($user)->post('/cases', [
            'machine_id' => $machine->id,
            'date' => '2026-09-01',
            'symptom' => 'バックゲージが原点復帰しない',
            'codes' => 'E1234、E5678',
            'cost' => '１２，０００', // 全角入力
            'days' => '2',
            'report_url' => 'https://drive.google.com/file/d/abc/view',
            'photos' => [UploadedFile::fake()->image('a.jpg')],
        ]);

        $case = TroubleCase::sole();
        $response->assertRedirect("/cases/{$case->id}")->assertSessionHas('success');
        $this->assertSame(12000, $case->cost);
        $this->assertSame($user->email, $case->submitted_by);
        $this->assertSame(TroubleCase::REVIEW_PUBLISHED, $case->review_status);
        $this->assertNotNull($case->slack_notified_at);
        $this->assertCount(1, $case->photos);
        Storage::disk('public')->assertExists($case->photos->first()->path);

        Http::assertSent(fn ($req) => str_contains($req['text'], 'TruBend5230(B23) B1508I0077') && str_contains($req['text'], 'バックゲージ'));
    }

    public function test_registration_survives_slack_failure(): void
    {
        Http::fake(['hooks.slack.test/*' => Http::response('no_service', 404)]);
        $machine = Machine::factory()->create();

        $this->actingAs($this->user())->post('/cases', ['machine_id' => $machine->id, 'symptom' => '異音'])->assertRedirect();
        $this->assertNull(TroubleCase::sole()->slack_notified_at);
    }

    public function test_validation(): void
    {
        $this->actingAs($this->user())->post('/cases', ['machine_id' => 'NOPE', 'symptom' => '', 'report_url' => 'not a url'])
            ->assertSessionHasErrors(['machine_id', 'symptom', 'report_url']);
    }

    public function test_search_matches_keywords_and_hides_pending(): void
    {
        $m = Machine::factory()->create();
        TroubleCase::factory()->create(['machine_id' => $m->id, 'symptom' => 'レーザー出力が低下する', 'codes' => 'E2105']);
        TroubleCase::factory()->create(['machine_id' => $m->id, 'symptom' => '油圧ユニットから異音', 'codes' => 'H10']);
        TroubleCase::factory()->pending()->create(['machine_id' => $m->id, 'symptom' => 'レーザー発振器 E2105 停止']);

        $this->actingAs($this->user())->get('/cases?q=E2105')
            ->assertInertia(fn ($p) => $p->component('Cases/Index')->where('cases.total', 1)->where('cases.data.0.symptom', 'レーザー出力が低下する'));

        $this->actingAs($this->user())->get('/cases?q=レーザー 異音')->assertInertia(fn ($p) => $p->where('cases.total', 0));
    }

    public function test_update_and_remove_photo(): void
    {
        Storage::fake('public');
        $case = TroubleCase::factory()->create();
        $photo = $case->photos()->create(['path' => UploadedFile::fake()->image('x.jpg')->store('photos', 'public')]);

        $this->actingAs($this->user())->put("/cases/{$case->id}", [
            'machine_id' => $case->machine_id,
            'symptom' => '更新後の症状',
            'quote_url' => 'https://drive.google.com/file/d/q/view',
            'remove_photo_ids' => [$photo->id],
        ])->assertRedirect("/cases/{$case->id}");

        $case->refresh();
        $this->assertSame('更新後の症状', $case->symptom);
        $this->assertSame('https://drive.google.com/file/d/q/view', $case->quote_url);
        $this->assertCount(0, $case->photos);
        Storage::disk('public')->assertMissing($photo->path);
    }

    public function test_show_page_includes_similar_cases(): void
    {
        $m = Machine::factory()->create();
        $case = TroubleCase::factory()->create(['machine_id' => $m->id, 'symptom' => 'バックゲージが原点復帰しない', 'codes' => 'E1']);
        TroubleCase::factory()->create(['machine_id' => $m->id, 'symptom' => 'バックゲージ原点復帰エラー', 'codes' => 'E9']);
        TroubleCase::factory()->create(['machine_id' => $m->id, 'symptom' => '油圧ユニット', 'cause' => null, 'codes' => null, 'parts' => null]); // 無関係

        $this->actingAs($this->user())->get("/cases/{$case->id}")
            ->assertInertia(fn ($p) => $p->component('Cases/Show')->has('similar', 1));
    }

    public function test_rating_toggles(): void
    {
        $case = TroubleCase::factory()->create();
        $user = $this->user();

        $this->actingAs($user)->post("/cases/{$case->id}/rating", ['value' => 1]);
        $this->assertSame(1, CaseRating::sole()->value);
        $this->actingAs($user)->post("/cases/{$case->id}/rating", ['value' => -1]);
        $this->assertSame(-1, CaseRating::sole()->value);
        $this->actingAs($user)->post("/cases/{$case->id}/rating", ['value' => 0]);
        $this->assertSame(0, CaseRating::count());
    }

    public function test_only_admin_can_delete(): void
    {
        $case = TroubleCase::factory()->create();

        $this->actingAs($this->user())->delete("/cases/{$case->id}")->assertForbidden();
        $this->actingAs($this->user(admin: true))->delete("/cases/{$case->id}")->assertRedirect('/cases');
        $this->assertModelMissing($case);
    }
}
