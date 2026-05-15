> Languages: [English](SPEC.md) | **日本語**

# PHPVerse 仕様書

ステータス: **draft v1**。本書は実装エージェント向けの PHPVerse 正規仕様である。

## 1. スコープ

PHPVerse は、以下を満たす PHP のワーカープールライブラリ + スタンドアロン daemon である:

1. 長寿命の子プロセス (ワーカー) のプールを管理する。各ワーカーは PHP インタプリタをホストする。
2. クライアント (親 / web サーバ) とワーカーの間で FastCGI 1.1 をワイヤ上で喋る。
3. ワーカーの履歴によらず、ユーザ PHP スクリプトがクリーンな環境を見られるよう、リクエスト単位の分離 contract を提供する。

本書は次を扱う: アーキテクチャ、ワイヤプロトコルプロファイル、分離 contract、ワーカーライフサイクル、CLI / 設定、実装 TODO リスト。

### 1.1 命名規約

| 識別子 | 値 | 出現箇所 |
|---|---|---|
| PHP 名前空間 | `phpverse\` (`baykit\` プレフィックスは付けない) | `src/Worker.php` → `namespace phpverse;`、`src/Protocol/FcgiCodec.php` → `namespace phpverse\Protocol;` 等 |
| Composer パッケージ名 | `baykit/phpverse` | `composer.json` の `"name"` フィールド |
| Composer autoload PSR-4 マッピング | `"phpverse\\": "src/"` | `composer.json` の `"autoload"` フィールド |
| GitHub リポジトリ | `baykit/PHPVerse` | <https://github.com/baykit/PHPVerse> |
| 非 PHP クライアントライブラリの source-package パス | `baykit.phpverse.*` | 例: Java クライアントクラス `baykit.phpverse.client.PhpverseClient`、Python モジュール `baykit.phpverse.client` など。既存の baykit ファミリの命名と一致。 |

PHP 名前空間は意図的に `baykit\` プレフィックスを**含めない**。PHPVerse はそれ自体が独立したプロダクトであり、PHP 名前空間は短くプロダクト名を冠したものに保つ。非 PHP クライアントライブラリは、ホスト側の慣習 (Java パッケージ、Python モジュールパス等) が通常 organization 名前空間を含むため、より長い `baykit.phpverse.*` 形式を採用する。

## 2. ゴールと non-goals

### ゴール

- **プロセス分離されたワーカー**。ユーザ PHP の fatal error / segfault / `exit()` はちょうど 1 ワーカーを殺すだけで、他のワーカーや親プロセスには影響しない。
- **標準的な FCGI ワイヤ**。任意の FCGI クライアントが無改造で動く。
- **ライブラリ + daemon**。同一コードベースで両モードに対応:
  - ライブラリモード: 親アプリケーションが `ForkedWorker::spawn()` を呼び、継承された Unix domain socket 経由で FCGI を喋る。
  - daemon モード: `phpverse --workers N --listen ...` が php-fpm に似たプロセスとして動作し、任意のクライアントから FCGI を受け付ける。
- **PHP CLI バイナリ + `pcntl` / `posix` / `sockets` 拡張だけで動く** (外部依存ゼロ)。
- **ドキュメント化されたリクエスト単位 reset contract**。ワーカー内でリクエスト間に何がクリアされ何が永続するかをユーザが当てにできる。

### Non-goals (v1)

- 1 ワーカー接続内の HTTP/2 風のストリーム多重化。PHPVerse は常に FCGI request_id = 1 を用い、1 接続あたり 1 リクエストずつ処理する。並列性はワーカー数で稼ぐ。
- 協調的非同期 (Fiber、ReactPHP、Swoole)。ワーカーはユーザ PHP を同期的に完了まで実行する。
- ワーカー再起動なしの PHP コード hot reload。ユーザは opcache に `opcache.validate_timestamps=1` を設定するか、`maxRequestsPerWorker` でワーカーをローテーションする。
- 既に動いている本番 php-fpm デプロイの置き換え。

## 3. アーキテクチャ

### 3.1 プロセスモデル

```
┌──────────────────────────────────────────────────────────┐
│ Parent (web server / agent / phpverse daemon)            │
│                                                            │
│   1. bind() Unix domain socket on /tmp/phpverse-{...}.sock │
│   2. listen()                                              │
│   3. for i in 0..N-1:                                      │
│        fork() + exec(worker.php)                           │
│        ↑ each child connects to parent's listen socket    │
└──────────────────────────────────────────────────────────┘
                       │
                       │ Unix domain socket per worker
                       │ (FCGI 1.1 wire format)
                       ▼
