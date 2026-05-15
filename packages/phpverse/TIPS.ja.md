> Languages: [English](TIPS.md) | **日本語**

# PHPVerse Tips

プログラミングノートとデプロイガイダンス。SPEC.ja.md (= 不変の contract) と MANUAL.ja.md (= CLI リファレンス) と異なり、本書は進化しうる **推奨** を述べる。自分のケースに合うものを選ぶこと。

## 1. デプロイパターン行列

PHPVerse は **2 つの独立した軸** に沿ってデプロイできる:

- **supervision 軸**: ワーカープールマネージャを誰が運用するか？
  - **daemon モード (D)** — `phpverse` バイナリが supervise する。
  - **library モード (L)** — ホストアプリケーションが直接 supervise する。
- **パーティション軸**: ワーカーをクライアントに対してどうグループ化するか？
  - **per-client pool (P)** — 各クライアント (例: 各 BayServer agent) が固有のワーカーグループを持つ。
  - **global pool (G)** — 全クライアント共有の単一プール。

これで **4 つの主要組み合わせ** が得られ、さらに退化的な単一ワーカーバリアントもある。以下、"client" はプールを所有する論理単位 — 典型的には BayServer のグランドエージェント、Nginx ワーカープロセス、独自 web サーバのスレッド — を指す。

### 1.1 パターン行列

```
                ┌─────────────────────────┬─────────────────────────┐
                │  Per-client pool (P)    │  Global pool (G)        │
                ├─────────────────────────┼─────────────────────────┤
  Daemon (D)    │  D-P                    │  D-G                    │
                │  N daemons,             │  1 daemon, M workers,   │
                │   each with workers=M   │   all clients connect   │
                │  → BayServer recipe a   │  → "php-fpm style"      │
                ├─────────────────────────┼─────────────────────────┤
  Library (L)   │  L-P                    │  L-G                    │
                │  each client forks      │  master forks workers,  │
                │   its own M workers     │   clients share via     │
                │  → BayServer recipe c   │   shared queue          │
                │                         │  → BayServer recipe b   │
                └─────────────────────────┴─────────────────────────┘
```

退化的バリアント (あまり一般的でない):

- **workers=1 の D-P**: たくさんの小さな daemon、それぞれ 1 ワーカー。daemon ごとの設定 (ワーカーごとに異なる PHP バージョン等) が欲しいときのみ有用。
- **1 ワーカーの L-G**: 無意味 (サーバ全体で 1 ワーカー、並列性ゼロ)。

### 1.2 パターン選択

| 質問 | パターン |
|---|---|
| 既存の FCGI クライアント (Nginx 等) がいて、php-fpm の代替が欲しい？ | **D-G** |
| 同一ホスト上に複数の独立クライアントがあり、それぞれに固有プール (隔離、独立した respawn サイクル) を持たせたい？ | **D-P** |
| web サーバを作っており、PHP 実行を完全に組み込みたい (プロセスツリーに余分な daemon プロセスを置きたくない)？ | **L-P** または **L-G** |
| リクエストあたりレイテンシ最小 / プロセス数最少にしたい？ | **L-P** (daemon の中継なし、プロセスツリーがリーン) |
| ホスト言語ごとの実装作業を最小化したい？ | **D-P** (各ホストの PharosDocker = 「サブプロセス spawn + FCGI クライアント」) |

## 2. BayServer レシピ

BayServer で `GRAND_AGENTS=N` を使うと特有の問いが生じる: PHP ワーカーは合計何個になるのか、誰が管理するのか？ 妥当なレシピは 3 つ。ホスト言語間の portability vs プロセス数のトレードオフで選ぶ。

3 つすべてで **`GRAND_AGENTS=8`** を仮定し、**合計 32 PHP ワーカー** (agent あたり 4) を目標とする。

### Recipe a — agent ごとに daemon (D-P、portability 重視で推奨)

各 agent が `phpverse --workers=4` を spawn する。PHPVerse daemon が 4 ワーカーを管理し、agent は agent 専用 Unix socket 経由で daemon に FCGI を喋る。

```
BayServer master
├─ agent 0  ──fork──→  phpverse-0 (daemon, --workers=4)
│                       ├─ worker 0-0 .. 0-3
├─ agent 1  ──fork──→  phpverse-1 (daemon, --workers=4)
│                       ├─ worker 1-0 .. 1-3
   ⋮
└─ agent 7  ──fork──→  phpverse-7 (daemon, --workers=4)
                        ├─ worker 7-0 .. 7-3
```

| | |
|---|---|
| BayServer 側プロセス数 (agent) | 8 |
| PHPVerse supervisor プロセス数 | 8 |
| ワーカープロセス数              | 32 |
| 合計                            | 48 + master |
| LB 粒度                         | 接続ごと (daemon の共有 listen socket で `accept()` するワーカー間をカーネルが LB) |
| ホスト別 Pharos docker 複雑度   | **低** — 単に「phpverse サブプロセスを spawn + FCGI を喋る」だけ |

