<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 設備トラブルナビのドメインテーブル。
 *
 * GAS版ではスプレッドシートの列位置に依存していた（CASE_HEADERSの末尾追加ルール）が、
 * RDBでは列名で読み書きするので、今後は通常のマイグレーションで自由に列を追加できる。
 */
return new class extends Migration
{
    public function up(): void
    {
        // 機種マスタ（旧: index.html内蔵のMACHINES + Machinesシート）
        Schema::create('machines', function (Blueprint $table) {
            $table->string('id')->primary();            // 機械番号 例: B1508I0077
            $table->string('model');                     // 型式 例: TruBend5230(B23)
            $table->string('maker')->nullable();
            $table->string('label')->nullable();         // 通称・設置場所など
            $table->string('site')->nullable()->index(); // 拠点 例: 本社
            $table->string('category')->nullable()->index();
            $table->json('manuals')->nullable();         // [{title, url}]
            $table->string('drive_folder_id')->nullable(); // <機械番号>_<型式> フォルダ
            $table->string('source')->default('master'); // master | user
            $table->string('submitted_by')->nullable();
            $table->timestamps();
        });

        // 対応履歴（旧: Cases + PendingCases シート）
        // review_status で「確認待ちステージング」も同一テーブルで扱う。
        Schema::create('trouble_cases', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('machine_id')->index();
            $table->date('date')->nullable()->index();
            $table->string('engineer')->nullable();
            $table->text('symptom');
            $table->string('report_no')->nullable();
            $table->string('quote_no')->nullable()->index();
            $table->text('cause')->nullable();
            $table->text('action')->nullable();
            $table->text('codes')->nullable();   // エラーコード（カンマ・読点区切り）
            $table->text('parts')->nullable();   // 交換部品（カンマ・読点区切り）
            $table->unsignedInteger('cost')->nullable();
            $table->string('status')->nullable();
            $table->text('note')->nullable();
            $table->unsignedInteger('days')->nullable(); // 停止日数
            $table->string('submitted_by')->nullable();
            $table->timestamp('slack_notified_at')->nullable();
            $table->text('report_url')->nullable();
            $table->text('quote_url')->nullable();

            // published | pending | approved | rejected
            $table->string('review_status')->default('published')->index();
            $table->string('source')->default('manual'); // manual | seed | ai_ingest
            $table->string('source_file_id')->nullable()->index();
            $table->text('source_url')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();
        });

        Schema::create('case_photos', function (Blueprint $table) {
            $table->id();
            $table->string('trouble_case_id')->index();
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->timestamps();
        });

        // 👍👎評価（旧: Ratings シート）
        Schema::create('case_ratings', function (Blueprint $table) {
            $table->id();
            $table->string('trouble_case_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->tinyInteger('value'); // 1 or -1
            $table->timestamps();
            $table->unique(['trouble_case_id', 'user_id']);
        });

        // AI一次相談の履歴（旧: Consultations シート）
        Schema::create('consultations', function (Blueprint $table) {
            $table->id();
            $table->string('trouble_case_id')->nullable()->index();
            $table->string('machine_id')->nullable()->index();
            $table->string('site')->nullable();
            $table->text('symptom');
            $table->longText('answer')->nullable();
            $table->json('similar_case_ids')->nullable();
            $table->string('source')->default('web');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        // 取込み済みPDF（旧: ProcessedReportFiles シート）
        Schema::create('processed_report_files', function (Blueprint $table) {
            $table->string('file_id')->primary();
            $table->string('machine_id')->nullable()->index();
            $table->string('file_name')->nullable();
            $table->string('result'); // pending_created | skipped | error:...
            $table->timestamp('processed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processed_report_files');
        Schema::dropIfExists('consultations');
        Schema::dropIfExists('case_ratings');
        Schema::dropIfExists('case_photos');
        Schema::dropIfExists('trouble_cases');
        Schema::dropIfExists('machines');
    }
};
