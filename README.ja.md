# univapay/univapay-sdk-compat

[English version here](README.md)

レガシーな手書き SDK である [`univapay/php-sdk`](https://github.com/univapay/univapay-php-sdk) の
公開 API 表面 —— 同じクラス名、同じメソッドシグネチャ、public プロパティ、enum の書き方、例外、
ポーリング挙動 —— をそのまま再実装し、新しく APIMatic により生成された
[`univapay/client-sdk`](https://github.com/univapay/univapay-client-php-sdk)（新エンジン）を
実行トランスポートとして利用するランタイム互換レイヤーです。
[`univapay/univapay-sdk-migrate`](https://github.com/univapay/univapay-php-sdk-migrate) によって
移行されたコードベースが、そのまま新エンジン上で動き続けることを目的としています。

## このパッケージが何であるか

`univapay/php-sdk` が今後アップデートされることはありません。新しい API 機能（v1.1 以降）はすべて
`univapay/client-sdk` にのみ追加されます。本パッケージは、既存の利用者が呼び出し箇所を書き換えることなく
新エンジンへ到達できるようにするものです —— 名前空間だけを入れ替える（`Univapay\*` →
`Univapay\Compat\*`）ドロップイン置き換えであり、コードがすでに使っているあらゆる構文（`Money\Money`
オブジェクト、`ChargeStatus::SUCCESSFUL()` の同一性比較、public プロパティへの直接アクセス、
`awaitResult()`・メソッドチェーン、`catch` ブロック）を以前とまったく同じように動かし続けます。
内部的には、compat の各メソッドは `univapay/client-sdk` に対して型付きのリクエストを構築し、その
エンジンの実際の HTTP トランスポート経由で送信します —— API と直接やり取りするコードはこのパッケージの
どこにもありません。レスポンスがどのように旧 SDK の形へ復元されるかについては、
[アーキテクチャ](#アーキテクチャ) を参照してください。

通常のケースでは、本パッケージを手動でインストールすることはありません。詳しくは
[インストール](#インストール) を参照してください。

## インストール

**通常の手順: 移行ツールを実行する。** `univapay/univapay-sdk-migrate` が本パッケージを要求し、
コードの import をこのパッケージに向けて書き換え、`univapay/php-sdk` を削除するところまでを、
たった一つのコマンドで行います。

```bash
composer require --dev univapay/univapay-sdk-migrate
vendor/bin/univapay-migrate
```

このコマンドが何を行うか、どのようなオプションがあるか、レポートの形式については
[`univapay-sdk-migrate` の README](https://github.com/univapay/univapay-php-sdk-migrate) を
参照してください。

**手動での手順。** 移行ツールを使わない場合 —— 意図的に旧 SDK の API 形状を対象とした新規実装や、
すでに手作業で書き換え済みのコードベースなど —— は、直接インストールしてください。

```bash
composer require univapay/univapay-sdk-compat
```

両パッケージは同時にインストール可能です —— autoload ルート（`Univapay\` と `Univapay\Compat\`）は
衝突せず、本パッケージが旧SDKのクラスを直接参照することもありません。`univapay-sdk-migrate` は
これを前提にしています: Rector が受け側の型解決のために旧SDKのクラスをまだ必要としている間、本
パッケージを require してから `univapay/php-sdk` を削除するため、一時的に両方が存在する瞬間があり
ます。移行が完了したら `univapay/php-sdk` は削除してください —— 残しておく理由はありませんが、
本パッケージと共存していても壊れることはありません。要件は
PHP `>=7.2`（`univapay/client-sdk` 自体の要件と一致）、`moneyphp/money` `^3.3 || ^4.0` です。

## サポート対象の一覧

旧 SDK が公開していたもののほとんどはそのまま動作します。ごく一部のメソッドはコンパイルは通り
呼び出し自体も可能ですが、実行時に `Errors\UnivapayUnsupportedFeatureError` を投げます。新エンジンに
呼び出し先となる対応 API が存在しないためです。

| 領域 | 状態 | 備考 |
|---|---|---|
| Charge、Refund、Cancel | サポート | 旧 SDK が行っていた「トークンを一度 GET してから作成する」2 段階のプリフライトを含め、ライフサイクル全体をサポート。 |
| Subscription、Scheduled Payment | サポート | |
| Transaction Token（card、コンビニ、オンラインウォレット、Paidy、銀行振込、QR） | サポート | |
| Store、Merchant、Configuration | サポート | `update()` を除く。詳細は後述。 |
| Transaction History | サポート | 読み取り専用: `GET /transaction_history`。 |
| Webhook パース（`parseWebhookData`） | サポート | そのまま引き継がれているコーナーケースについては [Webhook に関する注意事項](#webhook-に関する注意事項) を参照。 |
| **`Store::update()`、`Merchant::update()`** | **恒久的に例外を投げる** | この 2 つのリソースの更新エンドポイントは、旧 SDK にもバックエンドにも一度も存在したことがありません —— エンジンの制約ではなく、そもそも呼び出す先が存在しません。 |
| **`Transfer`、`TransferStatusChange`、`Ledger`**（および `GetTransfers`/`GetLedgers`/`GetStatusChanges` の Mixin） | **恒久的に例外を投げる** | 新エンジンには Transfers API 自体が一切存在しません —— コントローラーも一覧取得も個別取得もありません。`Transfer` の Webhook イベントは引き続きハイドレートされます（[Webhook に関する注意事項](#webhook-に関する注意事項) を参照）。それ以降そのオブジェクトに対して行う呼び出しはすべて例外を投げます。 |
| **`BankAccount`**（および `GetBankAccounts` の Mixin） | **恒久的に例外を投げる** | 新エンジンには Bank Accounts API 自体が一切存在しません —— コントローラーも一覧取得も個別取得も更新もありません。`Transfer` と異なり、旧 SDK の Webhook イベントも銀行口座のペイロードを運んだことは一度もないため、このクラスがまだ生きたチャンネルを持つわけではありません —— 他のすべての移植済みリソースとの整合性のためだけに、ハイドレート可能なデータクラスとして残しています。 |
| **`ApplePayPayment` のトークン作成** | **恒久的に例外を投げる** | 値オブジェクトの生成自体は可能ですが、そこからトークンを作成することはできません —— Apple Pay は新エンジンに組み込まれていません。 |
| **`Charge::qrMerchantToken()`** | **恒久的に例外を投げる** | このメソッドのみが対象で、`Charge` 自体は完全にサポートされています。対応するエンドポイント `/qr` はサーバー側ですでに非推奨で、MPM 用の QR データはトークンオブジェクト経由で取得する形に変わりました。 |

「恒久的に例外を投げる」とは機能凍結を意味します —— これらは今後の compat のリリースでもサポートされる
予定はありません。到達する手段はネイティブ SDK 経由のみです（[compat レイヤーからの移行](#compat-レイヤーからの移行)
を参照）。移行ツールは、これらに到達するすべての呼び出し箇所にフラグを付けるため、実行時に初めて
気づくのではなく、レビュー可能な形で目に見えるようになります。

## 挙動の差分

compat は旧 SDK の挙動をバイト単位でそのまま再現したものではありません —— 意図的かつ記録済みの
差分がわずかに存在します。

| 項目 | 挙動 |
|---|---|
| `listTransactions()` | `$from`/`$to` に対して null セーフです —— どちらか一方を省略しても（例: ステータスのみで絞り込む場合）致命的エラーになりません。 |
| カードトークンのハイドレーション | `billing`/`three_ds` は nullable です —— ワイヤー上に存在しない場合は `TypeError` ではなく `null` になります。 |
| CVV認証ステータス | バックエンドの `error` ステータス値に対応する `CvvAuthorizationStatus::ERROR()` が存在します。 |
| `CheckoutInfo` | `supportedCurrencies` は nullable です —— サーバーが省略した場合は致命的エラーではなく `null` になります。 |
| 銀行振込の issuer token | `call_method` はオプション扱いです —— ペイロードに存在しない場合は `null` になります（他の決済方法には影響しません）。 |
| Paidyトークン | `phone_number` は `{country_code, local_number}` というネスト構造ではなく、単純な文字列としてハイドレートされます。 |

これ以外にも、意図的な差分がいくつかあります。

- **`UnivapayNetworkError` が旧 `WpOrg\Requests\Exception` によるリトライ対象を置き換えます。**
  純粋な通信障害（DNS 失敗、接続拒否、レスポンスを受け取る前のタイムアウトなど）は、新エンジンからは
  `getCode() === 0` を伴う `ApiException` として表面化します。旧 SDK の `NetworkRetryHandler` は
  `WpOrg\Requests\Exception` にマッチさせていましたが、このトランスポートではそのクラスは一切
  出現しないため、このリトライ経路は静かに機能しなくなっていました。compat の
  `NetworkRetryHandler` は代わりに `Errors\UnivapayNetworkError` を対象としており、
  `Support\ExceptionMapper` がこのケース専用にこの例外を送出します（通信障害を 5xx エラーと
  誤ってラベル付けしてしまう `UnivapayServerError` ではありません）。移行ツールは、利用者のコードが
  引き続き `WpOrg\Requests\Exception` を catch している箇所を手動レビュー用にフラグ付けします。
- **10秒のタイムアウト。利用者がこれまで経験してきた挙動と一致します。** 旧 SDK のトランスポート
  （`rmccue/requests`）は既定で 10 秒のタイムアウトを持ち、これを設定可能な項目として公開したことは
  ありませんでした。新エンジン自体の既定値は 30 秒ですが、`Support\Bridge` がこれを 10 秒に固定して
  いるため、このタイムアウト値に依存していたコードの挙動は変わりません。
- **リトライ間でも安全なべき等性。** 旧 SDK は 1 回の論理的な呼び出しにつき 1 つのべき等キーを生成し、
  その呼び出し内のすべてのリトライで再利用していました。新エンジンの `IdempotencyCallback` は既定で
  HTTP リクエストごとに新しいキーを生成します —— 既定のリトライカスケード（レートリミット + ネットワーク
  リトライ、最大 4 回の試行）と組み合わさると、修正前は「タイムアウトしたが実際には処理済み」の
  `POST /charges` が最大 4 件の実際の課金を生み出す可能性がありました。`Support\ApiCaller` は
  リトライループの外側で 1 回の論理的な呼び出しにつき 1 つのキーを生成し、すべての試行でそれを明示的に
  渡します —— 旧 SDK が行っていたことと同じです。
- **エラーのマッピングは catch した例外ではなく `ApiResponse::isError()` を経由します。** 生成された
  すべての API メソッドのレスポンスハンドラーは、エラーレスポンスを例外として投げるのではなく
  「返す」ように構成されています —— `univapay/client-sdk` からの 4xx/5xx は、例外ではなく
  `isError()` が `true` になる、通常どおり値を返す `ApiResponse` として返ってきます。
  `Support\ApiCaller` はすべての呼び出しでこれを確認し、`Support\ExceptionMapper` を介して旧 SDK が
  公開していたものと同じ `Errors\*` 階層（`UnivapayNotFoundError`、`UnivapayRequestError` など）へ
  マッピングします —— 404 レスポンスにはデコード済みのエラーボディが含まれない（400/401/403 のみ
  含まれる）という旧 SDK 独自の癖も含めてです。実際に例外として投げられる唯一のケースは、HTTP
  レスポンスそのものを一切受け取れなかった純粋な通信障害であり、これは上記の `UnivapayNetworkError`
  へ個別にマッピングされます。この仕組み全体とそれが重要である理由については `docs/ARCHITECTURE.md`
  を参照してください。

## Webhook に関する注意事項

`UnivapayClient::parseWebhookData()` は、旧 SDK のディスパッチとそのコーナーケースをそのまま
再現しています —— 一見バグに見えるものも含め、意図的に固定された挙動です。

- **Transfer イベントはハイドレートされますが、`Transfer` に関するそれ以外の操作はすべて例外を
  投げ続けます。** `transfer_created`/`transfer_updated`/`transfer_finalized` の Webhook
  ペイロードは、`Transfer` 自体が直接の API アクセスとしては未サポートである（上記の
  [サポート対象の一覧](#サポート対象の一覧) を参照）にもかかわらず、実際の `Resources\Transfer`
  オブジェクトとしてハイドレートされます —— この Webhook チャネルは、このトランスポートエンジンが
  Transfers API を公開しているかどうかとは無関係にデータを配信し続けます。そのオブジェクトに対して
  行うそれ以降の呼び出し —— `fetch()`、`update()`、`listLedgers()`、`listStatusChanges()` ——
  はすべて `UnivapayUnsupportedFeatureError` を投げます。ペイロードをパースできることと、その
  リソースがサポート対象になることは別問題です。
- **現在の 3 つのトークンイベントタイプには compat 側の enum ケースが存在しません。** 実際の API の
  `TokenEvent` ディスクリミネーターには、現在 `token_three_d_s_updated`、
  `token_cvv_auth_check_updated`、`token_replaced` が含まれていますが、これらは旧 SDK の
  `Enums\WebhookEvent` が最後に更新された後に追加されたものであるため、compat 側にポートされた
  この enum にもこの 3 つは存在しません（現在の仕様ではなく旧 SDK をそのまま反映しているため）。
  これら 3 つのいずれかのイベントタイプを含む Webhook 配信を受け取ると、他の未知の `event` 文字列と
  同様に `Errors\UnivapayUnknownWebhookEvent` が発生します。これらのイベントが必要な場合は、
  `parseWebhookData()` ではなく `native()`（後述）経由で処理してください。
- **マーチャントレベルのアプリトークンが store スコープのイベントを受け取ると、より明確なエラーでは
  なく `UnivapayInvalidWebhookData` になります。** `TOKEN_*`、`REFUND_FINISHED`、
  `CANCEL_FINISHED` の各イベントは、旧 SDK のコンテキスト参照と同様、store スコープの JWT を
  要求します。このガードは `UnivapayInvalidWebhookData` へ集約する広い `catch` と同じ `try`
  ブロック内で発火するため、マーチャント JWT のクライアントが目にするのはより具体的な
  「トークン種別が違う」エラーではなく、これになります。これは整理・修正されたものではなく、
  そのまま再現されたものです —— 旧 SDK 自体のこの挙動こそ、既存の連携がすでに前提としてコードを
  書いてきたものだからです。
- **`customs_declaration_finished` には enum ケースがありますが、パーサーは存在せず、これも
  `UnivapayInvalidWebhookData` にマッピングされます。** このイベントタイプ自体は認識されます
  （`UnivapayUnknownWebhookEvent` にはなりません）が、旧 SDK にもここにも、これをハイドレートする
  ためのリソース型はこれまで一度も存在していません。

## アーキテクチャ

リクエストは `univapay/client-sdk` 自身が生成した型付きモデルに対して型付きで構築されます ——
コードが設定するすべてのフィールドは、ネイティブ SDK が使うのと同じバリデーションとシリアライズを
経由します。一方でレスポンスは、生成された SDK の型付きレスポンスモデルではなく、キャプチャした
生のワイヤーボディを旧 SDK 自身のポート済み JSON スキーマパーサーでハイドレートします ——
これは、旧 SDK の実績あるパーサーがすでに処理できていた形状（現在の仕様がまだ記述していない形状を
含む）とワイヤー単位で完全に一致させるための、唯一実現可能な方法でした。リクエスト/レスポンスの
全体図、生のボディへのアクセスをレビュー済みの許可リストに閉じ込めている境界、そして GA 後の方針
（型付き優先のハイドレーションへの移行。生のパスは恒久的なフォールバックとして残ります）については
[`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) を参照してください。

## compat レイヤーからの移行

compat は永続的な着地点として設計されているわけではありません。`UnivapayClient::native()` は、
このクライアントが自身の呼び出しのために内部で構築済みの `UnivaPay\UnivapayClientSdkClient`
インスタンスそのものを返します —— 認証情報、ベース URL、10 秒のタイムアウトも含めて compat 側と
まったく同じであり、別途独立して構成された 2 つ目のクライアントではありません。

```php
$client = new UnivapayClient($storeAppToken);

// compat 側の API -- 変更なし。
$charge = $client->createToken($paymentMethod)->createCharge(Money::USD(1000))->awaitResult();

// ネイティブ側の API -- 同じエンジン、同じ認証情報、同じ接続設定。
$native = $client->native();
$chargesApi = $native->getChargesApi();
```

これにより **混在モード** が可能になります。呼び出し箇所を 1 つずつ、`native()` の型付き API へ
書き換えながら移行しつつ、まだ手を付けていない箇所は引き続きそのまま compat のファサードを呼び出す、
という進め方です。両方の経路は同じエンジンを共有しているため、移行期間中に両者の間でずれが生じる
ことはありません —— `native()` 経由で作成した課金は、compat 経由でそれを読むコードからも見えますし、
その逆も同様です。

`Money` → `int` + 通貨文字列、`ChargeStatus::SUCCESSFUL()` の同一性比較 → 文字列定数、
`$charge->status` → 型付き getter、`awaitResult()` → `pollCharge()`、ページネーションされた
リスト → カーソルパラメータ、`parseWebhookData()` → 型付き Webhook ハンドラークラス、など
コンストラクト単位での移行リファレンス（それぞれ before/after のコード例つき）は、ポータルガイドの
[Phase 2 セクション](https://univapay.com/docs/#/http/onboarding-guides/guides/php-sdk-migration#migrating-further-to-the-native-sdk)
にまとめてあり、ここでは重複させていません。

## バージョニングとサンセットポリシー

compat は 1.0 時点で機能凍結されています —— 新しい API 機能はすべて `univapay/client-sdk` にのみ
追加され、`native()` 経由でのみ到達できます。compat 自体は、旧 SDK の API 表面に依存する利用者が
存在する限り、バグ修正とエンジン SDK 自身のバージョンアップへの追従を継続して受け取ります ——
強制的な終了時期は設けていません。この非対称性（旧来の API 表面はそのまま変わらず、新機能は
`native()` の先にしか存在しない）こそが、崖のような強制ではなく、段階的に移行を促すための
意図された仕組みです。

## ライセンス

MIT。
