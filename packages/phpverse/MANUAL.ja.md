> Languages: [English](MANUAL.md) | **日本語**

# PHPVerse マニュアル

運用者向けリファレンス。CLI フラグ、設定ファイル、環境変数、シグナル、ロギング。

プロトコル / 分離セマンティクスは [SPEC.ja.md](SPEC.ja.md) を参照。デプロイパターンとホスト固有レシピは [TIPS.ja.md](TIPS.ja.md) を参照。

## 1. CLI 概観

`phpverse` バイナリには 3 つの動作モードがある:

| モード | 起動形式 | 利用者 |
|---|---|---|
| **daemon モード** | `phpverse --workers N --listen ...` | システム管理者 (php-fpm 風デプロイ) |
| **worker モード** (内部) | `phpverse --worker --socket ... --worker-id N` | daemon 親プロセスが新規ワーカーを exec する際 |
| **library モード** | CLI 経由ではなく、`phpverse\Embed\*` PHP クラス、または他言語の `baykit.phpverse.client.*` ライブラリ経由 | ホストアプリケーション (BayServer、独自 web サーバ等) |

## 2. daemon モード

### 2.1 起動形式

```
phpverse \
    --workers N \
    --listen URI \
    [--max-requests N] \
    [--max-script-seconds N] \
    [--prepend PATH] \
    [--php PATH] \
    [--worker-uid USER] \
    [--worker-gid GROUP] \
    [--pid-file PATH] \
    [--log-level LEVEL] \
    [--config PATH]
```

### 2.2 フラグ

| フラグ                       | デフォルト              | 説明 |
|----------------------------|-------------------------|-------------|
| `--workers N`              | 必須                    | spawn するワーカープロセス数。php-fpm の `pm.max_children` と同様にサイジングする。 |
| `--listen URI`             | 必須                    | `unix:///path/to/sock` または `tcp://host:port`。 |
| `--max-requests N`         | 0                       | 各ワーカーが N リクエスト処理後に retire。0 は retire しない。メモリリーク対策。 |
| `--max-script-seconds N`   | 30                      | リクエストあたり hard kill 閾値。PHP の `set_time_limit` に渡される。 |
| `--prepend PATH`           | -                       | ワーカー起動時に 1 度だけ `require` する (リクエストごとではない)。 |
| `--php PATH`               | `PATH` 上の最初の `php` | ワーカー exec に使う PHP CLI バイナリ。 |
| `--worker-uid USER`        | 継承                    | ワーカー spawn 前にこのユーザ (名前または数値 uid) に特権を落とす。起動時に root が必要。 |
| `--worker-gid GROUP`       | 継承                    | このグループに特権を落とす。 |
| `--pid-file PATH`          | -                       | 親 PID をこのファイルに書く。 |
| `--log-level LEVEL`        | `info`                  | `error`、`warn`、`info`、`debug` のいずれか。 |
| `--config PATH`            | -                       | TOML 設定ファイルからデフォルトを読む。CLI フラグがファイル値を上書きする。 |
| `--version`                | -                       | バージョンを表示して exit。 |
| `--help`                   | -                       | ヘルプを表示して exit。 |

### 2.3 Listen URI

- `unix:///absolute/path/to/sock` — Unix domain socket。親ディレクトリは存在し、`phpverse` を実行するユーザが write 可能でなければならない。socket ファイルは起動時に作成され、graceful stop 時に unlink される。
- `tcp://host:port` — TCP socket。`host` は全インタフェースなら `0.0.0.0`、ループバックのみなら `127.0.0.1`、または特定 IP を指定する。クライアント (Nginx 等) はこのアドレスに connect する。

同一ホスト内のデプロイでは Unix socket を推奨 (オーバヘッドが低くファイルシステムレベルの権限が使える)。クロスホストでは TCP を使う。

### 2.4 設定ファイル

任意で TOML 形式。CLI フラグがファイル値を上書きする。

```toml
listen = "unix:///var/run/phpverse.sock"
workers = 8
max_requests = 1000
max_script_seconds = 30
prepend = "/etc/phpverse/prepend.php"
php = "/usr/bin/php"
log_level = "info"
pid_file = "/var/run/phpverse.pid"

[security]
worker_uid = "www-data"
worker_gid = "www-data"
```

設定ファイルのパス探索順:

1. `--config PATH` (明示指定)。
2. `$PHPVERSE_CONFIG` 環境変数。
3. `/etc/phpverse/phpverse.conf`。
4. なし (= すべて CLI デフォルト)。

### 2.5 環境変数 (daemon)

| 変数 | 効果 |
|---|---|
| `PHPVERSE_CONFIG`         | 設定ファイルのパス (`--config` で上書きされる) |
| `PHPVERSE_LOG_LEVEL`      | ログレベル (`--log-level` で上書きされる) |
| `PHPVERSE_PID_FILE`       | pid ファイルパス (`--pid-file` で上書きされる) |

これらは systemd / コンテナデプロイの利便のため。CLI フラグが優先される。

## 3. worker モード (内部)

```
phpverse --worker --socket /tmp/phpverse-{pid}.sock --worker-id N
```

親プロセスが新規ワーカーを exec する際に使う形式。エンドユーザ向け **ではない**。daemon とライブラリモードのヘルパが内部で起動する。

worker モードは以下の env var を設定した `php worker.php` と機能的に等価:

