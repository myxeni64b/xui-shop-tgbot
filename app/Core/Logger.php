<?php
class Logger
{
    protected $dir;
    protected $enabled;

    public function __construct($dir, $enabled)
    {
        $this->dir = $dir;
        $this->enabled = (bool)$enabled;
    }

    public function error($message, array $context = array())
    {
        if (!$this->enabled) {
            return;
        }
        $line = '[' . date('Y-m-d H:i:s') . '] ERROR ' . $message;
        if ($context) {
            $line .= ' ' . json_encode($context);
        }
        $this->write('error.log', $line);
    }

    public function info($message, array $context = array())
    {
        $line = '[' . date('Y-m-d H:i:s') . '] INFO ' . $message;
        if ($context) {
            $line .= ' ' . json_encode($context);
        }
        $this->write('app.log', $line);
    }

    protected function write($file, $line)
    {
        Utils::ensureDir($this->dir, 0750);
        file_put_contents($this->dir . '/' . $file, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
