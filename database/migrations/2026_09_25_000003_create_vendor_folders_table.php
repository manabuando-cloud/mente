<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 「メーカー作業報告書見積り」配下の業者別フォルダと機種の対応表。
 * mode: filename = ファイル名の機械番号（例 "#B0702A0033"）で判定 / fixed = このフォルダは1台専用 / skip = 対象外
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_folders', function (Blueprint $table) {
            $table->string('id')->primary(); // DriveフォルダID
            $table->string('name');
            $table->string('mode')->default('filename');
            $table->string('machine_id')->nullable();
            $table->timestamp('scanned_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_folders');
    }
};
