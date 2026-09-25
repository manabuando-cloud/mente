<?php

use Illuminate\Support\Facades\Schedule;

// 旧: dailyReportSync（毎日深夜2時台）。承認は画面上の「取込レビュー」で行うので promote は不要。
Schedule::command('navi:ingest-reports')->dailyAt('02:10')->timezone('Asia/Tokyo')->withoutOverlapping();
Schedule::command('navi:ingest-vendor-reports')->dailyAt('02:40')->timezone('Asia/Tokyo')->withoutOverlapping();
Schedule::command('navi:backup')->dailyAt('01:30')->timezone('Asia/Tokyo')->withoutOverlapping();
Schedule::command('navi:link-reports')->dailyAt('03:10')->timezone('Asia/Tokyo')->withoutOverlapping();
