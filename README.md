# 設備トラブルナビ（Laravel + React 版）

倉敷レーザーの設備トラブル対応履歴を **検索・登録・ダッシュボード表示** し、Gemini による **AI一次診断**、
Drive 上の **作業報告書PDFの自動取込み** と **Slack通知** を行う Web アプリ。
旧 Google Apps Script + スプレッドシート版（`docs/gas-handoff.md`）を Laravel 13 / Inertia 3 / React 19 で再設計したもの。

## 構成

| レイヤ | 技術 |
|---|---|
| サーバー | Laravel 13（PHP 8.3+）、Eloquent、スケジューラ、キュー |
| 画面 | Inertia.js 3 + React 19 + TypeScript + Tailwind CSS 4 |
| DB | 開発: SQLite / 本番: MySQL・PostgreSQL（どれでも可） |
| 認証 | Google Workspace ログイン（Socialite、`@g.kurashiki-laser.co.jp` のみ） |
| 外部連携 | Gemini API（相談・PDF抽出）、Slack Incoming Webhook、Google Drive API（サービスアカウント） |

### 旧GAS版からの主な変更点

| 旧GAS版 | 新版 |
|---|---|
| スプレッドシートの列位置に依存（`CASE_HEADERS` は末尾追加しか不可） | RDBの列名で読み書き。マイグレーションで自由に列追加できる |
| Sheets が日付・全角数字を勝手に型変換 | DB は型どおり保存。移行時だけ `LegacyValue` で正規化 |
| `PendingCases` シートを手で `approved` にして `promoteApprovedCases` | 画面「取込レビュー」で修正→承認/却下。承認で即公開＋Slack通知 |
| `linkReportsToDrive` の曖昧 61 件はログのみ | 画面「Drive連携」に候補PDF付きで一覧表示、ワンクリックで確定 |
| `index.html` に機種マスタ `MACHINES` を埋め込み | `machines` テーブル。画面「機械マスター」で追加・編集 |
| 「組織内の全員」デプロイ | Google ログイン + ドメイン制限（サーバー側で検証） |
| Slack Webhook のハードコード（偽URL） | `.env` の `SLACK_WEBHOOK_URL` のみ。未設定なら通知しない |

### 画面

- **ダッシュボード** `/` … 機種・拠点・カテゴリで絞り込み。件数・費用・停止日数、年別費用、機種別件数、エラーコード／交換部品の頻度、最近の履歴
- **症状検索** `/cases` … キーワード（スペース区切りAND）・機種・拠点・期間・評価順
- **症状登録／編集** `/cases/create` … 写真添付、報告書/見積書PDFのURL、登録で Slack 通知
- **対応履歴詳細** `/cases/{id}` … 類似事例、👍👎評価
- **AI相談** `/consult` … 類似の過去事例＋取説URLを根拠に Gemini が一次診断
- **機械マスター** `/machines`
- **取込レビュー** `/review`（管理者）… AI取込みの確認待ち
- **Drive連携** `/drive`（管理者）… 取込み/自動リンクの手動実行、曖昧候補の確定

## セットアップ（ローカル）

```sh
composer install
npm install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed     # デモデータ入り。dev@example.com が管理者
php artisan storage:link
```

`.env` で `NAVI_DEV_LOGIN=true` にすると Google OAuth 無しでログインできる（`APP_ENV=local` のときのみ）。

```sh
composer run dev   # サーバー・キュー・Vite をまとめて起動
# もしくは別々に: php artisan serve / php artisan queue:work / npm run dev
```

テスト・静的チェック:

```sh
php artisan test
./vendor/bin/pint --test
npx tsc --noEmit
npm run build
```

## 本番設定（`.env`）

| キー | 内容 |
|---|---|
| `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` | Google Cloud Console の OAuth クライアント（ウェブアプリ）。リダイレクトURI は `https://<ホスト>/auth/google/callback` |
| `NAVI_ALLOWED_DOMAIN` | ログインを許可するドメイン（既定 `g.kurashiki-laser.co.jp`） |
| `NAVI_ADMIN_EMAILS` | 管理者メール（カンマ区切り）。取込レビュー・Drive連携・削除・機種編集ができる |
| `GEMINI_API_KEY` / `GEMINI_MODEL` | Google AI Studio のキー。**無料枠は1分20リクエスト程度で PDF 取込みがすぐ 429 になるので従量課金を推奨** |
| `SLACK_WEBHOOK_URL` | Incoming Webhook。`php artisan navi:test-slack` で疎通確認 |
| `GOOGLE_APPLICATION_CREDENTIALS` | Drive 読み取り用サービスアカウントの JSON キーのパス。共有ルートフォルダ（`1BwmHW_…`）をそのサービスアカウントのメールに **閲覧者** で共有する |
| `NAVI_DRIVE_SITE_*` / `NAVI_DRIVE_VENDOR_FOLDER` | 拠点フォルダID（既定値は旧 `SITE_FOLDERS` と同じ） |

