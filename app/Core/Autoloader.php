<?php
class Autoloader
{
    protected static $registered = false;
    protected static $map = array();

    public static function register($baseDir)
    {
        if (!is_dir($baseDir)) {
            throw new RuntimeException('Autoloader base directory not found: ' . $baseDir);
        }

        if (!self::$registered) {
            spl_autoload_register(array(__CLASS__, 'autoload'));
            self::$registered = true;
        }

        self::indexDirectory($baseDir);
    }

    protected static function autoload($class)
    {
        $class = ltrim((string)$class, '\\');

        if (isset(self::$map[$class]) && is_file(self::$map[$class])) {
            require_once self::$map[$class];
            return;
        }

        $short = basename(str_replace('\\', '/', $class));
        if (isset(self::$map[$short]) && is_file(self::$map[$short])) {
            require_once self::$map[$short];
        }
    }

    protected static function indexDirectory($baseDir)
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($baseDir, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }
            if (strtolower($fileInfo->getExtension()) !== 'php') {
                continue;
            }

            $path = $fileInfo->getPathname();
            $class = $fileInfo->getBasename('.php');
            if (!isset(self::$map[$class])) {
                self::$map[$class] = $path;
            }
        }
    }
}
