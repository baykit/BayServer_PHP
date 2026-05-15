> Languages: **English** | [日本語](INSTALL.ja.md)

# PHPVerse Installation

## 1. System requirements

| Component | Required version | Notes |
|---|---|---|
| PHP CLI | 8.1 or later | The PHP binary that runs the workers |
| PHP extensions | `pcntl`, `posix`, `sockets`, `json` | usually present in default builds |
| Operating system | Linux / macOS / FreeBSD | Windows not supported in v1 |
| Architecture | x86_64, arm64 | any architecture PHP supports |

Recommended (not required):

| Component | Why |
|---|---|
| `opcache` extension | dramatically faster warm execution |
| systemd (Linux) | clean lifecycle management of the daemon |
| A modern kernel (Linux 4.5+) | `SO_REUSEPORT` for fair load balancing across workers |

Verify your PHP build:

```
php -v
php -m | grep -E '(pcntl|posix|sockets|json|Zend OPcache)'
```

If `pcntl` or `posix` is missing, install via your distro's php
package (e.g. `apt install php-pcntl`) or rebuild PHP with
`--enable-pcntl --enable-posix`.

## 2. Installing the `phpverse` binary

Three installation methods. Pick one.

### 2.1 Composer (recommended for PHP developers)

```
composer global require baykit/phpverse
```

This installs the package globally. The `phpverse` CLI ends up in
`~/.config/composer/vendor/bin/phpverse` (Linux) or
`~/.composer/vendor/bin/phpverse` (macOS / older Composer). Add this
directory to your `PATH` if it isn't already.

For project-local installation:

```
cd /path/to/your/project
composer require baykit/phpverse
./vendor/bin/phpverse --version
```

### 2.2 Phar download (recommended for non-Composer environments)

Self-contained archive — single file, no Composer required.

```
curl -L -o phpverse https://github.com/baykit/PHPVerse/releases/latest/download/phpverse.phar
chmod +x phpverse
sudo mv phpverse /usr/local/bin/phpverse
phpverse --version
```

The phar bundles the worker.php and all PHPVerse classes. It still
requires a system PHP CLI binary; the phar runs ON that PHP.

### 2.3 From source (for development / forking)

```
git clone git@github.com:baykit/PHPVerse.git
cd PHPVerse
composer install
./bin/phpverse --version
```

For development, run tests with:

```
./vendor/bin/phpunit
```

## 3. Verifying the install

After installing, do a one-shot smoke test:

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

Expected output:

```
Content-Type: text/html; charset=UTF-8

Hello from PHPVerse, request method = GET
```

(The exact `Content-Type` may vary depending on your PHP's default.)

## 4. Configuring Nginx as a PHPVerse client

After installing, point your web server at PHPVerse. Example for
Nginx:

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

Run PHPVerse:

```
phpverse --workers 8 --listen unix:///var/run/phpverse.sock
```

Reload Nginx and test:

```
sudo systemctl reload nginx
curl http://example.com/
```

## 5. Running as a systemd service (Linux)

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

Enable and start:

```
sudo systemctl daemon-reload
sudo systemctl enable --now phpverse
sudo systemctl status phpverse
```

## 6. Upgrading

### 6.1 Via Composer

```
composer global update baykit/phpverse
sudo systemctl restart phpverse
```

### 6.2 Via phar

```
curl -L -o /tmp/phpverse.new https://github.com/baykit/PHPVerse/releases/latest/download/phpverse.phar
chmod +x /tmp/phpverse.new
sudo mv /tmp/phpverse.new /usr/local/bin/phpverse
sudo systemctl restart phpverse
```

### 6.3 Reloading without dropping connections

Send `SIGUSR1` to the daemon to gracefully respawn workers (e.g. after
deploying new PHP code). New workers pick up changes; in-flight
requests on old workers finish first.

```
sudo systemctl reload phpverse        # = kill -USR1 $MAINPID
```

## 7. Uninstalling

### 7.1 Composer

```
composer global remove baykit/phpverse
```

### 7.2 Phar

```
sudo rm /usr/local/bin/phpverse
```

### 7.3 Systemd

```
sudo systemctl disable --now phpverse
sudo rm /etc/systemd/system/phpverse.service
sudo systemctl daemon-reload
```

## 8. Troubleshooting

### 8.1 `pcntl_fork()` undefined

The `pcntl` PHP extension is missing or disabled. Install / enable
it:

```
# Debian/Ubuntu
sudo apt install php-cli php-pcntl
# RHEL/Fedora
sudo dnf install php-cli php-pcntl
```

In some distro builds `pcntl` is only loaded in CLI mode (not web
SAPI) — that's fine, PHPVerse runs in CLI mode.

### 8.2 `socket bind: permission denied`

The user running PHPVerse can't write the Unix socket path. Either:

- Use a path the user can write to (e.g. `/tmp/phpverse.sock`).
- Run as root and use `--worker-uid` to drop privileges for workers
  while the parent keeps root for socket binding.
- Use a path under a directory owned by the running user
  (`/var/run/phpverse/` with `chown user:group`).

### 8.3 Workers exit immediately

Check the daemon log:

```
journalctl -u phpverse -f       # if systemd-managed
```

Common causes:

- `--prepend` file has a fatal error.
- PHP binary specified by `--php` is wrong.
- Missing `pcntl` / `posix` extension.

### 8.4 Slow first request after start

This is normal: workers warm up opcache on their first request.
Subsequent requests are much faster. To reduce first-request
latency, configure `opcache.preload` in your `php.ini`.

### 8.5 Memory usage grows over time

User PHP is leaking static state. Mitigate with
`--max-requests 1000` (workers retire after 1000 requests and a
fresh one replaces them). Long-term, fix the leak in user code.

## 9. Where to go next

- [README.md](README.md) — project overview.
- [MANUAL.md](MANUAL.md) — full CLI and config reference.
- [TIPS.md](TIPS.md) — deployment patterns, performance tuning,
  pitfalls.
- [SPEC.md](SPEC.md) — wire protocol and worker isolation contract
  (for implementers and client-library authors).
