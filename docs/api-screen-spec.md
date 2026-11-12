# API・画面詳細設計（初期版）

## 共通
- JSON API、UTF-8、HTTPS必須。
- 認証はセッションまたは短命アクセストークン。パスワードはArgon2idまたはbcryptでハッシュ化。
- 店舗IDは認証コンテキストから解決し、リクエスト本文の値だけで店舗を切り替えない。
- 作成・更新APIは Idempotency-Key を必須とする。
- 金額は整数の最小通貨単位またはDECIMAL文字列で送受信し、JavaScript浮動小数点の誤差を避ける。
- エラー形式: { "error": { "code": "VALIDATION_ERROR", "message": "...", "details": [] } }

## 主要API
| Method | Path | 用途 | 権限 |
|---|---|---|---|
| POST | /api/auth/login | ログイン | 公開 |
| GET | /api/tables | 卓一覧 | staff以上 |
| POST | /api/sessions | 着席開始 | staff以上 |
| GET | /api/sessions/{id} | 卓詳細 | staff以上 |
| POST | /api/sessions/{id}/sets | セット・延長追加 | staff以上 |
| POST | /api/orders | 注文作成 | staff以上 |
| POST | /api/orders/{id}/items | 注文明細追加 | staff以上 |
| POST | /api/order-items/{id}/back-allocations | バック配分 | staff以上 |
| POST | /api/sessions/{id}/checkout | 会計確定 | staff以上 |
| POST | /api/payments | 支払登録 | staff以上 |
| POST | /api/attendance/clock-in | 出勤打刻 | self/manager |
| POST | /api/attendance/clock-out | 退勤打刻 | self/manager |
| GET | /api/casts/{id}/payroll | 給与明細 | self/manager |
| GET | /api/closing/{date} | 締め状況 | manager以上 |
| POST | /api/closing/{date}/run | 締め再集計 | system/manager |
| GET | /api/reports/sales | 売上分析 | manager以上 |

## バック配分API
Request:
{ "allocations": [ { "cast_id": 123, "allocation_rate": "60.0000" }, { "cast_id": 456, "allocation_rate": "40.0000" } ] }
- 合計率は0〜100%。
- 商品明細に紐付くキャストは同一店舗に所属していること。
- 配分バック合計が明細のバック総額を超えないこと。
- 注文確定後の変更は権限確認と監査ログを必須とする。

## 画面要件
### iPad
- 卓カードは状態・経過時間・延長期限・会計待ちを視認できる。
- 卓詳細から注文、延長、担当キャスト、会計へ移動できる。
- 注文時は商品行ごとにバック付与先を選択できる。未指定も許可し、バック0として扱う。
- 会計画面に小計・割引・税・サービス料・合計・支払残額を表示する。
### Smartphone
- キャスト本人は自分の打刻・勤務時間・バック・給与だけを閲覧できる。
- 店舗スタッフの注文権限はロールにより制御する。
### PC
- マスター管理、キャスト時給履歴、売上集計、給与確認、日締め監視、経費、監査ログ。
- 日締めのblocked理由と対象データへのリンクを表示する。

## 自動日締め
- スケジューラは店舗ごとの営業日開始・締め時刻を使用する。
- 同一店舗・営業日の締めをDBロックして一度だけ実行する。
- 全チェック成功時のみ closed。エラー時は blocked として理由を記録する。
- 退勤漏れや未会計を自動補完して閉めない。
- 締め後の訂正は調整レコードと監査ログを作成する。


## 注文登録API 実装済み範囲
`POST /api/orders`

Request例:
```json
{
  "session_id": 10,
  "idempotency_key": "device-a-unique-order-key",
  "items": [
    {
      "product_id": 21,
      "quantity": "2",
      "allocations": [
        {"cast_id": 3, "allocation_rate": "60"},
        {"cast_id": 8, "allocation_rate": "40"}
      ]
    }
  ]
}
```

- バック配分率は商品バック総額に対する割合。
- 配分率合計100%以下。残りは店舗取り分。
- 商品名・単価・バック率・方式は商品マスターから読み込み、注文明細に固定保存。
- 注文ヘッダー・明細・バック配分は1トランザクションで登録。
- 同じ店舗・同じ冪等性キーの再送は既存注文を返す。
- セッションが営業中でない、商品・キャストが同一店舗に存在しない場合は登録を拒否。
- 会計済み注文の取消・変更は未実装。運用前に必ず追加する。
