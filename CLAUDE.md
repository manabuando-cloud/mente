# 設備トラブルナビ — 開発メモ

Laravel 13 + Inertia 3 + React 19 (TypeScript) + Tailwind 4。旧GAS版の仕様・経緯は `docs/gas-handoff.md`。

## 構成
- ドメイン: `app/Models`（`Machine`, `TroubleCase`, `CasePhoto`, `CaseRating`, `Consultation`, `ProcessedReportFile`）
- 対応履歴と「確認待ち」は同じ `trouble_cases` テーブル。`review_status`（published/pending/rejected）で区別し、画面・集計は `published()` スコープを使う
- 外部連携は `app/Services`: `Gemini/GeminiClient`（429 は `GeminiQuotaExceededException`）、`SlackNotifier`、`Drive/DriveClient`（本番 `GoogleDriveClient`、テスト `FakeDriveClient`）
- 画面は `resources/js/Pages`、対応履歴カードは `Components/CaseCard.tsx` に集約。props の形は `app/Http/Presenters/CasePresenter.php` と `resources/js/types.ts` を揃える
- Drive連携の結果（曖昧リスト・見積候補）は Cache に保存し、`/drive` 画面で人が確定する（`ReportLinker`, `QuoteSuggester`）
- 機種の表示順は「機種名（型式） + 機械番号」で統一（ユーザー要望）

## 本番
- 社内の Windows PC（kltech04）で WSL2 + Docker Engine（`deploy/windows/`、手順は `docs/deploy-windows.md`）。DB は SQLite（WAL）、バックアップは `navi:backup`。課金なしが前提
- 代替: Cloud Run（`deploy/cloudrun/`。キューワーカーは置かず `QUEUE_CONNECTION=sync`、毎日の処理は `navi:daily`）
- ログインは `NAVI_AUTH_MODE=google`（会社の Google Workspace アカウントだけ。管理者は `NAVI_ADMIN_EMAILS`）。`open`（名前だけ・管理者パスコード）も残してある。`User::isAdmin()` で判定
- Google の戻り先URLは相対（`/auth/google/callback`）で、開いたURL（localhost / ngrok の公開URL）に合わせる。プロキシ越しは TrustProxies で https になる

## 注意
- 旧スプレッドシート由来の値は必ず `App\Support\LegacyValue` で正規化する（日付・全角数字の自動変換問題）
- 外部 HTTP はテストで `Http::preventStrayRequests()` 済み。Gemini/Slack は `Http::fake`、Drive は `$this->fakeDrive()`
- Slack Webhook URL をコードにハードコードしない

## チェック
`php artisan test` / `./vendor/bin/pint --test` / `npx tsc --noEmit` / `npm run build`
