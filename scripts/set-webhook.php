<?php
$root = dirname(__DIR__);
$config = require $root . '/config.php';
$url = rtrim($config['base_url'], '/') . '/bot.php';
$params = array('url' => $url);
if (!empty($config['security']['validate_webhook_secret'])) {
    $params['secret_token'] = $config['security']['webhook_secret'];
}
$api = 'https://api.telegram.org/bot' . $config['bot_token'] . '/setWebhook?' . http_build_query($params);
echo file_get_contents($api) . PHP_EOL;
