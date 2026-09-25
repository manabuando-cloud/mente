<?php

namespace Tests\Feature;

use App\Models\Machine;
use App\Models\ProcessedReportFile;
use App\Models\TroubleCase;
use App\Models\VendorFolder;
use App\Services\VendorReportIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** ファイル名・フォルダ構成は実際の「メーカー作業報告書見積り」フォルダに合わせている */
class VendorIngestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['navi.drive.site_folders' => [], 'navi.drive.vendor_folder_id' => 'vendor']);
        Machine::create(['id' => 'B0702A0033', 'model' => 'TruBend7036(B19)', 'maker' => 'TRUMPF']);
        Machine::create(['id' => 'A0121C0046', 'model' => 'TruMatic6000fiber(K06)', 'maker' => 'TRUMPF']);
        Machine::create(['id' => 'L_0987', 'model' => 'salvagnini L3-30', 'maker' => 'サルバニーニ']);
        Http::fake(['generativelanguage.googleapis.com/*' => self::geminiResponse([
            'symptom' => 'X20モジュール異常', 'action' => 'モジュール交換', 'engineer' => '宮川',
            'parts' => [['n' => 'X20モジュール', 'id' => '1234567', 'q' => 1]], 'status' => 'repaired',
        ])]);
    }

    public function test_trumpf_reports_are_matched_by_serial_in_file_name_and_quotes_are_paired(): void
    {
        $this->fakeDrive()
            ->folder('vendor', 'v-trumpf', '作業報告書-見積書(トルンプ)')
            ->folder('v-trumpf', 'y2026', '2026年トルンプ作業報告書')
            ->file('y2026', 'r1', '20260824-#B0702A0033_TruBend_7036_(B19)_x20ﾓｼﾞｭｰﾙ交換_作業報告書.pdf')
            ->file('y2026', 'q1', '20260824-#B0702A0033_TruBend_7036_(B19)_x20ﾓｼﾞｭｰﾙ交換_事後見積り.pdf')
            ->file('y2026', 'c1', '20260817-#A0121C0046_TruMatic_6000_fiber_(K06)_年次点検_点検チェックリスト.pdf')
            ->file('y2026', 'u1', '20260805-#Z9999Z9999_Unknown_作業報告書.pdf');

        $this->artisan('navi:ingest-vendor-reports')->assertSuccessful();

        $case = TroubleCase::sole();
        $this->assertSame('B0702A0033', $case->machine_id);
        $this->assertSame('2026-08-24', $case->date->format('Y-m-d'));
        $this->assertSame(TroubleCase::REVIEW_PENDING, $case->review_status);
        $this->assertSame('https://drive.google.com/file/d/r1/view', $case->report_url);
        $this->assertSame('https://drive.google.com/file/d/q1/view', $case->quote_url); // 同じ日の見積を紐づけ
        $this->assertSame([['n' => 'X20モジュール', 'id' => '1234567', 'q' => 1]], $case->parts);
        Http::assertSentCount(1); // 見積・チェックリストはAIで読まない

        $last = cache(VendorReportIngestor::CACHE_RESULT);
        $this->assertSame(1, $last['quotes_linked']);
        $this->assertSame(1, $last['skipped']);
        $this->assertSame(['20260805-#Z9999Z9999_Unknown_作業報告書.pdf'], array_column($last['unresolved'], 'name'));

        // 2回目は何もしない
        $this->artisan('navi:ingest-vendor-reports')->assertSuccessful();
        $this->assertSame(1, TroubleCase::count());
        Http::assertSentCount(1);
    }

    public function test_single_machine_folder_uses_fixed_mapping_and_skip_mode(): void
    {
        $this->fakeDrive()
            ->folder('vendor', 'v-salva', '作業報告書-見積書(salvagnini_L3-30)')
            ->file('v-salva', 's1', '20241019_L3_report(Z軸リニアガイド一式交換).pdf')
            ->file('v-salva', 's2', '20260728_L3_倉敷レーサ゛ー㈱様向けIPG製発振器モシ゛ュール交換_納品書.pdf')
            ->folder('vendor', 'v-cal', '作業報告書(社内点検)')
            ->file('v-cal', 'cal1', '20251221_B0702A0033_メモ.pdf');

        $this->artisan('navi:ingest-vendor-reports')->assertSuccessful();

        // 既定の対応表で salvagnini フォルダは L_0987 に固定される
        $this->assertSame(VendorFolder::MODE_FIXED, VendorFolder::find('v-salva')->mode);
        $this->assertSame('L_0987', TroubleCase::where('source_file_id', 's1')->sole()->machine_id);
        // 社内点検フォルダは「ファイル名から判定」なので機械番号があれば取り込まれる → 対象外にできる
        $this->assertSame(1, TroubleCase::where('source_file_id', 'cal1')->count());

        TroubleCase::where('source_file_id', 'cal1')->delete();
        ProcessedReportFile::where('file_id', 'cal1')->delete();
        $this->actingAs($this->user(admin: true))->put('/drive/vendor-folders/v-cal', ['mode' => 'skip'])->assertSessionHas('success');
        $this->artisan('navi:ingest-vendor-reports')->assertSuccessful();
        $this->assertSame(0, TroubleCase::where('source_file_id', 'cal1')->count());
    }

    public function test_files_already_linked_by_legacy_data_are_not_ingested_again(): void
    {
        $this->fakeDrive()
            ->folder('vendor', 'v-trumpf', '作業報告書-見積書(トルンプ)')
            ->file('v-trumpf', 'legacy1', '20210720-#B0702A0033_TruBend_7036_作業報告書.pdf');
        TroubleCase::factory()->create(['machine_id' => 'B0702A0033', 'report_url' => 'https://drive.google.com/file/d/legacy1/view?usp=drivesdk']);

        $this->artisan('navi:ingest-vendor-reports')->assertSuccessful();

        $this->assertSame(1, TroubleCase::count());
        Http::assertNothingSent();
    }

    public function test_quote_waits_until_report_exists_and_mapping_requires_machine(): void
    {
        $this->fakeDrive()
            ->folder('vendor', 'v-trumpf', '作業報告書-見積書(トルンプ)')
            ->file('v-trumpf', 'q1', '20260821-#A0121C0046_TruMatic_6000_fiber_(K06)_ｵｲﾙｸｰﾗｰﾓｰﾀｰ_事後見積.pdf');

        $this->artisan('navi:ingest-vendor-reports')->assertSuccessful();
        $this->assertSame(1, cache(VendorReportIngestor::CACHE_RESULT)['quotes_waiting']);
        $this->assertSame(0, ProcessedReportFile::count()); // 次回また試す

        $case = TroubleCase::factory()->create(['machine_id' => 'A0121C0046', 'date' => '2026-08-21', 'quote_url' => null]);
        $this->artisan('navi:ingest-vendor-reports')->assertSuccessful();
        $this->assertSame('https://drive.google.com/file/d/q1/view', $case->fresh()->quote_url);

        $this->actingAs($this->user(admin: true))->put('/drive/vendor-folders/v-trumpf', ['mode' => 'fixed'])->assertSessionHasErrors('machine_id');
    }

    public function test_dry_run_only_reports_the_plan(): void
    {
        $this->fakeDrive()
            ->folder('vendor', 'v-trumpf', '作業報告書-見積書(トルンプ)')
            ->file('v-trumpf', 'r1', '20260824-#B0702A0033_TruBend_7036_(B19)_作業報告書.pdf')
            ->file('v-trumpf', 'q1', '20260824-#B0702A0033_TruBend_7036_(B19)_事後見積り.pdf');

        $this->artisan('navi:ingest-vendor-reports', ['--dry-run' => true])
            ->expectsOutputToContain('新規（AIで読む）: 1件 / 見積: 1件')
            ->assertSuccessful();

        $this->assertSame(0, TroubleCase::count());
        $this->assertSame(0, ProcessedReportFile::count());
        Http::assertNothingSent();
    }

    public function test_real_world_name_variants(): void
    {
        Machine::create(['id' => '1000482', 'model' => 'AuDeBu Mini', 'maker' => 'ｵｰｾﾝﾃｯｸ株式会社']);
        Machine::create(['id' => 'B0403A0045', 'model' => 'TruBendCell7036']);
        Machine::create(['id' => 'B0403A0306', 'model' => 'TruBend Cell 7036']); // 同じ型式が2台
        Machine::create(['id' => 'A0121D0128', 'model' => 'TruMatic6000fiber']); // 別の機械の型式名「TruMatic6000fiber(K06)」に含まれる
        $this->fakeDrive()
            ->folder('vendor', 'v-auth', '作業報告書-見積書(オーセンテック)')
            ->file('v-auth', 'a1', '2023-06-14_作業報告書(AuDeBuMiniﾌﾞﾚｰｶ交換修理).pdf')
            ->folder('vendor', 'v-tohoku', '東北事業所_トルンプ作業報告書')
            ->file('v-tohoku', 't1', 'Las18127#A0121C0046.pdf')
            ->file('v-tohoku', 't2', 'Lak18046#A0121C0046_ CheckList.pdf')
            ->file('v-tohoku', 't3', '20200619-#A0121D0046_Trumatic6000fiber(ｽｹｼﾞｭｰﾗPCｺｼｮｳ).pdf')
            ->file('v-tohoku', 't4', '20230919-#A0121C0046_Trumatic6000fiber(ｺﾝﾊﾟｸﾄｶｯﾀｰﾄｳｺｳｶﾝ)ﾐﾂﾓﾘ.pdf')
            ->folder('vendor', 'v-tb', '作業報告書-見積書(TruBend)')
            ->file('v-tb', 'b1', '20181018-Trubendcell7036_TS作業報告書.pdf')
            ->folder('vendor', 'v-cal', 'カレンダー')
            ->file('v-cal', 'cal', '2024年度カレンダー.pdf');

        $plan = collect(app(VendorReportIngestor::class)->ingest(null, true)['plan'])
            ->mapWithKeys(fn ($row) => [$row[1] => [$row[2], $row[3]]]);

        $this->assertSame(['1000482', '新規（AIで読む）'], $plan['2023-06-14_作業報告書(AuDeBuMiniﾌﾞﾚｰｶ交換修理).pdf']); // 型式名で特定
        $this->assertSame(['A0121C0046', '新規（AIで読む）'], $plan['Las18127#A0121C0046.pdf']);
        $this->assertSame(['', '対象外'], $plan['Lak18046#A0121C0046_ CheckList.pdf']);
        $this->assertSame(['', '機械不明'], $plan['20200619-#A0121D0046_Trumatic6000fiber(ｽｹｼﾞｭｰﾗPCｺｼｮｳ).pdf']); // 型式名で別の機械に推測しない
        $this->assertSame(['A0121C0046', '見積'], $plan['20230919-#A0121C0046_Trumatic6000fiber(ｺﾝﾊﾟｸﾄｶｯﾀｰﾄｳｺｳｶﾝ)ﾐﾂﾓﾘ.pdf']);
        $this->assertSame(['', '機械不明'], $plan['20181018-Trubendcell7036_TS作業報告書.pdf']); // 同じ型式が2台あるので特定しない
        $this->assertFalse($plan->has('2024年度カレンダー.pdf')); // カレンダーフォルダは既定で対象外
        $this->assertSame(VendorFolder::MODE_SKIP, VendorFolder::find('v-cal')->mode);
    }

    public function test_reports_matching_existing_legacy_cases_are_linked_without_ai(): void
    {
        $this->fakeDrive()
            ->folder('vendor', 'v-trumpf', '作業報告書-見積書(トルンプ)')
            ->file('v-trumpf', 'r1', '20240604-#A0121C0046_Trumatic6000fiber_作業報告書.pdf')
            ->file('v-trumpf', 'r2', '20240605-#A0121C0046_Trumatic6000fiber_作業報告書.pdf')
            ->file('v-trumpf', 'r3', '20240606-#A0121C0046_Trumatic6000fiber_作業報告書.pdf');
        // 旧データ: 6/4 は報告書URL無し、6/5 は別のPDFがリンク済み、6/6 は履歴なし
        $noUrl = TroubleCase::factory()->create(['machine_id' => 'A0121C0046', 'date' => '2024-06-04', 'report_url' => null]);
        TroubleCase::factory()->create(['machine_id' => 'A0121C0046', 'date' => '2024-06-05', 'report_url' => 'https://drive.google.com/file/d/other/view']);

        $this->artisan('navi:ingest-vendor-reports')->expectsOutputToContain('既存の履歴にリンク: 1件')->assertSuccessful();

        $this->assertSame('https://drive.google.com/file/d/r1/view', $noUrl->fresh()->report_url);
        $this->assertSame(1, TroubleCase::where('source_file_id', 'r3')->count()); // 新規だけAIで読む
        $this->assertSame(0, TroubleCase::where('source_file_id', 'r2')->count());
        Http::assertSentCount(1);
        $this->assertSame('skipped_existing', ProcessedReportFile::find('r2')->result);
    }
}
