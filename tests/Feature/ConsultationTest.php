<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Models\Machine;
use App\Models\TroubleCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ConsultationTest extends TestCase
{
    use RefreshDatabase;

    public function test_consult_uses_similar_cases_and_manuals(): void
    {
        $m = Machine::factory()->create(['manuals' => [['title' => '保守マニュアル', 'url' => 'https://example.com/manual.pdf']]]);
        $similar = TroubleCase::factory()->create(['machine_id' => $m->id, 'symptom' => 'レーザー出力が低下する', 'codes' => 'E2105']);
        TroubleCase::factory()->create(['machine_id' => $m->id, 'symptom' => '油圧ユニットから異音', 'codes' => 'H1', 'cause' => 'ポンプ', 'parts' => 'ポンプ']);
        Http::fake(['generativelanguage.googleapis.com/*' => self::geminiResponse("## 考えられる原因\n[{$similar->id}] と同様…")]);

        $response = $this->actingAs($this->user())->post('/consult', ['machine_id' => $m->id, 'symptom' => 'E2105 レーザー出力低下で停止']);

        $c = Consultation::sole();
        $response->assertRedirect("/consult?id={$c->id}");
        $this->assertSame([$similar->id], $c->similar_case_ids);
        $this->assertStringContainsString('考えられる原因', $c->answer);

        Http::assertSent(function ($req) use ($similar) {
            $prompt = $req['contents'][0]['parts'][0]['text'];

            return $req->hasHeader('x-goog-api-key', 'test-key')
                && str_contains($prompt, "[{$similar->id}]")
                && str_contains($prompt, 'manual.pdf')
                && ! str_contains($prompt, '油圧ユニット');
        });

        $this->actingAs($this->user())->get("/consult?id={$c->id}")
            ->assertInertia(fn ($p) => $p->component('Consult/Index')->where('current.id', $c->id)->has('current.similar', 1));
    }

    public function test_consult_error_is_shown_not_thrown(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => 'quota'], 429)]);

        $this->actingAs($this->user())->from('/consult')->post('/consult', ['symptom' => '異音'])
            ->assertRedirect('/consult')->assertSessionHas('error');
        $this->assertSame(0, Consultation::count());
    }
}
