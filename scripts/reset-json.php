<?php
$root = dirname(__DIR__);
$config = require $root . '/config.php';
$dir = $config['storage']['data_dir'];
foreach (glob($dir . '/*.json') as $file) {
    unlink($file);
}
echo "JSON data reset done.\n";
