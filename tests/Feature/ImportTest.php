<?php

namespace Tests\Feature;

use App\Models\CaseRating;
use App\Models\Machine;
use App\Models\TroubleCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_imports_legacy_sheets_idempotently(): void
    {
        $dir = sys_get_temp_dir().'/navi-import-'.uniqid();
        mkdir($dir);

        file_put_contents("$dir/machines.json", json_encode([
            'B1508I0077' => ['model' => 'TruBend5230(B23)', 'maker' => 'TRUMPF', 'site' => '本社', 'manuals' => ['https://example.com/m.pdf']],
        ]));

        // CASE_HEADERS の順。Sheets の癖（Date型化・全角数字・シリアル値）を含む
        $header = 'id,m,date,eng,symptom,reportNo,quoteNo,cause,action,codes,parts,cost,status,note,days,submittedBy,createdAt,updatedAt,slackNotified,photos,reportUrl,quoteUrl';
        file_put_contents("$dir/cases.csv", "\xEF\xBB\xBF".implode("\n", [
            $header,
            'c001,B1508I0077,2023/10/05,山田,"原点復帰しない, E1",１２３,est23102936,センサー,交換,E1,センサー,"¥12,300",完了,,２,a@x,2023-10-05T01:00:00.000Z,,TRUE,https://drive.google.com/p1,,',
            'c002,ZZ999,45204,,異音,,,,,,,,,,,,,,,,https://drive.google.com/file/d/r/view,',
            'seed_499,ZZ999,2026-02-10T00:00:00,—,—,,,—,移設に伴う電気工事の御見積り,[],[],550000.0,quote_only,見積No.2026-K32,,,,,True,[],,',
            // 旧GAS版の実データの形: codes/parts がJSON、空欄が「—」、数字だけの機械番号が "650200.0"
            'seed_3,650200.0,2021-10-20T00:00:00,—,Y軸エンコーダエラー,,,Y2軸ケーブル不良,—,"[""#B7032"",""95100197""]","[{""n"":""APC910 Standard 2, LS187, BIOS"",""id"":""2009706"",""q"":1}]",52506.0,repaired,,14.0,,,,TRUE,[],,',
        ]));
        file_put_contents("$dir/ratings.csv", "docId,caseId,uid,value,at\nr1,c001,a@g.kurashiki-laser.co.jp,1,\nr2,c001,b@g.kurashiki-laser.co.jp,-1,");

        $args = ['--machines' => "$dir/machines.json", '--cases' => "$dir/cases.csv", '--ratings' => "$dir/ratings.csv"];
        $this->artisan('navi:import', $args)->assertSuccessful();
        $this->artisan('navi:import', $args)->assertSuccessful(); // 2回目も重複しない

        $this->assertSame(4, TroubleCase::count());
        $this->assertSame('（症状の記録なし）', TroubleCase::find('seed_499')->symptom);
        $this->assertSame('quote_only', TroubleCase::find('seed_499')->status);
        $c1 = TroubleCase::find('c001');
        $this->assertSame('2023-10-05', $c1->date->format('Y-m-d'));
        $this->assertSame('１２３', $c1->report_no); // 文字列のまま保持（数値化しない）
        $this->assertSame(12300, $c1->cost);
        $this->assertSame(2, $c1->days);
        $this->assertNotNull($c1->slack_notified_at);
        $this->assertSame('2023-10-05 10:00:00', $c1->created_at->format('Y-m-d H:i:s'));
        $this->assertCount(1, $c1->photos);

        $c2 = TroubleCase::find('c002');
        $this->assertSame('2023-10-05', $c2->date->format('Y-m-d')); // シリアル値 45204
        $this->assertSame('import', Machine::find('ZZ999')->source); // 未知の機種は仮登録

        $this->assertSame([['title' => '取扱説明書', 'url' => 'https://example.com/m.pdf']], Machine::find('B1508I0077')->manuals);

        $c3 = TroubleCase::find('seed_3');
        $this->assertSame('650200', $c3->machine_id);
        $this->assertNull($c3->engineer);
        $this->assertNull($c3->action);
        $this->assertSame('#B7032, 95100197', $c3->codes);
        $this->assertSame([['n' => 'APC910 Standard 2, LS187, BIOS', 'id' => '2009706', 'q' => 1]], $c3->parts);
        $this->assertSame(14, $c3->days);
        $this->assertSame('2021-10-20', $c3->date->format('Y-m-d'));
        $this->assertSame(2, CaseRating::count());
    }

    public function test_imports_soft_equipment_master(): void
    {
        $file = sys_get_temp_dir().'/navi-soft-'.uniqid().'.csv';
        file_put_contents($file, implode("\n", [
            '設備NO,事業所,設備分類,設備名,使用部門,呼称,点検周期,次回点検年月,機械番号,メーカー,シリアルNO,導入日,仕様,備考',
            '126,1_本社,201_ベンディングマシン,TruBend5230,52_製造　2課,B2_板金,,2024/03,TruBend5230,4501_TRUMPF,B1508I0077,2018-03-08 0:00:00,,',
            '103,1_本社,102_YAGレーザー加工機,salvagnini L3-30,51_製造　1課,L3_salvagnini L3-30,01_1ヶ月,2026/06,L3-30,3101_サルバニーニ,L_0987,2019-09-07 0:00:00,,',
            '135,1_本社,319_PFO,PFO20-2,53_製造　3課,W3_溶接,,,PFO20-2,4501_TRUMPF,S/N 003282,2020-06-23 0:00:00,PFO20-2,',
            '115,1_本社,108_フォークリフト,FBRMA25-80,51_製造　1課,工場1_製造1課,,,FBRMA25-80,9004_ニチユ三菱,1.41E-185,2018-01-01 0:00:00,,',
            '206,2_九州,905_ブースター,GBVL14-223A,99_その他,設備_設備,,,GBVL14-223A,9007_田邉,160634,2019-11-01 0:00:00,,',
            '203,2_九州,904_中圧,SASG19VD-E,99_その他,設備_設備,,,SASG19VD-E,9002_北越,160634,2018-02-10 0:00:00,,',
            '269,2_九州,102_YAGレーザー加工機,Trulasercenter7030,51_製造　1課,T2_T-2,,,TrulaserCenter7030,4501_TRUMPF,,2024-02-10 0:00:00,,',
        ]));

        $this->artisan('navi:import', ['--soft' => $file])->expectsOutputToContain('設備マスタ: 7件')->assertSuccessful();

        $m = Machine::find('B1508I0077');
        $this->assertSame('TruBend5230', $m->model);
        $this->assertSame('TRUMPF', $m->maker);
        $this->assertSame('本社', $m->site);
        $this->assertSame('ベンディングマシン', $m->category);
        $this->assertSame('板金', $m->label);
        $this->assertSame('126', $m->equipment_no);
        $this->assertSame('2018-03-08', $m->installed_on->format('Y-m-d'));

        $this->assertSame('九州事業所', Machine::find('EQ-269')->site); // シリアル空欄
        $this->assertNotNull(Machine::find('L_0987'));
        $this->assertNotNull(Machine::find('003282'));  // "S/N " を除去
        $this->assertNotNull(Machine::find('EQ-115'));  // 指数表記で壊れたシリアル
        $this->assertSame('GBVL14-223A', Machine::find('160634')->model); // 重複シリアルは最初の行が優先
        $this->assertSame('SASG19VD-E', Machine::find('160634-203')->model);
    }
}
