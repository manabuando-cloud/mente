# 設備トラブルナビ — 引き継ぎメモ（Claude Code再開用）

倉敷レーザーの設備トラブル対応履歴を検索・登録・ダッシュボード表示し、Gemini APIによるAI一次診断とSlack通知を行うGoogle Apps Script（GAS）+ Google Sheets製Webアプリ。Cowork（本セッション）でここまで作り込んだ内容を、Claude Codeで新規セッションとして引き継ぐためのまとめ。

## 1. 全体構成

- **実行環境**: Google Apps Script（GASエディタ上で直接編集）。ローカルにgitリポジトリなどは無く、`Code.gs` / `index.html` / `Data.gs` の3ファイルをApps Scriptエディタ側にコピー＆ペーストして使う運用。
- **データストア**: Googleスプレッドシート1つ（`設備トラブルナビ_データ`、ID: `1-p9iQ0WfNX30ob9hyEkIqxO-CExQoaTECcscf-F_8Ww`）の中に複数シート（タブ）を持つ。
- **写真の保存先**: Googleドライブフォルダ「設備トラブルナビ_写真」（ID: `1e7FWgx2guIhZLCby8cuZBHP0Npu0yElA`）。
- **共有ルートフォルダ**: `https://drive.google.com/drive/u/0/folders/1BwmHW_UNOpbnTi9XdSCwUVRx_Lw2ghjc`（「全社」グループにreader権限。上記スプレッドシート・写真フォルダはこの中に作成されており、閲覧権限を継承している）。
- **デプロイ**: Webアプリとして「実行するユーザー: 自分」「アクセスできるユーザー: 組織内の全員」でデプロイ。コード変更時は「デプロイ管理」→既存デプロイの鉛筆アイコン→「新しいバージョン」で再デプロイ（URLは変わらない）。

## 2. ファイル構成

- `Code.gs` — サーバー側ロジック全部（後述）。
- `index.html` — 画面本体（症状登録・検索・ダッシュボード・AI相談・機械マスター登録の5画面、単一HTMLファイル、JSはインライン、`google.script.run`経由でサーバーと通信）。
- `Data.gs` — `var SEED_CASES = [...]`（524件の過去対応履歴）と機種マスタ相当のデータ。`index.html`側にも`var MACHINES = {...}`という機種マスタ（型式・メーカー・拠点・取説URLなど）が直接埋め込まれている（こちらは全社共通の設備マスタから同期したもの、約246機種）。
- `セットアップ手順.md` — エンドユーザー（MANABU氏）向けのセットアップ手順書。今回のセッションで追加した機能の説明もここに追記済み。

いずれも `/mnt/user-data/outputs/` に最新版を都度SendUserFileで届けている。**このセッションのローカルワークスペース（`/home/claude/gas-repair-nav/`）にある内容が最新の正**。Claude Codeで再開する場合はこれらのファイルを渡すか、内容をコピーして使うこと。

## 3. スプレッドシートのシート構成

| シート名 | ヘッダー（`Code.gs`内の定数） | 用途 |
|---|---|---|
| `Cases` | `CASE_HEADERS`（下記） | 正式な対応履歴。ダッシュボード・検索はここを表示 |
| `PendingCases` | `CASE_HEADERS` + `sourceFileId, sourceUrl, reviewStatus, reviewNote` | AI自動取込みの確認待ちステージング |
| `ProcessedReportFiles` | `fileId, machineId, processedAt, result` | 取込み済みPDFの重複処理防止用 |
| `Ratings` | `docId, caseId, uid, value, at` | 対応履歴への👍👎評価 |
| `Consultations`（`CONSULT_HEADERS`使用） | `id, caseId, m, site, symptom, answer, similarCaseIds, source, createdAt` | AI一次相談の履歴 |
| `Machines`（`MACHINE_HEADERS`使用） | `id, model, maker, label, site, manuals, createdAt, submittedBy` | 「機械マスター登録」画面から追加された機種（index.html内蔵の`MACHINES`に無いもの） |

