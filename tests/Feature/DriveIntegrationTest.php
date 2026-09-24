<?php

namespace Tests\Feature;

use App\Models\Machine;
use App\Models\ProcessedReportFile;
use App\Models\TroubleCase;
use App\Services\ReportLinker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DriveIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['navi.drive.site_folders' => ['本社' => 'site-honsha'], 'navi.drive.vendor_folder_id' => 'vendor']);
    }

    public function test_ingest_creates_pending_cases_and_skips_processed_files(): void
    {
        $drive = $this->fakeDrive()
            ->folder('site-honsha', 'mf-1', 'B1508I0077_TruBend5230(B23)')
            ->file('mf-1', 'pdf-1', '20240315_1030_ActivityReport.pdf')
            ->file('mf-1', 'other', 'memo.txt', 'x', 'text/plain');
        Http::fake(['generativelanguage.googleapis.com/*' => self::geminiResponse([
            'symptom' => 'バックゲージ異常', 'cause' => 'エンコーダ断線', 'action' => 'ケーブル交換',
            'codes' => ['E1', 'E2'], 'parts' => ['エンコーダケーブル'], 'engineer' => '山田',
        ])]);

        $this->artisan('navi:ingest-reports')->assertSuccessful();

        $case = TroubleCase::sole();
        $this->assertSame(TroubleCase::REVIEW_PENDING, $case->review_status);
        $this->assertSame('B1508I0077', $case->machine_id);
        $this->assertSame('2024-03-15', $case->date->format('Y-m-d'));
        $this->assertSame('E1, E2', $case->codes);
        $this->assertSame('https://drive.google.com/file/d/pdf-1/view', $case->report_url);
        $this->assertSame('TruBend5230(B23)', Machine::find('B1508I0077')->model); // 未登録の機種は自動登録
        Http::assertSent(fn ($req) => $req['contents'][0]['parts'][0]['inline_data']['mime_type'] === 'application/pdf');

        // 2回目は処理済みなので何もしない
        $this->artisan('navi:ingest-reports')->assertSuccessful();
        $this->assertSame(1, TroubleCase::count());
        Http::assertSentCount(1);
    }

    public function test_ingest_stops_on_quota_and_retries_next_time(): void
    {
        $this->fakeDrive()
            ->folder('site-honsha', 'mf-1', 'M1_Model')
            ->file('mf-1', 'pdf-1', '20240101_ActivityReport.pdf')
            ->file('mf-1', 'pdf-2', '20240102_ActivityReport.pdf');
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([], 429)]);

        $this->artisan('navi:ingest-reports')->expectsOutputToContain('クォータ超過')->assertSuccessful();

        Http::assertSentCount(1); // 1件目で中断
        $this->assertSame(0, ProcessedReportFile::count()); // 処理済みにしない → 次回リトライ
    }

    public function test_approving_pending_case_publishes_and_notifies(): void
    {
        Http::fake(['hooks.slack.test/*' => Http::response('ok')]);
        $case = TroubleCase::factory()->pending()->create();

        $this->actingAs($this->user(admin: true))->post("/review/{$case->id}", ['decision' => 'approve'])->assertRedirect();

        $this->assertSame(TroubleCase::REVIEW_PUBLISHED, $case->fresh()->review_status);
        Http::assertSentCount(1);
    }

    public function test_link_reports_by_date_and_reports_ambiguous(): void
    {
        $this->fakeDrive()
            ->folder('site-honsha', 'mf-1', 'M1_Model')
            ->file('mf-1', 'r1', '20240315_1030_ActivityReport.pdf')
            ->file('mf-1', 'r2', '20240401_0900_ActivityReport.pdf')
            ->file('mf-1', 'r3', '20240401_1500_ActivityReport.pdf');
        $m = Machine::factory()->create(['id' => 'M1']);
        $unique = TroubleCase::factory()->create(['machine_id' => $m->id, 'date' => '2024-03-15', 'report_url' => null]);
        $ambiguous = TroubleCase::factory()->create(['machine_id' => $m->id, 'date' => '2024-04-01', 'report_url' => null]);
        $noFile = TroubleCase::factory()->create(['machine_id' => $m->id, 'date' => '2024-05-01', 'report_url' => null]);

        $this->artisan('navi:link-reports')->assertSuccessful();

        $this->assertSame('https://drive.google.com/file/d/r1/view', $unique->fresh()->report_url);
        $this->assertNull($ambiguous->fresh()->report_url);
        $this->assertNull($noFile->fresh()->report_url);
        $this->assertSame('mf-1', $m->fresh()->drive_folder_id);

        $last = Cache::get(ReportLinker::CACHE_REPORTS);
        $this->assertSame(1, $last['linked']);
        $this->assertCount(2, $last['ambiguous'][0]['candidates']);

        // 画面から候補を選んで確定
        $this->actingAs($this->user(admin: true))->post('/drive/resolve', [
            'kind' => 'reports', 'case_id' => $ambiguous->id, 'url' => 'https://drive.google.com/file/d/r3/view',
        ])->assertRedirect();
        $this->assertSame('https://drive.google.com/file/d/r3/view', $ambiguous->fresh()->report_url);
        $this->assertCount(0, Cache::get(ReportLinker::CACHE_REPORTS)['ambiguous']);
    }

    public function test_link_quotes_from_machine_quote_folder_then_vendor_folder(): void
    {
        $this->fakeDrive()
            ->folder('site-honsha', 'mf-1', 'M1_Model')
            ->folder('mf-1', 'qf-1', '見積')
            ->file('qf-1', 'q1', 'est23102936_事後見積り.pdf')
            ->folder('vendor', 'v-amada', 'アマダ')
            ->file('v-amada', 'q2', '見積書_est23285574.pdf');
        $m = Machine::factory()->create(['id' => 'M1']);
        $a = TroubleCase::factory()->create(['machine_id' => $m->id, 'quote_no' => 'EST-23102936']);
        $b = TroubleCase::factory()->create(['machine_id' => $m->id, 'quote_no' => 'est_23285574']);
        $c = TroubleCase::factory()->create(['machine_id' => $m->id, 'quote_no' => '99999999']);

        $this->artisan('navi:link-quotes')->assertSuccessful();

        $this->assertSame('https://drive.google.com/file/d/q1/view', $a->fresh()->quote_url);
        $this->assertSame('https://drive.google.com/file/d/q2/view', $b->fresh()->quote_url);
        $this->assertNull($c->fresh()->quote_url);
    }
}