利点:

- ワーカープール管理 (spawn、supervise、respawn、drain) が 1 か所 — PHPVerse コード、PHP で書かれ全ホストで共有 — に集約される。
- ホストごとの PharosDocker (Java / Ruby / Python / Go / Node) は薄い層 (~100 行) で済む: daemon サブプロセスを起動、FCGI socket を保持、tour をそこに routing する。既存の FCGI クライアントコードが再利用できる。
- agent ごとの隔離: 1 agent のプールは他 agent と独立。

欠点:

- supervisor プロセスが 8 個、合計 ~80 MB の RSS オーバヘッド。最近のサーバでは無視できる。
- LB 粒度は接続ごと (どのワーカーが新規クライアント接続を受けるかはカーネルが決める)。リクエストごとの LB を得るには、FCGI クライアントが `FCGI_KEEP_CONN` を無効化し、再接続あたり ~10–30 µs を払うことになる。

これが BayServer ベースのデプロイの推奨スタート地点。

### Recipe b — global daemon (D-G)

すべての BayServer agent が `--workers=32` の単一 PHPVerse daemon を共有する。

```
BayServer master
├─ agent 0 ─┐
├─ agent 1 ─│
│  ...      ├─ all agents connect to /var/run/phpverse.sock
├─ agent 7 ─┘
└─ phpverse-global (daemon, --workers=32)
    ├─ worker 0 .. 31
```

| | |
|---|---|
| BayServer 側プロセス数 (agent) | 8 |
| PHPVerse supervisor プロセス数 | 1 |
| ワーカープロセス数              | 32 |
| 合計                            | 41 + master |
| LB 粒度                         | 接続ごと (カーネル LB だがプール共有なのでどの agent からもワーカーを使える) |
| ホスト別 Pharos docker 複雑度   | 低 |

利点:

- プロセス数が最小。
- ワーカーは任意の agent から利用可能 (agent 間負荷の平滑化)。

欠点:

- **agent ごとの隔離が無い**。1 agent のバーストが他 agent のリクエストを starve させうる。
- BayServer のグランドエージェントモデルは agent ごとの独立を前提としており、global リソースを強いるとその前提を破る。
- listen socket が単一の競合点になる。

クロス agent 隔離が問題にならないほど小規模な BayServer デプロイでだけ使う。

### Recipe c — agent ごとに library モード (L-P)

各 BayServer agent が worker.php プロセス 4 個を **直接** fork する。プロセスツリーに PHPVerse daemon バイナリは無い。

```
BayServer master
├─ agent 0
│   ├─ fork → worker.php 0-0 (FCGI over fd)
│   ├─ fork → worker.php 0-1
│   ├─ fork → worker.php 0-2
│   └─ fork → worker.php 0-3
   ⋮
└─ agent 7 ...
```

| | |
|---|---|
| BayServer 側プロセス数 (agent) | 8 |
| PHPVerse supervisor プロセス数 | 0 |
| ワーカープロセス数              | 32 |
| 合計                            | 40 + master |
| LB 粒度                         | リクエストごと (agent が tour ごとにワーカーを選ぶ) |
| ホスト別 Pharos docker 複雑度   | **高** — agent がワーカー spawn / respawn / アイドルキュー / max-requests を自前で実装 |

利点:

- 最もリーンなプロセスツリー (supervisor の仲介無し)。
- 本物のリクエストごと LB: agent はアイドルキュー + 保留キュー (下記 §3 のアルゴリズム) で公平に dispatch できる。
- agent あたり余分な FCGI socket が無い。

欠点:

- 各ホスト言語がワーカープールマネージャを再実装することになる。同じ bug を 6 言語で 6 回直すのを避けるため、言語ごとに共通の `baykit.phpverse.client.*` ライブラリを出荷する (PHP は PHPVerse がリファレンス実装を提供、Java / Ruby 等はコミュニティ / BayServer チームが書く)。

プロセス数最小か、きめ細かなリクエスト単位 LB 制御を重視し、ホストごとのプールマネージャコードをメンテする覚悟があるならこれを選ぶ。

### レシピ比較サマリ

| | Recipe a (D-P) | Recipe b (D-G) | Recipe c (L-P) |
|---|---|---|---|
| プロセス数 | 48 | 41 | **40** |
| agent ごとの隔離 | ✓ | ❌ | ✓ |
| LB 粒度 | 接続 | 接続 | **リクエスト** |
| Pharos のホスト別実装工数 | **低** | 低 | 高 |
| 推奨用途 | 最初の port、多言語展開 | 小規模な all-in-one | レイテンシ重視 / 高度な LB |

