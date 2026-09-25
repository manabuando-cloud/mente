<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 全社の設備マスタ（SOFTエクスポート）の項目 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            $table->string('equipment_no')->nullable()->index(); // 設備NO
            $table->text('spec')->nullable();                   // 仕様
            $table->date('installed_on')->nullable();           // 導入日
        });
    }

    public function down(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            $table->dropIndex(['equipment_no']); // SQLite はインデックスを先に消さないと列を削除できない
            $table->dropColumn(['equipment_no', 'spec', 'installed_on']);
        });
    }
};
