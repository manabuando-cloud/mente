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
        ]));
        file_put_contents("$dir/ratings.csv", "docId,caseId,uid,value,at\nr1,c001,a@g.kurashiki-laser.co.jp,1,\nr2,c001,b@g.kurashiki-laser.co.jp,-1,");

        $args = ['--machines' => "$dir/machines.json", '--cases' => "$dir/cases.csv", '--ratings' => "$dir/ratings.csv"];
        $this->artisan('navi:import', $args)->assertSuccessful();
        $this->artisan('navi:import', $args)->assertSuccessful(); // 2回目も重複しない

        $this->assertSame(2, TroubleCase::count());
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
        $this->assertSame(2, CaseRating::count());
    }
}
