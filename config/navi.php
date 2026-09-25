<?php

return [

    /*
    | ログイン方式
    |   google: Google Workspace アカウントでログイン（下の allowed_domain で制限）
    |   open  : URLを知っている人なら誰でも使える。最初に名前だけ入力してもらい、
    |           管理機能（取込レビュー・Drive連携・削除など）は admin_passcode を知っている人だけ
    */
    'auth_mode' => env('NAVI_AUTH_MODE', 'google'),

    // open モードで管理者になるための合言葉。空なら open モードでは誰も管理者になれない
    'admin_passcode' => env('NAVI_ADMIN_PASSCODE'),

    /*
    | Google Workspace ドメイン制限（旧GAS: 「組織内の全員」デプロイ相当）。
    | 空にするとドメイン制限なし。
    */
    'allowed_domain' => env('NAVI_ALLOWED_DOMAIN', 'g.kurashiki-laser.co.jp'),

    /*
    | 管理者（取込レビューの承認・機種マスタ編集など）として扱うメールアドレス。カンマ区切り。
    */
    'admin_emails' => array_filter(array_map('trim', explode(',', (string) env('NAVI_ADMIN_EMAILS', '')))),

    /*
    | ローカル開発用のログインバイパス（APP_ENV=local のときのみ有効）。
    */
    'dev_login' => (bool) env('NAVI_DEV_LOGIN', false),

    /*
    | Google Drive 上の拠点フォルダ（旧: Code.gs の SITE_FOLDERS）。
    | 各拠点フォルダ直下に <機械番号>_<型式>/ フォルダがあり、その中に
    |   <YYYYMMDD>_<時刻>_ActivityReport.pdf  … 作業報告書
    |   見積/estXXXXXXXX[_接尾辞].pdf          … 見積書（ファイル名＝見積書番号）
    | が置かれている。
    */
    'drive' => [
        'root_folder_id' => env('NAVI_DRIVE_ROOT', '1BwmHW_UNOpbnTi9XdSCwUVRx_Lw2ghjc'),
        'site_folders' => [
            '本社' => env('NAVI_DRIVE_SITE_HONSHA', '1hqndULTkyVbquhA59L0xGzgchw0co27z'),
            '九州事業所' => env('NAVI_DRIVE_SITE_KYUSHU', '1bWc5oDYuwDjHDfwT9FvQG83hJnkoicge'),
            '東北工場' => env('NAVI_DRIVE_SITE_TOHOKU', '1dCJCZPYKm8n4NLhVHd4pkIbU7UvcZaXc'),
            '中部事業所' => env('NAVI_DRIVE_SITE_CHUBU', '17SKJ_lMqlyJtm6wakPv7lcTY9YWPq7Us'),
        ],
        // 業者別に整理された「メーカー作業報告書見積り」フォルダ（見積検索のフォールバック先）
        'vendor_folder_id' => env('NAVI_DRIVE_VENDOR_FOLDER', '1rnmixHlfXjeZ_H8j-XUIdMLghrCw9-SC'),
        'quote_subfolder_name' => '見積',
        // サービスアカウントのJSONキー。Driveフォルダを当該アカウントに共有しておくこと。
        'credentials' => env('GOOGLE_APPLICATION_CREDENTIALS'),
        // Cloud Run などでは鍵ファイルを使わず、実行中のサービスアカウントの認証情報（ADC）を使う
        'use_adc' => (bool) env('NAVI_DRIVE_USE_ADC', false),
    ],

    // 拠点名の表記ゆれ（旧データ・設備マスタの「事業所」列）
    'site_aliases' => [
        '本社' => ['本社工場', '倉敷'],
        '九州事業所' => ['九州', '九州工場'],
        '東北工場' => ['東北', '東北事業所'],
        '中部事業所' => ['中部', '中部工場'],
    ],

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),
        'endpoint' => env('GEMINI_ENDPOINT', 'https://generativelanguage.googleapis.com/v1beta'),
        'timeout' => (int) env('GEMINI_TIMEOUT', 120),
        // 無料枠は1分あたりの回数制限が厳しいので、連続で呼ぶときの最低間隔（ミリ秒）。無料枠なら 7000 程度
        'min_interval_ms' => (int) env('GEMINI_MIN_INTERVAL_MS', 0),
    ],

    'slack' => [
        'webhook_url' => env('SLACK_WEBHOOK_URL'),
    ],

    // 写真の保存先ディスク（config/filesystems.php）
    'photos_disk' => env('NAVI_PHOTOS_DISK', 'public'),

    // 毎日のバックアップ（navi:backup）の保存先と保存日数
    'backup' => [
        'path' => env('NAVI_BACKUP_PATH', storage_path('backups')),
        'keep_days' => (int) env('NAVI_BACKUP_KEEP_DAYS', 30),
    ],

    // 1回の自動取込みで処理するPDFの上限（Geminiのクォータ対策）
    'ingest_batch_limit' => (int) env('NAVI_INGEST_BATCH_LIMIT', 15),
];