### `CASE_HEADERS`（現在の最終形）

```js
['id','m','date','eng','symptom','reportNo','quoteNo','cause','action','codes','parts','cost','status','note','days','submittedBy','createdAt','updatedAt','slackNotified','photos','reportUrl','quoteUrl']
```

**重要な設計制約**: `reportUrl`・`quoteUrl`は今回のセッションで**末尾に追記**した。`getSheet_()`が呼ぶ`ensureHeaders_()`は、既存シートに無いヘッダー名を**必ず末尾に追加**する（途中挿入は絶対にしない）仕組みになっている。これは`caseToRow_`/`rowToCase_`/`addCase`/`updateCase`が全て「シートの列位置 = `CASE_HEADERS`配列のインデックス」という前提で書かれているため。**今後`CASE_HEADERS`に新しい項目を足すときは、必ず配列の一番最後に追加すること**（途中に入れると本番シートの既存データと列がズレて壊れる）。

## 4. Googleドライブのフォルダ構造（実地確認済み）

```
共有ルート (1BwmHW_UNOpbnTi9XdSCwUVRx_Lw2ghjc)
├─ 設備トラブルナビ_データ（スプレッドシート）
├─ 設備トラブルナビ_写真（フォルダ）
├─ 本社 (1hqndULTkyVbquhA59L0xGzgchw0co27z)
│   ├─ <機械番号>_<型式>/  例: B1508I0077_TruBend5230(B23)
│   │   ├─ <YYYYMMDD>_<時刻>_ActivityReport.pdf  ← 作業報告書。ファイル名先頭が対応日
│   │   └─ 見積/                                  ← ★見積書PDFはここ
│   │       └─ estXXXXXXXX[_事後見積り].pdf        ← ファイル名自体が見積書番号
│   └─ ...（機種ごとに同じ構造）
├─ 九州事業所 (1bWc5oDYuwDjHDfwT9FvQG83hJnkoicge)   ← 同じ構造
├─ 東北工場 (1dCJCZPYKm8n4NLhVHd4pkIbU7UvcZaXc)     ← 同じ構造
├─ 中部事業所 (17SKJ_lMqlyJtm6wakPv7lcTY9YWPq7Us)   ← 同じ構造
└─ メーカー作業報告書見積り (1rnmixHlfXjeZ_H8j-XUIdMLghrCw9-SC)
    └─ <業者名>/...PDF...   ← 機種と紐づけづらい別系統。ingestNewReportsの対象外
```

`SITE_FOLDERS`定数（`Code.gs`内）がこの4拠点のIDを持っている。「見積」サブフォルダの存在は前回セッションの終盤で発見したばかりで、それ以前は「メーカー作業報告書見積り」フォルダ（業者別整理）しか見積の置き場所として認識していなかった。**この発見を反映した`linkQuotesToDrive`の書き直しが直近の作業**（詳細は6節）。

## 5. 実装済み機能

### 5.1 基本機能（既存・継続）
- 症状登録（写真添付可）、症状検索、機械マスター登録、履歴ダッシュボード（機種・拠点・カテゴリで絞り込み、統計・エラーコード頻度・部品交換頻度・年別費用グラフ）、AI一次相談（Gemini、過去事例＋取説を参照）。
- Slack通知（登録と同時にIncoming Webhook経由）。**ユーザー確認済みで正常動作**。
- Googleシートの「全角数字文字列が自動でNumber型になる」問題への対処（`str_`/`strOrNull_`ヘルパーで文字列に強制）。

