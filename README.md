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

## 本番デプロイ

- **Windows PC（kltech04）・費用ゼロ**: **[docs/deploy-windows.md](docs/deploy-windows.md)**（`deploy/windows/navi.sh`）。現在の本番の想定。WSL2 + Docker Engine + SQLite、会社の Google アカウントでログイン、公開URLは ngrok（無料の固定ドメイン）
- Google Cloud（Cloud Run・要課金）: [docs/deploy-cloudrun.md](docs/deploy-cloudrun.md)（`deploy/cloudrun/deploy.sh`）
- Linux サーバー（Docker + MySQL / `compose.yaml`）: [docs/deploy.md](docs/deploy.md)

ログイン方式は `NAVI_AUTH_MODE` で切り替える。`open` は「URLを知っている人なら誰でも使える」（名前だけ入力、管理機能は `NAVI_ADMIN_PASSCODE`）、`google` は Google Workspace ログイン。

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
- 02:40 `navi:ingest-vendor-reports` … 業者別フォルダの報告書を確認待ちに追加し、見積を同じ日の履歴に紐づけ
- 03:10 `navi:link-reports` … 報告書URL未設定の履歴を、ファイル名の日付×機種で自動リンク

## 旧GAS版からのデータ移行

共有ルートフォルダの2つのスプレッドシートから移行する。ID ベースの upsert なので何度実行しても重複しない。

1. **設備マスタ一覧（SOFTエクスポート）** を「ファイル → ダウンロード → CSV」で書き出して取り込む。
   Driveの機械フォルダ名の先頭と同じ「シリアルNO」が機械番号になる（空欄・壊れたものは `EQ-<設備NO>`、重複は2件目以降を `<シリアルNO>-<設備NO>`）。
   ```sh
   php artisan navi:import --soft=設備マスタ一覧.csv
   ```
2. **設備トラブルナビ_データ** の各シートをCSVで書き出して取り込む（シートごとにファイルが分かれる）。
   ```sh
   php artisan navi:import --cases=Cases.csv
   php artisan navi:import --pending=PendingCases.csv --consultations=AiConsultations.csv --ratings=Ratings.csv
   php artisan navi:import --machines=CustomMachines.csv    # 画面から追加された機種（あれば）
   ```
3. **Driveの機械フォルダから機種マスタを補完**（設備マスタにシリアルが無い機械や、履歴だけにある機械の型式・拠点を埋める）。
   ```sh
   php artisan navi:sync-drive-machines --dry-run   # 確認
   php artisan navi:sync-drive-machines
   ```
4. Drive連携画面で「業者別フォルダの対応表」を確認し、`php artisan navi:ingest-vendor-reports --dry-run` で振り分けを確認してから取込みを始める。

移行時の正規化（実データで確認済み）:
- `codes` / `parts` 列のJSON（`["#B7032"]`、`[{"n":"部品名","id":"品番","q":1}]`）をそのまま構造化して保存。部品名にカンマを含むものがあるので部品は `[{n, id, q}]` で持つ
- 空欄の代わりの `—` / `―` は空として扱う。症状が空欄の記録（見積のみ・移設工事など）も「（症状の記録なし）」として残す
- Sheets が数値化した機械番号（`650200.0`）、日付（`2023/10/05`・ISO・シリアル値）、費用（`¥12,300`・全角数字）を元に戻す
- 拠点の `1_本社` `2_九州` などは正式名（本社・九州事業所…）に揃える
- 対応状況は旧データのコード（`repaired` 修理完了 / `pending` 対応中 / `free` 無償対応 / `quote_only` 見積のみ）のまま保存し、画面で日本語表示する
- マスタに無い機種を参照する履歴は機種を仮登録（`source=import`）し、手順3で型式を補完する

## コマンド一覧

| コマンド | 内容 |
|---|---|
| `navi:import` | 旧データ移行（上記） |
| `navi:ingest-reports [--limit=] [--retry-errors]` | 作業報告書PDFのAI取込み |
| `navi:link-reports` | 報告書PDFの自動リンク（曖昧なものは Drive連携画面へ） |
| `navi:link-quotes` | 見積書番号から見積書PDFを自動リンク（機械フォルダの「見積」→「メーカー作業報告書見積り」の順に探索） |
| `navi:suggest-quotes [--ai] [--ai-limit=30]` | 見積書番号の逆入力の候補づくり（下記） |
| `navi:ingest-vendor-reports [--limit=] [--dry-run]` | 「メーカー作業報告書見積り」フォルダの取込み（下記） |
| `navi:sync-drive-machines [--dry-run]` | Driveの機械フォルダ名から機種マスタを補完 |
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

### 業者別フォルダの取込み（`navi:ingest-vendor-reports`）

「メーカー作業報告書見積り」配下は業者別に整理されていて、機械フォルダとは構成が違う。

- **機械の特定**: ① Drive連携画面の対応表で「1台専用」に設定したフォルダはその機械（既定で `salvagnini_L3-30` → `L_0987` など）
  ② ファイル名の `#<機械番号>`（トルンプの報告書は `20260824-#B0702A0033_TruBend_7036_(B19)_x20ﾓｼﾞｭｰﾙ交換_作業報告書.pdf` の形）
  ③ ファイル名に登録済みの機械番号がそのまま含まれる。特定できないファイルは画面に一覧表示される
- **ファイルの種類**: 点検チェックリスト・納品書・写真・請求書は対象外。「見積」を含むものは同じ機械・同じ日付の履歴に見積書PDFとして紐づける（AIは使わない。報告書がまだ無ければ次回再試行）。それ以外は作業報告書としてAIで読み取り「取込レビュー」へ
- 旧データで報告書URLが入っているファイル、すでに取り込んだファイルは対象外
- ファイル名の日付が未来（打ち間違い）の場合はPDFから読んだ日付を使う
- `--dry-run` でAIを使わずに振り分け結果だけを表で確認できる

### CSV出力

検索画面の「CSVでダウンロード」で、検索条件に一致した対応履歴を Excel で開ける CSV（UTF-8 BOM付き）として出力できる。

## 未着手（旧版からの持ち越し）

1. 見積書番号の逆入力は、候補を人が確定する方式。実データで精度を見て、確度の高いもの（金額一致かつ日付が近い等）を自動確定にするか判断する。
