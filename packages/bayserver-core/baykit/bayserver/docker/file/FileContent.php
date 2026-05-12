<?php

namespace baykit\bayserver\docker\file;

/**
 * Cached representation of a static file's bytes.
 * For PHP we keep this simple: load once synchronously, hold the
 * full content as a string. No async-loading state machine is needed.
 */
class FileContent
{
    public string $path;
    public string $content;
    public int $loadedTime;

    public function __construct(string $path, string $content)
    {
        $this->path = $path;
        $this->content = $content;
        $this->loadedTime = time();
    }

    public function length() : int
    {
        return strlen($this->content);
    }
}
