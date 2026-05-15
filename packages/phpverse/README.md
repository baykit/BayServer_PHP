> Languages: **English** | [日本語](README.ja.md)

# PHPVerse

A modern, embeddable FCGI worker pool for PHP.

PHPVerse runs PHP scripts in a pool of long-lived child processes
managed by a parent. It speaks standard **FastCGI 1.1** on the wire, so
any FCGI client (Nginx, Apache, HAProxy, Caddy, custom web servers) can
talk to it without modification. Workers each host a PHP interpreter
and serve requests sequentially.

The pool can be supervised by:

- The `phpverse` binary (= **daemon mode**, like php-fpm).
- A host application (web server) that forks workers directly
  (= **library mode**, like mod_php but with process isolation).

## Why

| Compared to | Distinction |
|---|---|
| **php-fpm** | Embeddable as a library (host can fork workers directly), no external daemon required, modern codebase. |
| **mod_php / FrankenPHP** | Process isolation (user PHP crash kills one worker, not the host), no host-language FFI needed (the worker is just `php worker.php`). |
| **CGI / php-cgi** | No per-request fork/exec; workers stay warm with opcache hot. |

## Quick start

Daemon mode (= php-fpm replacement):

```
phpverse --workers 8 --listen unix:///var/run/phpverse.sock
```

Then in Nginx:

```nginx
location ~ \.php$ {
    fastcgi_pass unix:/var/run/phpverse.sock;
    include fastcgi_params;
}
```

See [INSTALL.md](INSTALL.md) to install, [MANUAL.md](MANUAL.md) for the
full CLI / config, [TIPS.md](TIPS.md) for deployment patterns and
performance tuning.

## Documentation

| File | Audience | Contents |
|---|---|---|
| [SPEC.md](SPEC.md)     | implementers + client authors | wire protocol (FCGI profile), worker isolation contract, lifecycle semantics, acceptance criteria. The immutable contract. |
| [MANUAL.md](MANUAL.md) | operators                     | CLI flags, config file, environment variables, signals, logging. Equivalent to a man page. |
| [TIPS.md](TIPS.md)     | application developers        | deployment patterns (daemon vs library × per-agent vs global), web-server-specific recipes (BayServer, Nginx, Apache), performance tuning, common pitfalls. |
| [INSTALL.md](INSTALL.md) | system administrators       | system requirements, install via Composer / phar, verifying installation. |
| [LICENSE](LICENSE)     | everyone                      | Baykit Public License v1.1. |

## License

[Baykit Public License v1.1](LICENSE) — based on Apache Software License
1.1. Same license as the rest of the baykit family of projects.

## Naming conventions (authoritative table in SPEC.md §1.1)

| Identifier | Value |
|---|---|
| PHP namespace (inside `src/`) | `phpverse\` |
| Composer package | `baykit/phpverse` |
| Source-package for non-PHP client libraries | `baykit.phpverse.*` |
| GitHub repository | `baykit/PHPVerse` |

## Project status

Pre-implementation. The specification (SPEC.md) and operator manual
(MANUAL.md) define the v1.0 contract; the implementing agent works from
SPEC.md §8 ("Implementation TODO list"). No source code yet.