┌──────────────────────────────────────────────────────────┐
│ Worker (PHP CLI process)                                   │
│                                                            │
│   1. connect() to parent's socket using env var           │
│      PHPVERSE_SOCKET=/tmp/phpverse-{...}.sock              │
│   2. loop forever:                                         │
│        read FCGI_BEGIN_REQUEST                             │
│        read FCGI_PARAMS records until empty params record │
│        read FCGI_STDIN records until empty stdin record   │
│        run PHP script (require)                            │
│        emit FCGI_STDOUT records (response headers + body) │
│        emit FCGI_STDERR records if any                     │
│        emit FCGI_END_REQUEST                               │
└──────────────────────────────────────────────────────────┘
```

### 3.2 接続の所有権

- **ライブラリモード**: 親が listen socket を所有する。fork 後、子は env var `PHPVERSE_SOCKET` (パス) もしくは `PHPVERSE_FD` (fd-passing を使う場合の数値 fd) 経由で listen fd を継承する。子が connect する。
- **daemon モード**: PHPVerse daemon が listen socket を所有する。外部クライアントが connect してくる。daemon は accept した接続をアイドルワーカーに振り分ける — 典型的には、accept した fd を制御ソケット経由でワーカーに渡す (`SCM_RIGHTS` fd passing)、もしくは共有 listening socket に対してワーカー自身が `accept()` する (FCGI の multiplexed-accept パターン)。

v1 では daemon モードで **「共有 listening socket、各ワーカーが accept する」** パターンを推奨する (これは php-fpm がやっていることそのもの)。ライブラリモードでは、よりシンプルな **「ワーカーごとに 1 socket」** パターンで十分機能する。

### 3.3 並行モデル

- 1 ワーカー = in-flight 1 リクエスト。クライアントとワーカー間の接続はリクエスト期間中保持され、後続のリクエストでも再利用される (`FCGI_BEGIN_REQUEST.flags = FCGI_KEEP_CONN`)。
- 並列性は複数ワーカーを並行動作させることで得る (`--workers N` または `Pool::start(n)` で設定)。
- ワーカー間で状態を直接共有しない。任意の共有手段:
  - opcache 共有メモリ (`opcache.file_cache` や `opcache.preload` を設定)
  - APCu (v1 では各ワーカーが自分の APCu を持ち、ワーカー間共有は無い)
  - ファイルシステム / データベース (ユーザの選択)

### 3.4 デプロイパターン

PHPVerse は **daemon モード** (`phpverse` バイナリが supervise) と **ライブラリモード** (ホストアプリケーションが直接 supervise) の両方をサポートする。各モードに per-agent / global のパーティション分割バリアントがある。詳細なパターン行列、選定ルール、ホスト固有の推奨は本 contract の一部ではない。プロトコル + 分離セマンティクスにフォーカスする本 SPEC から外し、[TIPS.ja.md](TIPS.ja.md) に置く。

SPEC が保証するのは、**ワイヤプロトコル (§4) と分離 contract (§5) は両モード・全バリアントで同一である**ということ。ワーカーは自分の親が `phpverse` daemon なのかホストアプリケーションなのか区別できない。クライアントは保持している FCGI 接続が daemon 管理ワーカーかホスト管理ワーカーか区別できない。

## 4. ワイヤプロトコル

PHPVerse は <https://fastcgi-archives.github.io/FastCGI_Specification.html> で規定される **FastCGI 1.1** を使う。

### 4.1 準拠レベル

- ロール: **RESPONDER のみ**。Authorizer / Filter ロールは `FCGI_END_REQUEST.protocolStatus` に `FCGI_UNKNOWN_ROLE` を返す。
- リクエスト多重化: 非対応。`FCGI_GET_VALUES_RESULT.FCGI_MPXS_CONNS` の値は `"0"`。クライアントは 1 接続あたり 1 つの論理リクエストを同時に扱うべき。パイプライニング (前の `FCGI_END_REQUEST` を読む前に次の `FCGI_BEGIN_REQUEST` を発行する) は `FCGI_CANT_MPX_CONN` で拒否する。
- 接続: `FCGI_KEEP_CONN` を尊重する。これが無い場合、ワーカーは `FCGI_END_REQUEST` 後に接続を閉じる。
- サポートするレコードタイプ (ワーカーが送出・受理する):

| レコードタイプ           | コード | 方向     | 備考                                            |
|------------------------|-----:|----------|-------------------------------------------------|
| FCGI_BEGIN_REQUEST     | 1    | C → W    | role は RESPONDER (1) でなければならない        |
| FCGI_ABORT_REQUEST     | 2    | C → W    | ワーカーは現在のリクエストを終了する             |
| FCGI_END_REQUEST       | 3    | W → C    | 応答の末尾で送信                                  |
| FCGI_PARAMS            | 4    | C → W    | 環境変数。空レコードで終端                        |
| FCGI_STDIN             | 5    | C → W    | リクエストボディ。空レコードで終端                |
| FCGI_STDOUT            | 6    | W → C    | 応答ヘッダ + ボディ                              |
| FCGI_STDERR            | 7    | W → C    | ワーカーログ / ユーザ error_log の出力           |
| FCGI_DATA              | 8    | -        | 未使用 (FILTER ロール専用)                        |
| FCGI_GET_VALUES        | 9    | C → W    | management レコード                              |
| FCGI_GET_VALUES_RESULT | 10   | W → C    | management レコードの応答                         |
| FCGI_UNKNOWN_TYPE      | 11   | W → C    | 未知の management タイプに対する応答              |

### 4.2 必須の FCGI_PARAMS キー

ワーカーは以下の標準 CGI/1.1 キーを必ず処理しなければならない (`$_SERVER` を埋めるのに使う):

```
REQUEST_METHOD       e.g. "GET", "POST"
REQUEST_URI          e.g. "/path?q=1"
QUERY_STRING         e.g. "q=1"
SCRIPT_NAME          e.g. "/index.php"
SCRIPT_FILENAME      absolute path; this is the file the worker requires
PATH_INFO            e.g. "/extra" (optional)
CONTENT_LENGTH       request body length (optional)
CONTENT_TYPE         request Content-Type (optional)
SERVER_NAME          host header value
SERVER_PORT          e.g. "8080"
SERVER_PROTOCOL      e.g. "HTTP/1.1"
SERVER_SOFTWARE      e.g. "PHPVerse/1.0"
REMOTE_ADDR          peer IP address
REMOTE_PORT          peer port (optional)
HTTPS                "on" if TLS, absent otherwise

