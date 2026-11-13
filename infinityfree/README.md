# InfinityFree PHP/MySQL POS

InfinityFree無料ホスティング向けのPHP/MySQL版です。FastAPI/Docker版とは別実装で、InfinityFree上ではPHPから同一ホスト内のMySQLへ接続します。

## GitHub Actions / FTP設定

リポジトリのSettings → Secrets and variables → Actionsに次を登録します。

- Repository secrets: `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD`
- Repository variable（任意）: `FTP_SERVER_DIR`（初期値 `htdocs/`）

`.github/workflows/deploy-infinityfree.yml` は明示トリガー専用です。通常のpushではデプロイされません。mainを変更せずにデプロイする場合は、開発ブランチへのコミットメッセージに `[deploy]` を含めた場合のみFTP配置します。`workflow_dispatch` はworkflowファイルが既定ブランチに存在する場合にGitHub UIから実行できます。FTP配置は `production` environment の承認設定がある場合、その承認後に進みます。

## 初回セットアップ（本番配置前）

1. InfinityFree Control PanelでMySQLデータベースを作成します。
2. phpMyAdminから `infinityfree/database/schema.sql` を新規DBにインポートします。既存DBへ無確認でインポートしないでください。
3. `infinityfree/config.example.php` を元に、サーバー上の `htdocs/config.php` を作成します。DBホスト・DB名・DBユーザー・DBパスワードを入力し、`app_secret` と `setup_token` はそれぞれ64文字以上の別々のランダム文字列にします。config.phpはGitHubへ登録せず、FTPでサーバーへ直接配置します。
4. アプリファイルを `htdocs/` に配置します。`config.php` はFTP同期で上書き・削除されない構成です。
5. HTTPSで `https://あなたのドメイン/health.php` を開き、`database=connected` 相当の応答と `schema_incomplete` でないことを確認します。
6. `https://あなたのドメイン/setup.php` を開き、config.phpに設定した初回設定キー、店舗コード、店舗名、管理者ID、表示名、12文字以上のパスワードを入力します。初回店舗は一度しか作成できません。
7. `https://あなたのドメイン/index.php` からログインします。初回登録後、setup.phpはDBに店舗が存在することを検知して再利用を拒否します。
8. テスト用の卓・商品・セット・キャストを登録し、注文、割引、会計、分割入金、返金、勤怠、給与、日締め、売上集計を確認します。テストデータを削除してから営業データを登録します。

## 注意事項・運用保護

- FTP workflowはアプリファイルのみを配置します。サーバー上の `config.php` とDBを削除・上書きしません。同期削除は無効です。
- `database/schema.sql`、`config.example.php`、`bootstrap_owner.php`、tests、docsは公開領域へ配置しません。
- 初回登録キーは強いランダム値を使い、初回登録が完了したらconfig.phpから `setup_token` を削除または無効化してください。
- DBバックアップはInfinityFreeのphpMyAdmin等から取得してください。FTP配置だけではDBバックアップは作成されません。
- `health.php` はDB接続と必須テーブルの有無だけを返し、接続資格情報や例外内容は公開しません。
- 実店舗DBへのスキーマ適用前には必ずバックアップし、初回は空のDBを使ってください。既存データの自動マイグレーション機能ではありません。
- 給与計算は暫定機能です。法定割増、税、源泉徴収、社会保険等の適法性を保証しません。
- 実決済端末への決済・返金実行は外部決済サービス側の操作です。
