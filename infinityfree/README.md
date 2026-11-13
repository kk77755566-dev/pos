# InfinityFree PHP/MySQL POS

InfinityFree無料ホスティング向けのPHP/MySQL版です。FastAPI/Docker版とは別実装で、InfinityFree上ではPHPから同一ホスト内のMySQLへ接続します。

## GitHub Actions / FTP設定

リポジトリのSettings → Secrets and variables → Actionsに次を登録します。

- Repository secrets: `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD`
- Repository variable（任意）: `FTP_SERVER_DIR`（初期値 `htdocs/`）

`.github/workflows/deploy-infinityfree.yml` は明示トリガー専用です。通常のpushではデプロイされません。mainを変更せずにデプロイする場合は、開発ブランチへのコミットメッセージに `[deploy]` を含めた場合のみFTP配置します。FTP配置は `production` environment の承認設定がある場合、その承認後に進みます。

## 初回セットアップ

1. InfinityFree Control Panelで新しいMySQLデータベースを作成します。**既存データがあるDBは使用しないでください。**
2. `infinityfree/config.example.php` を元に、サーバー上の `htdocs/config.php` を作成します。DBホスト・DB名・DBユーザー・DBパスワードを入力し、`app_secret` と `setup_token` はそれぞれ64文字以上の別々のランダム文字列にします。config.phpはGitHubへ登録せず、FTPでサーバーへ直接配置します。
3. アプリをFTP配置します。デプロイには `database/schema.sql` とアクセス拒否用 `database/.htaccess` も含まれます。SQLファイルはWebから直接取得できません。
4. HTTPSで `https://あなたのドメイン/health.php` を開き、DB接続を確認します。初回は `schema_incomplete` が返る場合があります。
5. HTTPSで `https://あなたのドメイン/setup.php` を開き、初回設定キー、店舗コード、店舗名、管理者ID、表示名、12文字以上のパスワードを入力します。**空のDBに限り、setup.phpが同梱スキーマを自動作成し、店舗と管理者を一度だけ登録します。** 部分的または既存テーブルのあるDBにはスキーマを適用せず、安全に停止します。
6. `health.php` が `status=ready` を返すことを確認し、`index.php` からログインします。初回登録後、setup.phpは再利用できません。初回設定が終わったらconfig.phpから `setup_token` を削除または無効化してください。
7. テスト用の卓・商品・セット・キャストを登録し、注文、割引、会計、分割入金、返金、勤怠、給与、日締め、売上集計を確認します。テストデータを削除してから営業データを登録します。

## 注意事項・運用保護

- FTP workflowはアプリファイルと初回DB構築用SQLのみを配置します。サーバー上の `config.php` とDBを削除・上書きしません。同期削除は無効です。
- `database/.htaccess` とルートのアクセス制御によりSQLファイルへのHTTPアクセスを拒否します。tests、docs、設定テンプレートは公開領域へ配置しません。
- DBバックアップはInfinityFreeのphpMyAdmin等から定期取得してください。FTP配置だけではDBバックアップは作成されません。復元テストも実施してください。
- `health.php` はDB接続と全必須テーブルの有無だけを返し、接続資格情報や例外内容は公開しません。
- 自動スキーマ作成は空DB専用です。既存DBの自動マイグレーション機能ではありません。
- 給与計算は暫定機能です。法定割増、税、源泉徴収、社会保険等の適法性を保証しません。
- 実決済端末への決済・返金実行は外部決済サービス側の操作です。