### 5.2 今回のセッションで対応した不具合修正
1. **保存失敗（"保存に失敗しました"）**: 原因はDriveフォルダ権限（「全社」グループがreaderのみ）。ユーザー個人にwriter権限を付与して解決。`rethrowWithHint_()`で今後同様の権限エラーが起きた際にヒントを表示するようにした。
2. **Sheetsの数値自動変換によるクライアント側クラッシュ**（`(s||"").replace is not a function`）: `str_`/`strOrNull_`と`esc()`のハードニングで解決。
3. **Slack通知が一度も届いていなかった**: 過去にハードコードされていたデフォルトWebhook URLが実在しない偽物だったことが判明（ユーザー本人も「心当たりがない」と証言）。ハードコードを削除し、`セットアップ手順.md`にIncoming Webhookの作成手順を追記。`testSlack()`関数を追加。ユーザー確認済みで解決。
4. **524件の履歴データが読み込まれない**: `setup()`内の「シートが空の時だけシードする」ロジックが、テストデータが既にあると永久にブロックされる仕組みだった。IDベースの冪等シード関数`seedHistoricalCases()`に置き換えて解決（何度実行しても重複しない）。
5. **`Data.gs`ファイルがApps Scriptプロジェクトに存在せずReferenceError**: ユーザーがファイルを追加し忘れていた。再送して追加してもらい解決。
6. **ダッシュボードの機種切り替えUI表示順**: 「機械番号 + 機種名」表示だった箇所（全7箇所）を「機種名 + 機械番号」に統一（ユーザー要望）。

### 5.3 今回のセッションで新規実装した機能

**A. PDF自動取込み機能（人の承認を挟む、1日1回自動実行）**
- `ingestNewReports()`: `SITE_FOLDERS`配下の各機械フォルダ内の未処理ActivityReport PDFを検出し、Gemini（`extractCaseFromReportPdf_`）にPDFを直接読ませて構造化データ（症状・原因・対処・エラーコード・部品など）をJSON抽出、`PendingCases`シートに`reviewStatus=pending`で追加。
- 429（クォータ超過）を検知したら即座にそのバッチを中断する`quotaExhausted`フラグあり（無駄な失敗の繰り返しを防ぐ）。503（一時的な高負荷）は同一モデルで1回リトライ。
- `promoteApprovedCases()`: `PendingCases`で`reviewStatus=approved`にした行だけを`Cases`へ反映し`promoted`に変更。
- `dailyReportSync()` = `ingestNewReports()` + `promoteApprovedCases()`。`installDailyReportSync()`で毎日深夜2時台の時間主導トリガーを冪等に設定。
- **既知の制限**: 無料枠のGemini APIキーだと1分間20リクエストなどのクォータに簡単に達する。ユーザーは複数回429エラーに遭遇済み。恒久対策としてはGoogle AI Studioで従量課金を有効化することを推奨済み（未実施の可能性あり、要確認）。

**B. 対応履歴とPDFの紐づけ機能**
- `CASE_HEADERS`に`reportUrl`・`quoteUrl`を追加（末尾。4節参照）。
- 症状登録フォーム（新規・編集共通）に「報告書PDFのURL」「見積書PDFのURL」の任意入力欄を追加。入力すると対応履歴カードに「📄 報告書PDFを開く」「🧾 見積書PDFを開く」リンクが表示される（共通カードレンダラーなので検索結果・ダッシュボード・モーダル全てに反映）。
- `ingestNewReports()`で取り込んだ対応履歴は、取り込んだPDFの`reportUrl`が自動セットされた状態で`PendingCases`→`Cases`に入る。
- **`linkReportsToDrive()`**: 既存524件などreportUrl未設定の対応履歴を、ActivityReportのファイル名先頭の日付（`YYYYMMDD`）と対応履歴の`date`・`m`（機種）を突き合わせて自動リンク。Sheetsが日付文字列を自動でDate型に変換する問題があり、`Utilities.formatDate`で正規化して解決済み。同じ機種・同日付が複数ある場合は曖昧としてスキップ（手動リンクへ誘導）。**実行結果: 139件成功、61件は曖昧でスキップ**（2026年9月時点、初回実行）。何度でも再実行可能（差分のみ処理）。
- **`linkQuotesToDrive()`**: 対応履歴の`quoteNo`（見積書番号、手入力）を手がかりに、①各機械フォルダ内の「見積」サブフォルダ（ファイル名=見積書番号、例:`est23102936.pdf`）→②見つからなければ「メーカー作業報告書見積り」フォルダを再帰的にファイル名部分一致で探索、の2段構えで`quoteUrl`を自動セット。`est_23285574`と`est23285574`のような表記ゆれは`normQuoteNo_()`（英数字以外を除去して小文字化）で吸収。**現状の課題**: 524件の履歴データに`quoteNo`が入力されているものがほぼ無く、この関数を実行しても紐づけ対象が見つからない状態（4節の実フォルダ構造発見を受けて、ファイル名からquoteNoを自動採取して対応履歴側にも自動入力する機能は「提案はしたが未実装」——次のセッションで着手するのに良い候補）。

