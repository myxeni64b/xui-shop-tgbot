<?php
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', '0');

$root = dirname(__DIR__);
$config = require $root . '/config.php';

require_once $root . '/app/Core/Autoloader.php';
Autoloader::register($root . '/app');

if (!class_exists('BotService', false)) {
    require_once $root . '/app/Services/BotService.php';
}

$bot = new BotService($config);
$bot->run();
