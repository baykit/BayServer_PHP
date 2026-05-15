> Languages: [English](README.md) | **日本語**

# PHPVerse

PHP のための、組み込み可能でモダンな FCGI ワーカープール。

PHPVerse は、親プロセスが管理する長寿命の子プロセスのプールで PHP スクリプトを実行する。ワイヤ上では標準的な **FastCGI 1.1** を喋るため、任意の FCGI クライアント (Nginx、Apache、HAProxy、Caddy、独自 web サーバ等) が無改造で通信できる。各ワーカーは PHP インタプリタをホストし、リクエストを逐次的に処理する。

プールの supervisor は以下のいずれか:

- `phpverse` バイナリ (= **daemon モード**、php-fpm と同様)。
- ホストアプリケーション (web サーバ) がワーカーを直接 fork する (= **library モード**、mod_php に似ているがプロセス分離つき)。

## Why

| 比較対象 | 違い |
|---|---|
| **php-fpm** | ライブラリとして組み込み可能 (ホストが直接ワーカーを fork できる)、外部 daemon が不要、モダンなコードベース。 |
| **mod_php / FrankenPHP** | プロセス分離 (ユーザ PHP のクラッシュは 1 ワーカーを殺すだけでホストは無傷)、ホスト言語の FFI 不要 (ワーカーは単に `php worker.php`)。 |
| **CGI / php-cgi** | リクエストごとの fork/exec が無い。ワーカーは常時 warm で opcache も hot に保たれる。 |

## クイックスタート

daemon モード (= php-fpm の置き換え):

```
phpverse --workers 8 --listen unix:///var/run/phpverse.sock
```

その上で Nginx 側:

```nginx
location ~ \.php$ {
    fastcgi_pass unix:/var/run/phpverse.sock;
    include fastcgi_params;
}
```

インストールは [INSTALL.ja.md](INSTALL.ja.md)、CLI / 設定の全リファレンスは [MANUAL.ja.md](MANUAL.ja.md)、デプロイパターンとパフォーマンスチューニングは [TIPS.ja.md](TIPS.ja.md) を参照。

## ドキュメント

| ファイル | 対象読者 | 内容 |
|---|---|---|
| [SPEC.ja.md](SPEC.ja.md)     | 実装者 + クライアント作者 | ワイヤプロトコル (FCGI プロファイル)、ワーカー分離契約、ライフサイクルセマンティクス、受け入れ基準。不変な contract。 |
| [MANUAL.ja.md](MANUAL.ja.md) | 運用者                  | CLI フラグ、設定ファイル、環境変数、シグナル、ロギング。man page 相当。 |
| [TIPS.ja.md](TIPS.ja.md)     | アプリケーション開発者   | デプロイパターン (daemon vs library × per-agent vs global)、web サーバ別レシピ (BayServer、Nginx、Apache)、パフォーマンスチューニング、よくある落とし穴。 |
| [INSTALL.ja.md](INSTALL.ja.md) | システム管理者          | システム要件、Composer / phar 経由のインストール、動作確認。 |
| [LICENSE](LICENSE)           | 全員                    | Baykit Public License v1.1。 |

## ライセンス

[Baykit Public License v1.1](LICENSE) — Apache Software License 1.1 をベースとする。baykit ファミリの他プロジェクトと同一ライセンス。

## 命名規約 (正本テーブルは SPEC.ja.md §1.1)

| 識別子 | 値 |
|---|---|
| PHP 名前空間 (`src/` 内) | `phpverse\` |
| Composer パッケージ | `baykit/phpverse` |
| 非 PHP クライアントライブラリの source-package | `baykit.phpverse.*` |
| GitHub リポジトリ | `baykit/PHPVerse` |

## プロジェクト状態

実装前。仕様 (SPEC.ja.md) と運用者マニュアル (MANUAL.ja.md) が v1.0 の contract を定義し、実装エージェントは SPEC.ja.md §8 ("Implementation TODO list") から作業する。ソースコードはまだ存在しない。