新規 BayServer-PHP port では **Recipe a から始める**。プロファイリングで daemon 仲介オーバヘッドがワークロードに有意と判明すれば (めったに無い) Recipe c に切り替える。

## 3. ロードバランシング戦略 (library モード)

ホストアプリケーションがプールを所有する (= 上の recipe c) とき、どのワーカーが各リクエストを処理するかをホストが選ぶ。推奨アルゴリズム:

```
struct WorkerPool:
    idle: FIFO queue of Worker
    busy: Set of Worker
    pending: bounded FIFO queue of Tour

on tour_arrived(tour):
    if not idle.empty():
        w = idle.pop_front()
        busy.add(w)
        w.dispatch(tour)
    elif pending.length() < pending_cap:
        pending.push_back(tour)
    else:
        tour.respond_503("All workers busy and queue full")

on worker_finished(w):
    busy.remove(w)
    if not pending.empty():
        tour = pending.pop_front()
        busy.add(w)
        w.dispatch(tour)
    else:
        idle.push_back(w)
```

特性:

- FIFO アイドルキューにより、定常負荷下で自然なラウンドロビンになる。
- 保留キューが短いバーストを吸収する。
- 過負荷時に 503 で backpressure (無限のレイテンシ増よりまし)。
- `pending_cap` はワークロードごとにチューニングするが、ワーカー数の 2 倍をデフォルトの開始点にするのが妥当。

バリアント:

- **FIFO の代わりに least recently used**: 定常状態では FIFO と同一挙動。違いはワーカー respawn 後だけ (新ワーカーは "最も新しく作られた" が直ちに利用可能であるべき — 新ワーカーをキューの末尾に push する限り、どちらのアルゴリズムでも問題ない)。
- **加重 RR**: ワーカーごとに `max-requests` クォータが異なる (例: PHP バージョンが異なる) 場合、アイドルキューを bias する。
- **セッションアフィニティ**: アプリケーションが同一ワーカーへの tour 連続ヒット (例: PHP 側のワーカー別キャッシュ) で恩恵を受けるなら、session id をハッシュしてワーカーを選ぶ。カーネルが選択を上書きしないよう `FCGI_KEEP_CONN` を無効化すること。

## 4. FCGI_KEEP_CONN のトレードオフ

`FCGI_KEEP_CONN = 1` (`FCGI_BEGIN_REQUEST.flags` のビット) は、`FCGI_END_REQUEST` 後も接続を保持して次のリクエストに使うようワーカーに伝える。ほぼすべての FCGI クライアントがデフォルトで有効にしている。

| 設定 | 効果 | 使いどころ |
|---|---|---|
| `KEEP_CONN = 1` (デフォルト) | 同一クライアント接続 → そのリクエストすべてが同一ワーカーへ。再接続オーバヘッドが減るが、リクエストレベル LB は "best effort"。 | ほとんどの web-server-to-pool シナリオ。 |
| `KEEP_CONN = 0` | リクエストごとに新規接続。プールは均一なリクエスト単位の負荷分布を見る。 | 真のリクエストごと LB が欲しい library モードホスト、短命な小規模クライアント。 |

10–30 µs の再接続コストは典型的な PHP ワークロード (>1 ms のスクリプト実行) では見えない。マイクロベンチマーク (<200 µs) では差が見える。

## 5. パフォーマンスチューニング

### 5.1 ワーカー数

| ワークロード | 推奨 `--workers` |
|---|---|
| CPU バウンド (テンプレート、数値計算) | 物理コア数 × 1.0–1.5 |
| I/O バウンド (DB、外部 API) | 物理コア数 × 2–4 |
| 典型的な混在 CRUD | ホストあたり 4–16 |

ワーカーの過剰割当ては RAM を消費する。各ワーカーは PHP プロセスの状態 (拡張 + preload 次第で ~30–80 MB) を保持する。

### 5.2 opcache

PHPVerse は opcache 非依存。opcache は `php.ini` または `-d` フラグで設定すること。本番推奨:

```ini
opcache.enable_cli = 1
opcache.memory_consumption = 256
opcache.max_accelerated_files = 20000
opcache.validate_timestamps = 0          ; reload via SIGUSR1 instead
```

`validate_timestamps = 0` (高速) にする場合、新コードのデプロイ後に SIGUSR1 でワーカーを reload する。

### 5.3 ワーカーあたり max-requests

`--max-requests N` でワーカーを N リクエスト後に retire する:

| アプリ種別 | 推奨 N |
|---|---|
| 行儀のよい (グローバル状態リークなし) | 0 (retire なし) |
| 平均的 | 1000–10000 |
| メモリリーク傾向 (レガシーコード、特定フレームワーク) | 100–500 |

retire は N リクエストごとに 1 回のコールドスタートをコストとする。攻めすぎ (小さい N) はスループットを損なう。

