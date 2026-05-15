> Languages: [English](INSTALL.md) | **日本語**

# PHPVerse インストール

## 1. システム要件

| コンポーネント | 必要バージョン | 備考 |
|---|---|---|
| PHP CLI | 8.1 以降 | ワーカーを動かす PHP バイナリ |
| PHP 拡張 | `pcntl`、`posix`、`sockets`、`json` | 通常はデフォルトビルドに含まれる |
| OS | Linux / macOS / FreeBSD | v1 では Windows 非対応 |
| アーキテクチャ | x86_64、arm64 | PHP がサポートする任意のアーキテクチャ |

推奨 (必須ではない):

| コンポーネント | 理由 |
|---|---|
| `opcache` 拡張 | warm 実行が劇的に高速化 |
| systemd (Linux) | daemon のクリーンなライフサイクル管理 |
| 比較的新しいカーネル (Linux 4.5+) | ワーカー間で公平な負荷分散をするための `SO_REUSEPORT` |

PHP ビルドを確認:

```
php -v
php -m | grep -E '(pcntl|posix|sockets|json|Zend OPcache)'
```

`pcntl` または `posix` が無ければ、ディストロの php パッケージで入れる (例: `apt install php-pcntl`)、もしくは `--enable-pcntl --enable-posix` 付きで PHP を再ビルドする。

## 2. `phpverse` バイナリのインストール

3 つの方法から 1 つを選ぶ。

### 2.1 Composer (PHP 開発者に推奨)

```
composer global require baykit/phpverse
```

パッケージがグローバルにインストールされる。`phpverse` CLI は `~/.config/composer/vendor/bin/phpverse` (Linux) または `~/.composer/vendor/bin/phpverse` (macOS / 古い Composer) に置かれる。このディレクトリが `PATH` に無ければ追加すること。

プロジェクトローカルインストールの場合:

```
cd /path/to/your/project
composer require baykit/phpverse
./vendor/bin/phpverse --version
```

### 2.2 phar ダウンロード (非 Composer 環境に推奨)

自己完結アーカイブ — 単一ファイル、Composer 不要。

```
curl -L -o phpverse https://github.com/baykit/PHPVerse/releases/latest/download/phpverse.phar
chmod +x phpverse
sudo mv phpverse /usr/local/bin/phpverse
phpverse --version
```

phar には worker.php と全 PHPVerse クラスがバンドルされる。それでもシステムの PHP CLI バイナリは必要 (phar はその PHP の上で動く)。

### 2.3 ソースから (開発 / fork 用)

```
git clone git@github.com:baykit/PHPVerse.git
cd PHPVerse
composer install
./bin/phpverse --version
```

開発時は以下でテストを走らせる:

```
./vendor/bin/phpunit
```

## 3. インストールの動作確認

インストール後、ワンショットのスモークテストを実行する:

```
# 1. Create a test script
mkdir -p /tmp/phpverse-test
cat > /tmp/phpverse-test/hello.php <<'EOF'
<?php
echo "Hello from PHPVerse, request method = " . $_SERVER['REQUEST_METHOD'] . "\n";
EOF

# 2. Start PHPVerse with one worker
phpverse --workers 1 --listen tcp://127.0.0.1:9000 &
PV_PID=$!

# 3. Issue an FCGI request via cgi-fcgi (apt install libfcgi-bin)
SCRIPT_FILENAME=/tmp/phpverse-test/hello.php REQUEST_METHOD=GET \
    cgi-fcgi -bind -connect 127.0.0.1:9000

# 4. Tear down
kill $PV_PID
```

期待出力:

```
Content-Type: text/html; charset=UTF-8

Hello from PHPVerse, request method = GET
```

(正確な `Content-Type` は PHP のデフォルト次第。)

## 4. PHPVerse クライアントとしての Nginx 設定

インストール後、web サーバを PHPVerse に向ける。Nginx の例:

```nginx
# /etc/nginx/sites-available/example
server {
    listen 80;
    server_name example.com;
    root /var/www/example;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/var/run/phpverse.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_keep_conn on;
    }
}
```

PHPVerse を起動:

```
phpverse --workers 8 --listen unix:///var/run/phpverse.sock
```

Nginx を reload してテスト:

```
sudo systemctl reload nginx
curl http://example.com/
```

## 5. systemd サービスとして実行 (Linux)