HTTP_*               all request headers, with `-` → `_` and uppercased
                     e.g. "User-Agent" → HTTP_USER_AGENT
```

その他のキーはそのまま `$_SERVER` に渡される。

### 4.3 FCGI_STDOUT 形式 (応答)

`FCGI_STDOUT` の先頭バイトは HTTP ヘッダ風のテキスト:

```
Status: 200 OK\r\n
Content-Type: text/html; charset=utf-8\r\n
X-Custom: ...\r\n
\r\n
<html>...</html>
```

空行 `\r\n\r\n` でヘッダとボディが区切られる。ユーザが `http_response_code()` や `header("HTTP/1.1 ...")` でステータスを設定した場合はヘッダに `Status:` を含めること。なければクライアントは 200 を仮定する。

ボディのバイト列は複数の FCGI_STDOUT レコードに分割されてもよい。

### 4.4 FCGI_END_REQUEST

応答が完了したあと (ユーザスクリプトが return し、出力バッファが flush されたあと) に送信する:

- `appStatus`: 通常は 0。スクリプトがエラーコードで exit した場合 (例: `exit(1)`) は非ゼロ。
- `protocolStatus`: 通常は `FCGI_REQUEST_COMPLETE` (0)。

### 4.5 FCGI_GET_VALUES_RESULT

`FCGI_GET_VALUES` への応答として、ワーカーは以下を返す:

| キー              | 値                                                     |
|-------------------|--------------------------------------------------------|
| FCGI_MAX_CONNS    | "1" (ワーカープロセスあたり 1 接続)                     |
| FCGI_MAX_REQS     | "1" (多重化なし)                                        |
| FCGI_MPXS_CONNS   | "0"                                                    |
| PHPVERSE_VERSION  | 実装バージョン文字列 (PHPVerse 拡張)                    |

## 5. ワーカー分離 contract

本章は、リクエスト単位の分離についてユーザ PHP が依拠してよい仕様を定義する。

### 5.1 リクエスト間の reset

以下は各リクエスト開始時にクリーンな状態に **reset される**:

- スーパグローバル: `$_SERVER`、`$_GET`、`$_POST`、`$_COOKIE`、`$_REQUEST`、`$_FILES`、`$_ENV`、`$_SESSION` (ユーザ PHP が session を開始した場合)。すべて来訪 FCGI_PARAMS / FCGI_STDIN から再代入される。
- 出力バッファ: FCGI_STDOUT レコードに書き出すコールバックを伴う、新規 `ob_start()` がインストールされる。前リクエストのユーザインストール出力バッファは破棄される。
- 送信済みヘッダ: `header_remove()` が呼ばれる。各リクエスト開始時に `headers_sent()` は false を返す。
- エラー状態: `error_get_last()` がクリアされる。
- カレントワーキングディレクトリ: ワーカー起動時のディレクトリに復元される (ワーカーは `require` の前にスクリプトのディレクトリへ `chdir()` し、終了時に復元する)。
- 時間制限: `set_time_limit(default)` が呼ばれる。

### 5.2 リクエスト間で reset されないもの

以下はワーカー内でリクエストを跨いで **永続する** (これが warm process の利得である):

- opcache の状態 (コンパイル済みバイトコード)。
- ロード済みのクラス、インタフェース、トレイト、関数 (autoloader キャッシュ)。
- `define()` で定義された定数。
- 静的クラスプロパティ、メソッドローカルの static。
- `global` キーワードを使わずに宣言したグローバル変数 (= 文字通り `$GLOBALS` テーブルにあるもの)。
- ユーザ PHP が明示的にクローズしなかった open file descriptor、データベース接続等。

**含意**: static / global state を mutate するユーザコードは、リクエスト単位のクリーンアップを自分で行うか、リークを受け入れる責任を持つ。メモリ増加を制限するには `maxRequestsPerWorker` を設定して N リクエスト後にワーカーをリタイアさせる。

### 5.3 fatal error の処理

ユーザスクリプトの fatal error (E_ERROR / E_PARSE / E_CORE_ERROR / E_COMPILE_ERROR) と catch されない throwable は以下で trap される:

1. `register_shutdown_function()` が `error_get_last()` を確認し、fatal であれば FCGI_STDOUT 経由で `Status: 500 Internal Server Error` 応答を、FCGI_STDERR 経由でスタックトレースを書き出す。
2. `set_exception_handler()` が未捕捉例外を catch し、500 + トレースを書き出す。

trap された fatal の後、ワーカーは **exit しない**。状態を reset して次のリクエストを継続する。(例外: fatal がユーザコードではなくフレームワークコード自体にある場合、ワーカーは不整合な状態にいる可能性がある。その場合は `exit(1)` して親に respawn させるべき。)

### 5.4 `exit()` / `die()`

PHP の `exit()` と `die()` は PHP コードからインターセプトできない。これらはワーカープロセスを終了させる。親プロセスは `SIGCHLD` でこれを検出し respawn する。in-flight のリクエストは終了し、クライアントは接続クローズを観測する (またはワーカーが `register_shutdown_function` 経由で送出すれば、非ゼロ appStatus 付きの `FCGI_REQUEST_COMPLETE`)。

通常のフローで `exit()` の使用は避け、トップレベルスクリプトからは `return` を使うべき。

### 5.5 出力キャプチャ

ユーザ PHP の出力 (`echo`、`print`、`printf`、`print_r` 等) は、各リクエスト開始時にインストールされる `ob_start()` コールバックでキャプチャされる:

```php
ob_start(function (string $chunk, int $phase) use ($conn) {
    if ($chunk !== '') {
        FcgiCodec::writeRecord($conn, FCGI_STDOUT, $requestId, $chunk);
    }
    return '';   // do not also write to the worker's actual stdout
}, /* chunkSize */ 8192);
```

chunk サイズは出力 flush 頻度を制御する。ユーザコードからの `flush()` で即時 write が走る。

`header()` で設定されたヘッダも同じ FCGI_STDOUT ストリームにキャプチャされ、先頭の "Header: value\r\n" 行群として送出される。その後 `\r\n\r\n` が続き、続いてボディバイトが流れる。これはボディの最初のバイトを送出する前に `headers_list()` を確認することで処理される。

### 5.6 error_log() と stderr

`error_log()` (デフォルト送信先) や STDERR への直接書き込みはキャプチャされて FCGI_STDERR レコードとして送出される。クライアントは通常これを自身のロギングに転送する。

## 6. ワーカーのライフサイクル

### 6.1 spawn

親プロセス:

1. `/tmp/phpverse-{pid}.sock` (またはユーザ指定のパス) に listen socket を作成する。
2. 各ワーカーインデックス `i` について:
   - `pcntl_fork()` (PHP 親で) または `posix_spawn` / `ProcessBuilder.start()` (非 PHP 親で)。
   - 子は `PHPVERSE_SOCKET=...` env var (listen socket パス) と `PHPVERSE_WORKER_ID=...` (数値) を設定する。
   - 子は PHP CLI を exec する: `php /path/to/phpverse/worker.php` (PHP 親では、fork で exec せず worker.php を直接 `require` してもよい)。
3. 各ワーカーは起動時に listen socket への接続を開き (`stream_socket_client("unix://$socketPath")`)、FCGI リクエストループに入る。

### 6.2 warm-up

新規 spawn されたワーカーへの最初のリクエストは、`worker.php` と preload PHP ファイルの opcache miss のためわずかに遅い可能性がある。以降のリクエストは warm。

`prependFile` が設定されている場合、ワーカーは起動時に 1 度だけ `require` する (リクエストごとではない)。これによりトップレベルの副作用が償却される。リクエスト単位での prepend 動作が必要なユーザは、スクリプトから `require` すべき。

### 6.3 drain とシャットダウン

親がワーカー停止を決定した場合 (graceful shutdown、N リクエスト後の retire、プール全体の teardown):

1. 親は (リクエスト in-flight なら) ワーカーの現接続に `FCGI_ABORT_REQUEST` を送る、もしくは `FCGI_END_REQUEST` 受信後に接続をクローズする。
2. 接続クローズ後、ワーカーはメインループを抜け `exit(0)` する。
3. 親は `pcntl_waitpid()` / `Process.onExit()` で reap する。

ワーカーは `SIGTERM` に対しても、現在のリクエスト (あれば) を完了してから clean に exit する形で応答する。

### 6.4 クラッシュ復帰

ワーカーが予期せず exit した場合 (trap をすり抜けた未捕捉 fatal、`exit()`、segfault、OOM kill):

- 親の `SIGCHLD` ハンドラが exit を観測する。
- 親はワーカー ID と exit code を log に出す。
- 親は同じインデックスに新しいワーカーを re-spawn する。
- そのワーカーで in-flight だったリクエストは、クライアントには 502 Bad Gateway として報告される (FCGI 相当: 応答途中で接続が閉じる)。

### 6.5 maxRequestsPerWorker

ユーザコードのリークによるメモリ増加を抑えるため、ワーカーを N リクエスト処理後に retire するよう設定できる:

- N 回目の `FCGI_END_REQUEST` 完了後、ワーカーは接続を閉じて `exit(0)` する。
- 親は exit を観測し、新規ワーカーを re-spawn する。

`N = 0` (デフォルト) は retire しない意味。

## 7. CLI 表面と設定

`phpverse` バイナリの CLI フラグ、環境変数、設定ファイルのスキーマは運用者向けリファレンスであり、[MANUAL.ja.md](MANUAL.ja.md) に置く。本 SPEC が支配するワイヤ / 分離 contract の一部ではない。SPEC 準拠は特定の CLI フラグの綴りを要求しないが、daemon モードが同等の機能 (ワーカー数、listen URI、ワーカーあたり最大リクエスト数、prepend ファイル、PHP バイナリパス、特権ドロップ) を提供することは要求する。

ライブラリモード組み込み API (PHP 側 `phpverse\Embed\*` クラスとその非 PHP 同等物) は [MANUAL.ja.md](MANUAL.ja.md) の "Library mode" 節と [TIPS.ja.md](TIPS.ja.md) の "Library-mode integration recipes" 節にドキュメント化されている。

## 8. 実装 TODO リスト

実装エージェント向け。順序が重要。各ステップはテスト可能。

### Phase 1: プロトコル codec

- [ ] `src/Protocol/Constants.php`: FCGI レコードタイプコード、role コード、status コード、version (1)。
- [ ] `src/Protocol/FcgiCodec.php`:
  - `readRecord($stream): ?Record` — 1 FCGI レコードの blocking read。EOF で null を返す。
  - `writeRecord($stream, $type, $requestId, $content): void`
  - `readParams($paramsBytes): array<string,string>` — 連結された 1 つ以上の PARAMS レコードから name/value ペアストリームを decode。
  - `writeParams(array $kv): string` — encode (クライアント側で使用)。
- [ ] phpunit: 既知 FCGI バイトストリーム → records → bytes の round-trip。FCGI/1.1 仕様の example records をテストフィクスチャに含める。

### Phase 2: 単一リクエストワーカー

- [ ] `src/Worker.php`:
  - `__construct($conn, array $opts)`
  - `loop(): void` — レコードを読み、1 リクエスト分を `handleRequest()` に dispatch、応答レコードを書き、peer が接続を閉じるまで繰り返す。
  - `handleRequest($beginReq, array $params, string $stdin): void` — 分離を立ち上げ、スクリプトを require、応答を送出。
- [ ] `worker.php` エントリポイント: `PHPVERSE_SOCKET` env を読み、connect、Worker を construct、loop を実行。
- [ ] スモークテスト: テスト用 FCGI サーバスタブが 1 つの BEGIN_REQUEST + PARAMS + STDIN を送る形で worker.php を起動し、応答レコードが フィクスチャ `.php` ファイルの期待出力と一致することを assert。

### Phase 3: 分離

- [ ] `src/Isolation/RequestScope.php`: スーパグローバルの snapshot / restore、リクエスト単位 reset。
- [ ] `src/Isolation/OutputCapture.php`: FCGI_STDOUT レコードを書く ob_start コールバックをインストール、遅延 `header()` 呼び出しを処理。
- [ ] `src/Isolation/FatalTrap.php`: 500 + トレースを送出する register_shutdown_function と set_exception_handler。
- [ ] phpunit: Worker 経由の end-to-end:
  - 正常リクエストは 200 + body を返す
  - throw するスクリプト → STDERR に 500 + トレース
  - parse error のスクリプト → 500
  - `header()` を呼んで echo するスクリプト → ヘッダ + ボディが正しく framing される
  - 同一ワーカーへの連続リクエストが互いの `$_GET` / `$_POST` を見ない

### Phase 4: parent-fork ヘルパ

- [ ] `src/Embed/ForkedWorker.php`:
  - `static spawn(array $opts): self`
  - `$worker->sock` と `$worker->pid` を公開
  - `kill(int $sig = SIGTERM)`
- [ ] デモスクリプト `examples/embedded.php`: ForkedWorker で 1 リクエストを end-to-end 実行。

### Phase 5: daemon モード

- [ ] `src/Pool.php`: N ワーカー、listen socket、SIGCHLD respawn、max_requests retirement の管理。
- [ ] `src/Cli/Main.php` + `src/Cli/DaemonMode.php`: argv parsing、フラグ処理、Pool 実行。
- [ ] `bin/phpverse` スクリプト。
- [ ] 統合テスト: `--workers 2` で daemon を起動、テストクライアント経由で 100 FCGI リクエスト送信、100 件すべてが正しい応答を受け、ちょうど 2 つのワーカー PID が観測されたことを assert。

### Phase 6: 本番堅牢化

- [ ] 特権ドロップ (`--worker-uid / --worker-gid`)。
- [ ] ログのローテーション / 書式 (`--log-level`、stdout / ファイル出力)。
- [ ] PID ファイル。
- [ ] graceful reload (SIGUSR1 → 全ワーカーの drain + respawn)。
- [ ] TOML 設定ファイルのパース。
- [ ] ドキュメント: docs/architecture.md、docs/protocol.md、docs/isolation.md、docs/cli.md (本 SPEC の対応する節から拡張可)。

## 9. 受け入れ基準

v1.0 リリースの要件:

1. 上記すべての Phase がパスするテストとともに完了していること。
2. 以下のスモークマトリクスをパスすること:
   - ボディ無しの GET リクエスト、プレーンテキスト応答
   - フォームボディの POST リクエスト、`$_POST` がパースされ、プレーン応答
   - 生ボディ + `Content-Type: application/json` の POST リクエスト、`php://input` 経由で読める
   - ストリーミング応答 (ループ内で echo + flush)
   - 5 MB のリクエストボディと 5 MB の応答ボディ
   - ユーザスクリプトの fatal error → 500
   - ユーザスクリプトの `exit()` → ワーカー respawn → 次のリクエストは OK