### 5.4 listen backlog

`phpverse` は listen socket backlog を `SOMAXCONN` に設定する。新規接続フラッドが backlog を越えるとカーネルが拒否する (クライアントは connect-refused を見る)。同時新規接続が 4096 を越えるバーストを想定するなら `net.core.somaxconn` をチューニングすること。

## 6. よくある落とし穴

### 6.1 `exit()` / `die()` がワーカーを殺す

PHP の `exit()` と `die()` はプロセスを終了させる。PHPVerse はこれをインターセプトできない。ワーカーは exit し、親は respawn する。in-flight のリクエストは失われる。**リクエストから抜けるには `return` を使い、`exit()` を使わないこと。**

サードパーティが `exit()` を呼ぶコードを動かす必要があるなら、各呼び出しがワーカー respawn 1 回 (~100–500 ms のコールドスタート) のコストになることを受け入れる。

### 6.2 グローバル状態のリーク

PHPVerse のワーカーは長寿命。スクリプトのトップレベルで `global $foo` 宣言した変数はリクエスト間で永続する。シングルトン、静的クラスプロパティ、`define()` した定数も永続する。**ユーザコードはリクエスト単位のクリーンアップに責任を持つ** (関数スコープで包まない限り)。

リークを抑えるには `--max-requests` を使う。

### 6.3 `chdir()` のリーク

PHPVerse はリクエストごとにワーカーの CWD を snapshot / restore するが、ユーザコードが `chdir()` してから早期 return した場合、CWD は予期せぬ状態になりうる。PHPVerse の `register_shutdown_function` trap が復元するのでそれに任せること。ただし、サードパーティコードが `chdir()` した場合、リクエスト内で CWD に依存しないこと。

### 6.4 ワーカー間のセッションロック

PHP のデフォルトファイルベースセッションハンドラはリクエスト期間中セッションファイルをロックする。複数ワーカー環境では、遅いハンドラが同一セッションからの後続リクエストをブロックしうる。`session_write_close()` を早めに呼ぶか、ロックなしハンドラ (Redis をロック無効、memcached 等) に切り替える。

### 6.5 ワーカー stderr の汚染

ワーカーからの STDERR への直接書き込みは `FCGI_STDERR` レコードになり、クライアントが通常ログに残す。stderr に書く拡張 (例: `xdebug` トレース) はクライアントのログを溢れさせる。本番ビルドではそうした拡張を無効化すること。

## 7. php-fpm からの移行

ほとんどの php-fpm デプロイは直接対応する:

| php-fpm | PHPVerse |
|---|---|
| `pm = dynamic`、`pm.max_children = N` | `--workers N` (v1 では静的、動的スケーリング無し) |
| `pm.max_requests = N` | `--max-requests N` |
| `request_terminate_timeout = 30s` | `--max-script-seconds 30` |
| `listen = /var/run/php-fpm.sock` | `--listen unix:///var/run/phpverse.sock` |
| `user = www-data`、`group = www-data` | `--worker-uid www-data --worker-gid www-data` |
| `php_admin_value[opcache.*] = ...` | `php.ini` で設定 (PHPVerse は opcache 非依存) |
| `slowlog = ...` | v1 ではサポート無し (予定) |
| `pm.status_path = ...` | v1 ではサポート無し (予定) |

php-fpm の動的スケーリング、slowlog、status page に依存している場合、それらの機能が入るまでは php-fpm を使い続け、後で PHPVerse を再検討すべき。

## 8. library モード統合レシピ

PHP ホスト (PHP で書かれた任意の web サーバ) は `phpverse\Embed\ForkedWorker` を使う。API は [MANUAL.ja.md §4](MANUAL.ja.md) を参照。

非 PHP ホストは言語別 client library を取り込む:

| ホスト言語 | クライアントライブラリ |
|---|---|
| Java   | `baykit.phpverse.client` (Maven artifact `io.baykit:phpverse-client`) |
| Ruby   | `baykit.phpverse.client` (gem `baykit-phpverse-client`) |
| Python | `baykit.phpverse.client` (PyPI `baykit-phpverse-client`) |
| Go     | `baykit.phpverse.client` (Go module `github.com/baykit/phpverse-client-go`) |
| Node   | `baykit.phpverse.client` (npm `@baykit/phpverse-client`) |
| Rust   | `baykit_phpverse_client` (crate `baykit-phpverse-client`) |

各 client library が提供するもの:

- fork + IPC を包む `Pool` / `ForkedWorker` 相当。
- FCGI codec (リクエストの encode + レスポンスの decode)。
- ホストの event loop 用統合ヘルパ (NIO Selector、goroutine、async/await 等)。

これらのライブラリは PHPVerse 本体の一部ではない。それぞれ独自リポジトリに住み、SPEC のワイヤプロトコルだけに依存する。
