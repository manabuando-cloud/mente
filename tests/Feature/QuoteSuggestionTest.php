<?php

namespace Tests\Feature;

use App\Models\Machine;
use App\Models\TroubleCase;
use App\Services\QuoteSuggester;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class QuoteSuggestionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['navi.drive.site_folders' => ['本社' => 'site'], 'navi.drive.vendor_folder_id' => null]);
        Machine::factory()->create(['id' => 'M1', 'model' => 'TruLaser3030']);
    }

    public function test_suggests_nearest_cases_by_drive_date_and_skips_known_quotes(): void
    {
        $this->fakeDrive()
            ->folder('site', 'mf', 'M1_TruLaser3030')
            ->folder('mf', 'qf', '見積')
            ->file('qf', 'q-new', 'est23102936_事後見積り.pdf', createdTime: '2023-10-10T02:00:00Z')
            ->file('qf', 'q-known', 'est11111111.pdf', createdTime: '2023-10-10T02:00:00Z')
            ->file('qf', 'q-far', 'est22222222.pdf', createdTime: '2019-01-01T00:00:00Z');
        $near = TroubleCase::factory()->create(['machine_id' => 'M1', 'date' => '2023-10-05', 'quote_no' => null, 'quote_url' => null]);
        $farther = TroubleCase::factory()->create(['machine_id' => 'M1', 'date' => '2023-12-20', 'quote_no' => null, 'quote_url' => null]);
        TroubleCase::factory()->create(['machine_id' => 'M1', 'date' => '2023-10-09', 'quote_no' => 'EST-11111111']); // 紐づけ済み

        $this->artisan('navi:suggest-quotes')->assertSuccessful();

        $r = Cache::get(QuoteSuggester::CACHE_RESULT);
        $this->assertCount(1, $r['suggestions']);
        $this->assertSame(1, $r['unmatched']); // q-far は180日以上離れていて候補なし
        $s = $r['suggestions'][0];
        $this->assertSame('est23102936', $s['quote_no']);
        $this->assertSame('drive', $s['date_source']);
        $this->assertSame([$near->id, $farther->id], array_column($s['candidates'], 'case_id'));
        Http::assertNothingSent();
    }

    public function test_ai_reads_issue_date_and_amount_to_rank(): void
    {
        $this->fakeDrive()
            ->folder('site', 'mf', 'M1_TruLaser3030')
            ->folder('mf', 'qf', '見積')
            ->file('qf', 'q1', 'est33333333.pdf', createdTime: '2024-06-01T00:00:00Z');
        $sameDay = TroubleCase::factory()->create(['machine_id' => 'M1', 'date' => '2024-03-01', 'cost' => 50000, 'parts' => 'ノズル']);
        $costMatch = TroubleCase::factory()->create(['machine_id' => 'M1', 'date' => '2024-03-10', 'cost' => 110000, 'parts' => '保護ガラス']);
        Http::fake(['generativelanguage.googleapis.com/*' => self::geminiResponse([
            'issue_date' => '2024-03-01', 'total_amount' => 100000, 'items' => ['保護ガラス'], 'subject' => '保護ガラス交換',
        ])]);

        $this->artisan('navi:suggest-quotes', ['--ai' => true])->assertSuccessful();
        // 2回目はAIの読み取り結果をキャッシュから使う
        $this->artisan('navi:suggest-quotes', ['--ai' => true])->assertSuccessful();
        Http::assertSentCount(1);

        $s = Cache::get(QuoteSuggester::CACHE_RESULT)['suggestions'][0];
        $this->assertSame('pdf', $s['date_source']);
        $this->assertSame('2024-03-01', $s['date']);
        // 9日ずれていても、税込金額一致＋部品一致の方が上位
        $this->assertSame($costMatch->id, $s['candidates'][0]['case_id']);
        $this->assertTrue($s['candidates'][0]['cost_match']);
        $this->assertSame($sameDay->id, $s['candidates'][1]['case_id']);
    }

    public function test_assign_and_dismiss_from_screen(): void
    {
        $this->fakeDrive()
            ->folder('site', 'mf', 'M1_TruLaser3030')
            ->folder('mf', 'qf', '見積')
            ->file('qf', 'q1', 'est44444444.pdf', createdTime: '2024-01-02T00:00:00Z')
            ->file('qf', 'q2', 'est55555555.pdf', createdTime: '2024-01-03T00:00:00Z');
        $case = TroubleCase::factory()->create(['machine_id' => 'M1', 'date' => '2024-01-01', 'quote_no' => null, 'quote_url' => null]);
        $this->artisan('navi:suggest-quotes');
        $admin = $this->user(admin: true);

        $this->actingAs($admin)->post('/drive/quote-assign', ['file_id' => 'q1', 'case_id' => $case->id])->assertSessionHas('success');
        $case->refresh();
        $this->assertSame('est44444444', $case->quote_no);
        $this->assertSame('https://drive.google.com/file/d/q1/view', $case->quote_url);
        // 確定した履歴は他の見積の候補からも消える（q2 は候補が無くなり一覧から消える）
        $this->assertCount(0, Cache::get(QuoteSuggester::CACHE_RESULT)['suggestions']);

        $this->actingAs($admin)->post('/drive/quote-assign', ['file_id' => 'q2', 'dismiss' => true])->assertSessionHas('success');
        $this->assertSame(['q2'], Cache::get(QuoteSuggester::CACHE_DISMISSED));

        $this->actingAs($this->user())->post('/drive/quote-assign', ['file_id' => 'q1', 'dismiss' => true])->assertForbidden();
    }

    public function test_amount_matching_handles_tax(): void
    {
        $this->assertTrue(QuoteSuggester::amountMatches(100000, 110000));
        $this->assertTrue(QuoteSuggester::amountMatches(110000, 100000));
        $this->assertTrue(QuoteSuggester::amountMatches(100000, 108000));
        $this->assertFalse(QuoteSuggester::amountMatches(100000, 120000));
        $this->assertSame('est23102936', QuoteSuggester::quoteNoFromFileName('EST_23102936_事後見積り.pdf'));
    }
}