cron に以下を1行登録し、キューワーカー（`php artisan queue:work`）を常駐させる。

```
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

スケジュール（`routes/console.php`、日本時間）:
- 02:10 `navi:ingest-reports` … 未処理の ActivityReport PDF を AI で読み取り、確認待ちに追加（1回15件まで、429 で即中断し次回リトライ）
- 03:10 `navi:link-reports` … 報告書URL未設定の履歴を、ファイル名の日付×機種で自動リンク

## 旧GAS版からのデータ移行

スプレッドシート `設備トラブルナビ_データ` の各シートを「ファイル → ダウンロード → CSV」で書き出し、
`index.html` の `MACHINES` / `Data.gs` の `SEED_CASES` は JSON として保存してから取り込む。
ID ベースの upsert なので何度実行しても重複しない。

```sh
php artisan navi:import --machines=machines.json            # index.html の MACHINES（{id: {...}} 形式でも配列でも可）
php artisan navi:import --machines=Machines.csv             # 画面から追加された機種
php artisan navi:import --cases=seed_cases.json             # Data.gs の SEED_CASES（524件）
php artisan navi:import --cases=Cases.csv --source=manual   # Cases シート
php artisan navi:import --pending=PendingCases.csv          # 確認待ち（promoted は Cases 側を優先）
php artisan navi:import --ratings=Ratings.csv --consultations=Consultations.csv
```

- 日付は `2023/10/05`・`2023年10月5日`・ISO（UTC→JSTに補正）・シリアル値（`45204`）のいずれも受け付ける
- 費用 `¥12,300`・全角数字も数値化。報告書番号などは文字列のまま保持
- マスタに無い機種を参照する履歴は、機種を仮登録（`source=import`）して取り込む
- 旧写真（Drive URL）は外部リンクとしてそのまま保持

## コマンド一覧

| コマンド | 内容 |
|---|---|
| `navi:import` | 旧データ移行（上記） |
| `navi:ingest-reports [--limit=] [--retry-errors]` | 作業報告書PDFのAI取込み |
| `navi:link-reports` | 報告書PDFの自動リンク（曖昧なものは Drive連携画面へ） |
| `navi:link-quotes` | 見積書番号から見積書PDFを自動リンク（機械フォルダの「見積」→「メーカー作業報告書見積り」の順に探索） |
| `navi:suggest-quotes [--ai] [--ai-limit=30]` | 見積書番号の逆入力の候補づくり（下記） |
| `navi:test-slack` | Slack 疎通確認 |

### 見積書番号の逆入力（`navi:suggest-quotes`）

機械フォルダの「見積」サブフォルダにある `estXXXXXXXX.pdf` のうち、まだどの対応履歴にも紐づいていないものについて、
紐づけ先の候補（同じ機種・見積書番号未入力の対応履歴、最大3件）を作る。ファイル名に日付が無いため**自動では書き込まず**、
「Drive連携」画面で人が「この履歴の見積」を押して確定する（見積書番号と見積書PDFのURLが入る）。「該当なし」を押した見積は以後出さない。

候補の並び順（スコア）:
- 見積の日付と対応日の近さ（180日以上離れたものは候補外）。見積の日付は `--ai` ならPDFの発行日、それ以外は Drive の作成日時
- `--ai` のとき: 見積合計金額と費用の一致（税抜/税込 10%・8% の差を許容）、見積品目と交換部品・対処内容の重なり
- AIで読んだ結果はキャッシュするので、同じPDFを何度も読み直さない（クォータ超過時は途中からDriveの日付のみで続行）

画面の「今すぐ実行」は、Gemini が設定されていれば `--ai` 付きで動く。

### CSV出力

検索画面の「CSVでダウンロード」で、検索条件に一致した対応履歴を Excel で開ける CSV（UTF-8 BOM付き）として出力できる。

## 未着手（旧版からの持ち越し）

1. 「メーカー作業報告書見積り」フォルダ（業者別）の報告書の自動取込み（業者名→機種の対応表が必要）。
2. 見積書番号の逆入力は、候補を人が確定する方式。実データで精度を見て、確度の高いもの（金額一致かつ日付が近い等）を自動確定にするか判断する。
