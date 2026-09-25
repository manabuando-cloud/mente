<?php

namespace Tests\Feature;

use App\Jobs\RunDriveTask;
use App\Models\Machine;
use App\Models\ProcessedReportFile;
use App\Models\TroubleCase;
use App\Services\Drive\DriveClient;
use App\Services\Drive\GoogleDriveClient;
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

    public function test_sync_drive_machines_fills_placeholders_without_overwriting_master(): void
    {
        $this->fakeDrive()
            ->folder('site-honsha', 'f1', 'B0702A0033_TruBend7036(B19)')
            ->folder('site-honsha', 'f2', 'B1508I0077_TruBend5230(B23)')
            ->folder('site-honsha', 'f3', 'L_0987_L3-30')
            ->folder('site-honsha', 'f4', 'NEW0001_TruLaser1030');
        Machine::create(['id' => 'B0702A0033', 'model' => 'B0702A0033', 'source' => 'import']); // 履歴移行時の仮登録
        Machine::create(['id' => 'B1508I0077', 'model' => 'TruBend5230', 'site' => '本社', 'source' => 'master']);
        Machine::create(['id' => 'L_0987', 'model' => 'salvagnini L3-30', 'source' => 'master']);

        $this->artisan('navi:sync-drive-machines')->assertSuccessful();

        $this->assertSame('TruBend7036(B19)', Machine::find('B0702A0033')->model);
        $this->assertSame('本社', Machine::find('B0702A0033')->site);
        $this->assertSame('TruBend5230', Machine::find('B1508I0077')->model); // マスタの型式は上書きしない
        $this->assertSame('f2', Machine::find('B1508I0077')->drive_folder_id);
        $this->assertSame('f3', Machine::find('L_0987')->drive_folder_id);   // "_" を含む機械番号
        $this->assertSame('salvagnini L3-30', Machine::find('L_0987')->model);
        $this->assertSame('TruLaser1030', Machine::find('NEW0001')->model);
    }

    public function test_daily_runs_every_step_and_continues_after_failure(): void
    {
        $this->fakeDrive()->folder('site-honsha', 'mf', 'M1_Model');
        config(['navi.drive.vendor_folder_id' => null]);

        $this->artisan('navi:daily')
            ->expectsOutputToContain('機種マスタの補完')
            ->expectsOutputToContain('業者別フォルダの取込み')
            ->expectsOutputToContain('報告書PDFの自動リンク')
            ->assertSuccessful();
        $this->assertNotNull(Machine::find('M1'));
    }

    public function test_failed_task_is_recorded_so_the_screen_does_not_stay_running(): void
    {
        $this->app->instance(DriveClient::class, new GoogleDriveClient(null, false));
        TroubleCase::factory()->create(['report_url' => null]);

        try {
            (new RunDriveTask('link-reports'))->handle();
            $this->fail('例外が投げ直されていない');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Driveの認証情報がありません', $e->getMessage());
        }

        $state = Cache::get('navi.task.link-reports');
        $this->assertSame('failed', $state['status']);
        $this->assertStringContainsString('Driveの認証情報がありません', $state['output']);
    }
}