```ini
# /etc/systemd/system/phpverse.service
[Unit]
Description=PHPVerse FCGI worker pool
After=network.target

[Service]
Type=simple
ExecStart=/usr/local/bin/phpverse \
    --workers 8 \
    --listen unix:///var/run/phpverse.sock \
    --worker-uid www-data \
    --worker-gid www-data \
    --max-requests 1000 \
    --pid-file /var/run/phpverse.pid
ExecReload=/bin/kill -USR1 $MAINPID
Restart=always
RestartSec=5

# Required so phpverse can drop privileges to www-data and bind
# the socket to a path under /var/run.
User=root
RuntimeDirectory=phpverse
RuntimeDirectoryMode=0755

[Install]
WantedBy=multi-user.target
```

有効化して起動:

```
sudo systemctl daemon-reload
sudo systemctl enable --now phpverse
sudo systemctl status phpverse
```

## 6. アップグレード

### 6.1 Composer 経由

```
composer global update baykit/phpverse
sudo systemctl restart phpverse
```

### 6.2 phar 経由

```
curl -L -o /tmp/phpverse.new https://github.com/baykit/PHPVerse/releases/latest/download/phpverse.phar
chmod +x /tmp/phpverse.new
sudo mv /tmp/phpverse.new /usr/local/bin/phpverse
sudo systemctl restart phpverse
```

### 6.3 接続を落とさず reload する

daemon に `SIGUSR1` を送ると graceful にワーカーが respawn される (例: 新 PHP コードをデプロイした後)。新ワーカーが変更を取り込み、旧ワーカー上の in-flight リクエストは先に完了する。

```
sudo systemctl reload phpverse        # = kill -USR1 $MAINPID
```

## 7. アンインストール

### 7.1 Composer

```
composer global remove baykit/phpverse
```

### 7.2 phar

```
sudo rm /usr/local/bin/phpverse
```

### 7.3 systemd

```
sudo systemctl disable --now phpverse
sudo rm /etc/systemd/system/phpverse.service
sudo systemctl daemon-reload
```

## 8. トラブルシューティング

### 8.1 `pcntl_fork()` 未定義

`pcntl` PHP 拡張が無いか無効化されている。インストール / 有効化する:

```
# Debian/Ubuntu
sudo apt install php-cli php-pcntl
# RHEL/Fedora
sudo dnf install php-cli php-pcntl
```

一部のディストロビルドでは `pcntl` が CLI モードでのみロードされる (web SAPI ではロードされない)。PHPVerse は CLI モードで動くので問題ない。

### 8.2 `socket bind: permission denied`

PHPVerse を実行しているユーザが Unix socket パスに書き込めない。対処:

- そのユーザが書き込めるパスを使う (例: `/tmp/phpverse.sock`)。
- root で動かし、ワーカーは `--worker-uid` で特権ドロップする (親は socket バインド用に root を維持)。
- 動作ユーザが所有するディレクトリ配下のパスを使う (`/var/run/phpverse/` を `chown user:group` する)。

### 8.3 ワーカーがすぐに exit する

daemon ログを確認:

```
journalctl -u phpverse -f       # if systemd-managed
```

よくある原因:

- `--prepend` ファイルが fatal error を起こしている。
- `--php` で指定した PHP バイナリが間違っている。
- `pcntl` / `posix` 拡張が無い。

### 8.4 起動後の最初のリクエストが遅い

これは正常 — ワーカーは最初のリクエストで opcache を warm up する。以降のリクエストはかなり速くなる。初回レイテンシを下げたければ `php.ini` で `opcache.preload` を設定する。

### 8.5 時間が経つにつれてメモリが増える

ユーザ PHP が静的状態をリークしている。`--max-requests 1000` (1000 リクエスト後にワーカーを retire し、新規に置き換える) で緩和する。長期的にはユーザコードのリークを修正すること。

## 9. 次に読むもの

- [README.ja.md](README.ja.md) — プロジェクト概観。
- [MANUAL.ja.md](MANUAL.ja.md) — CLI と設定のフルリファレンス。
- [TIPS.ja.md](TIPS.ja.md) — デプロイパターン、パフォーマンスチューニング、落とし穴。
- [SPEC.ja.md](SPEC.ja.md) — ワイヤプロトコルとワーカー分離 contract (実装者とクライアントライブラリ作者向け)。