3. 少なくとも Nginx と Apache の FCGI クライアントと互換 (`fastcgi_pass unix:///var/run/phpverse.sock;` が機能する)。
4. パフォーマンス: 簡素な `phpinfo()` 風ベンチマークで php-fpm の 2 倍以内、典型的な CRUD エンドポイントで 1.2 倍以内。(具体的閾値は Phase 5 後に再評価。)

## 10. 実装者向けの open questions

(実装エージェントが) 本仕様に曖昧さを見つけた場合:

1. **daemon モードでの fd passing**: shared-listen-and-accept-from-worker は SCM_RIGHTS fd passing よりシンプル。fd passing を選ぶ具体的な理由を示せる場合を除き、shared-listen パターンを使うこと。

2. **ワーカー間の opcache 設定**: PHPVerse コードから opcache を設定しないこと。ユーザが `php.ini` または PHP CLI の `-d` フラグで `opcache.*` を設定する。PHPVerse は opcache 非依存。

3. **ワーカーの `exec()` vs PHP の `require`**: PHP 親モードでは `exec()` を省略し、fork した子で worker.php を `require` するだけでよい。非 PHP 親モードでは exec が必須。CLI バイナリの `phpverse --worker` が標準の exec ターゲットである。

4. **TCP listen サポート**: 仕様上要求されるが優先度は低い。まず Unix-socket-only を実装し、TCP は Phase 5 以降に追加する。

5. **Windows サポート**: v1 ではスコープ外。PHP CLI は Linux / macOS のみ。

6. **後方互換**: pre-1.0 は互換性保証なし。1.0 出荷後は FCGI プロファイルと CLI フラグが安定する。内部クラスは動く可能性あり。

## 11. 参考文献

- FastCGI 1.1 仕様:
  <https://fastcgi-archives.github.io/FastCGI_Specification.html>
- CGI/1.1 (RFC 3875): <https://datatracker.ietf.org/doc/html/rfc3875>
- PHP SAPI アーキテクチャ (背景読み物):
  <https://www.php.net/manual/en/internals2.php>