| 変数 | 説明 |
|---|---|
| `PHPVERSE_SOCKET`       | 親の listen socket パス (ワーカーはここに connect する) |
| `PHPVERSE_WORKER_ID`    | 数値ワーカー id (ログ用) |
| `PHPVERSE_PREPEND`      | 任意の prepend ファイルパス (起動時に 1 度ロードされる) |
| `PHPVERSE_MAX_REQUESTS` | このリクエスト数の後に retire。未指定 / 0 は無制限 |

`--worker` フラグ形式は、env var を設定せずに手動でデバッグ用ワーカーを起動するためのもの。

## 4. library モード

PHP で書かれたホストアプリケーションに組み込む場合:

```php
use phpverse\Embed\ForkedWorker;

$worker = ForkedWorker::spawn([
    'php'        => '/usr/bin/php',
    'workerPhp'  => __DIR__ . '/vendor/baykit/phpverse/worker.php',
    'prepend'    => null,
    // optional:
    'env'        => ['SOME_VAR' => 'value'],
    'maxRequests' => 0,
]);

// $worker->sock  : connected non-blocking stream resource
// $worker->pid   : child PID
// $worker->kill(SIGTERM);  // graceful stop
```

その後、ホストは `$worker->sock` 上での FCGI 会話を担当する。ホストの event loop はこの fd を read イベントに登録すること。

非 PHP ホスト言語では、各言語向け client library が同等のヘルパを提供する: `baykit.phpverse.client.*` (Java / Ruby / Python / Go / Node / Rust)。ホスト固有レシピは [TIPS.ja.md](TIPS.ja.md) を参照。

## 5. シグナル

daemon モード親プロセスは以下に応答する:

| シグナル | 動作 |
|---|---|
| `SIGTERM` / `SIGINT` | graceful shutdown: accept 停止、in-flight をドレイン、exit。 |
| `SIGQUIT`            | 即時シャットダウン: 全ワーカーを SIGKILL、exit。 |
| `SIGUSR1`            | graceful reload: 全ワーカーを drain して respawn (例: 新 PHP コードをデプロイ後、opcache をクリアする) |
| `SIGUSR2`            | ログファイルの再オープン (`--log-level` がファイル出力の場合)。 |
| `SIGHUP`             | 設定ファイルを再読込し、ランタイムで変更可能な tunable (`log_level`、`max_requests`) を再適用。`--workers` と `--listen` はフル再起動が必要。 |
| `SIGCHLD`            | 死んだワーカーを reap して respawn。 |

ワーカーは以下に応答する:

| シグナル | 動作 |
|---|---|
| `SIGTERM`            | 現在のリクエスト (あれば) を完了してから clean に exit。 |
| `SIGKILL`            | 即死 (カーネル強制)。 |
| `SIGUSR1`            | "次のアイドル時に retire" フラグを立て、次の `FCGI_END_REQUEST` 後に exit。 |

## 6. ロギング

PHPVerse はデフォルトで運用ログを stderr に書き出す。ログ行の形式:

```
[YYYY-MM-DDTHH:MM:SS+ZZ:ZZ] [level] [worker-id?] message
```

レベル:

| レベル | 何を出すか |
|---|---|
| `error` | ワーカーのクラッシュ、設定エラー、起動時 fatal エラー |
| `warn`  | リトライ、低速ワーカー、retire されたワーカー |
| `info`  | 起動、シャットダウン、ワーカーの spawn / respawn |
| `debug` | リクエストごとの envelope 詳細 (冗長。本番用ではない) |

ユーザ PHP の出力 (echo 等) は FCGI 経由でクライアントに行き、PHPVerse のログには **行かない**。ユーザ `error_log()` と PHP からの stderr 直接書き込みは FCGI_STDERR レコードになり、クライアントが通常ログに残す。

## 7. exit code

daemon 親:

| exit code | 意味 |
|---|---|
| 0 | graceful shutdown |
| 1 | ランタイム fatal (例: 動作中に listen socket bind が失敗) |
| 2 | 設定エラー (不正な CLI フラグ、設定ファイルの不備) |
| 64 | 用法エラー (`--help`、`--version`、または `--help` 無しの未知フラグ) — BSD sysexits に準拠 |

## 8. 運用例

### 8.1 systemd unit

```ini
# /etc/systemd/system/phpverse.service
[Unit]
Description=PHPVerse FCGI worker pool
After=network.target

[Service]
Type=simple
ExecStart=/usr/local/bin/phpverse --config /etc/phpverse/phpverse.conf
ExecReload=/bin/kill -USR1 $MAINPID
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

### 8.2 PHP コードの reload

新しい PHP ファイルをデプロイした後、新 opcache 状態を取り込むためにワーカーを reload する:

```
systemctl reload phpverse        # sends SIGUSR1 → graceful worker respawn
```

### 8.3 in-flight リクエストの inspection

PHPVerse v1 にはビルトインの status エンドポイントは無い。標準的な Linux ツールを使う:

```
ss -lx | grep phpverse           # is the socket listening?
ps -ef | grep phpverse           # what worker PIDs are alive?
strace -p <worker-pid>           # what is one worker doing right now?
```

`--status-endpoint` フラグ (= php-fpm の `pm.status_path` 相当) は将来の minor リリースで追加される可能性がある。
