<?php
class JsonStore
{
    protected $baseDir;
    protected $perm;

    public function __construct($baseDir, $perm)
    {
        $this->baseDir = $baseDir;
        $this->perm = $perm;
        Utils::ensureDir($baseDir, 0750);
    }

    protected function path($name)
    {
        return $this->baseDir . '/' . $name . '.json';
    }

    public function read($name, $default = array())
    {
        $path = $this->path($name);
        if (!is_file($path)) {
            return $default;
        }
        $content = file_get_contents($path);
        if ($content === false || $content === '') {
            return $default;
        }
        $data = json_decode($content, true);
        return is_array($data) ? $data : $default;
    }

    public function write($name, array $data)
    {
        $path = $this->path($name);
        $tmp = $path . '.tmp';
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        file_put_contents($tmp, $json, LOCK_EX);
        @chmod($tmp, $this->perm);
        rename($tmp, $path);
        @chmod($path, $this->perm);
        return true;
    }

    public function mutate($name, $default, callable $fn)
    {
        $path = $this->path($name);
        Utils::ensureDir(dirname($path), 0750);
        $fp = fopen($path, 'c+');
        if (!$fp) {
            throw new Exception('Unable to open store: ' . $name);
        }
        try {
            if (!flock($fp, LOCK_EX)) {
                throw new Exception('Unable to lock store: ' . $name);
            }
            $content = stream_get_contents($fp);
            $data = $default;
            if ($content !== false && $content !== '') {
                $decoded = json_decode($content, true);
                if (is_array($decoded)) {
                    $data = $decoded;
                }
            }
            $newData = call_user_func($fn, $data);
            if (!is_array($newData)) {
                $newData = $data;
            }
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($newData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            fflush($fp);
            flock($fp, LOCK_UN);
            fclose($fp);
            @chmod($path, $this->perm);
            return $newData;
        } catch (Exception $e) {
            fclose($fp);
            throw $e;
        }
    }
}
