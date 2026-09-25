# 設備トラブルナビ — 開発メモ

Laravel 13 + Inertia 3 + React 19 (TypeScript) + Tailwind 4。旧GAS版の仕様・経緯は `docs/gas-handoff.md`。

## 構成
- ドメイン: `app/Models`（`Machine`, `TroubleCase`, `CasePhoto`, `CaseRating`, `Consultation`, `ProcessedReportFile`）
- 対応履歴と「確認待ち」は同じ `trouble_cases` テーブル。`review_status`（published/pending/rejected）で区別し、画面・集計は `published()` スコープを使う
- 外部連携は `app/Services`: `Gemini/GeminiClient`（429 は `GeminiQuotaExceededException`）、`SlackNotifier`、`Drive/DriveClient`（本番 `GoogleDriveClient`、テスト `FakeDriveClient`）
- 画面は `resources/js/Pages`、対応履歴カードは `Components/CaseCard.tsx` に集約。props の形は `app/Http/Presenters/CasePresenter.php` と `resources/js/types.ts` を揃える
- Drive連携の結果（曖昧リスト・見積候補）は Cache に保存し、`/drive` 画面で人が確定する（`ReportLinker`, `QuoteSuggester`）
- 機種の表示順は「機種名（型式） + 機械番号」で統一（ユーザー要望）

## 注意
- 旧スプレッドシート由来の値は必ず `App\Support\LegacyValue` で正規化する（日付・全角数字の自動変換問題）
- 外部 HTTP はテストで `Http::preventStrayRequests()` 済み。Gemini/Slack は `Http::fake`、Drive は `$this->fakeDrive()`
- Slack Webhook URL をコードにハードコードしない

## チェック
`php artisan test` / `./vendor/bin/pint --test` / `npx tsc --noEmit` / `npm run build`
