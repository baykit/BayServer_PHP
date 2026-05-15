<?php

declare(strict_types=1);

namespace phpverse\Isolation;

/**
 * Per-request superglobal + CWD reset (SPEC §5.1).
 *
 * Multipart bodies are NOT auto-parsed in v1: $_FILES is always []
 * and user code is expected to consume the raw body via php://input
 * when CONTENT_TYPE is multipart/form-data.
 */
final class RequestScope
{
    /** @var array<string,string> */
    private array $params;

    private string $stdin;

    private string $savedCwd = '';

    private bool $activated = false;

    /** @param array<string,string> $params */
    public function __construct(array $params, string $stdin)
    {
        $this->params = $params;
        $this->stdin  = $stdin;
    }

    public function activate(): void
    {
        $_SERVER = $this->params;

        $_GET     = [];
        $_POST    = [];
        $_COOKIE  = [];
        $_FILES   = [];
        $_ENV     = [];

        if (isset($this->params['QUERY_STRING']) && $this->params['QUERY_STRING'] !== '') {
            parse_str($this->params['QUERY_STRING'], $_GET);
        }

        $ct = $this->params['CONTENT_TYPE'] ?? '';
        // strip "; charset=..."  before comparing
        $ctMain = trim(strtolower(strstr($ct, ';', true) ?: $ct));
        if ($this->stdin !== '' && $ctMain === 'application/x-www-form-urlencoded') {
            parse_str($this->stdin, $_POST);
        }

        if (isset($this->params['HTTP_COOKIE']) && $this->params['HTTP_COOKIE'] !== '') {
            foreach (explode(';', $this->params['HTTP_COOKIE']) as $pair) {
                $pair = trim($pair);
                if ($pair === '') {
                    continue;
                }
                $eq = strpos($pair, '=');
                if ($eq === false) {
                    $_COOKIE[urldecode($pair)] = '';
                } else {
                    $_COOKIE[urldecode(substr($pair, 0, $eq))] = urldecode(substr($pair, $eq + 1));
                }
            }
        }

        // GP + cookies, first-wins via array union (matches PHP's default
        // request_order="GP" plus an explicit cookie merge).
        $_REQUEST = $_GET + $_POST + $_COOKIE;

        if (!headers_sent()) {
            header_remove();
        }

        $cwd = getcwd();
        $this->savedCwd = is_string($cwd) ? $cwd : '';

        $script = $this->params['SCRIPT_FILENAME'] ?? '';
        if ($script !== '' && is_file($script)) {
            @chdir(dirname($script));
        }

        $this->activated = true;
    }

    public function restore(): void
    {
        if (!$this->activated) {
            return;
        }
        if ($this->savedCwd !== '') {
            @chdir($this->savedCwd);
        }
        $_SERVER  = [];
        $_GET     = [];
        $_POST    = [];
        $_COOKIE  = [];
        $_REQUEST = [];
        $_FILES   = [];
        $_ENV     = [];

        if (!headers_sent()) {
            header_remove();
        }

        $this->activated = false;
    }
}
