<?php
class FileLock
{
    protected $dir;

    public function __construct($dir)
    {
        $this->dir = $dir;
        Utils::ensureDir($dir, 0750);
    }

    public function acquire($key)
    {
        $path = $this->dir . '/' . sha1($key) . '.lock';
        $fp = fopen($path, 'c+');
        if (!$fp) {
            throw new Exception('Unable to open lock');
        }
        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            throw new Exception('Unable to acquire lock');
        }
        return $fp;
    }

    public function release($fp)
    {
        if (is_resource($fp)) {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }
}