## 6. 未着手・次にやると良いこと（優先度順の目安）

1. **見積PDFのファイル名から`quoteNo`を自動採取して対応履歴に逆入力する機能**（直前にユーザーへ提案し、返答待ちだった）。`見積`サブフォルダのファイル名は`estXXXXXXXX[_接尾辞].pdf`という規則性があるので、機械フォルダ単位でPDFを列挙→`quoteNo`未入力かつ該当機種のケースへ、日付以外の手がかり（対応日に近いPDFの更新日時、など）で紐づけるロジックの設計が必要。日付が無いため`linkReportsToDrive`ほど単純ではなく、要件を詰めてから実装するのが良い。
2. **`linkReportsToDrive`で曖昧判定された61件**の扱い（手動リンクを促す運用のままで良いか、UI上に「曖昧リスト」を出すなど改善するか）。
3. **Gemini APIクォータ問題**の恒久対応（課金プラン移行が済んだか要確認。済んでいれば`ingestNewReports`が安定して回るはず）。
4. **`index.html`内の"PILOT v0.2 — B1508I0077 / A0111A0024"というヘッダー文言**（旧バージョンの名残の固定テキスト。データ表示バグとは無関係と判明済みだが、実害が無いので放置中。気になるようならただの表示文言なので消すだけで良い）。
5. `ingestNewReports`のスコープ外である「メーカー作業報告書見積り」フォルダ（業者別整理）に置かれた報告書は、引き続き手入力が必要。将来的に自動化するなら業者名→機種の対応表を別途用意する必要がある。

## 7. Script Properties（Apps Script側で設定が必要な値）

- `GEMINI_API_KEY` — Google AI Studioで発行
- `SLACK_WEBHOOK_URL` — 自社Slackワークスペースで作成したIncoming Webhook（設定済み・動作確認済み）
- `SHEET_ID` / `PHOTOS_FOLDER_ID` — 通常は`setup()`実行時に自動セットされるため上書き不要

## 8. 動作確認・デバッグ時の心得（このセッションで得た教訓）

- Google SheetsはISO形式の日付文字列や全角数字文字列を自動的にDate型/Number型に変換して保存する。読み書きロジックを書くときは必ず`String()`ではなく`Utilities.formatDate(new Date(v), ...)`や明示的な型強制を使うこと（このバグで2回ハマった）。
- Apps Scriptエディタの実行ログは、関数が`Logger.log()`を呼ばずに`return`しただけだと何も表示されない（早期returnパスにログ漏れがあると「実行完了」しか出ず原因が分からなくなる）。全てのreturnパスでLogger.logを通すこと。
- ユーザーはDriveのフォルダ構造について、こちらの想定（過去の会話ログや命名規則からの推測）と実際が食い違っていることがある（見積フォルダの件）。自動化ロジックを書く前に、可能なら`Google_Drive`系ツールで実物のフォルダ構成を確認してから実装するとやり直しが減る。
