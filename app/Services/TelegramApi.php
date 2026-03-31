<?php
class TelegramApi
{
    protected $token;

    public function __construct($token)
    {
        $this->token = $token;
    }

    public function call($method, array $params)
    {
        $url = 'https://api.telegram.org/bot' . $this->token . '/' . $method;
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            $out = curl_exec($ch);
            curl_close($ch);
            return $out;
        }
        $ctx = stream_context_create(array(
            'http' => array(
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => http_build_query($params),
                'timeout' => 30,
            )
        ));
        return file_get_contents($url, false, $ctx);
    }

    public function sendMessage($chatId, $text, $replyMarkup)
    {
        $params = array('chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML');
        if ($replyMarkup !== null) {
            $params['reply_markup'] = json_encode($replyMarkup);
        }
        return $this->call('sendMessage', $params);
    }

    public function sendPhoto($chatId, $photo, $caption, $replyMarkup)
    {
        $params = array('chat_id' => $chatId, 'photo' => $photo, 'caption' => $caption, 'parse_mode' => 'HTML');
        if ($replyMarkup !== null) {
            $params['reply_markup'] = json_encode($replyMarkup);
        }
        return $this->call('sendPhoto', $params);
    }

    public function editMessageText($chatId, $messageId, $text, $replyMarkup)
    {
        $params = array('chat_id' => $chatId, 'message_id' => $messageId, 'text' => $text, 'parse_mode' => 'HTML');
        if ($replyMarkup !== null) {
            $params['reply_markup'] = json_encode($replyMarkup);
        }
        return $this->call('editMessageText', $params);
    }

    public function answerCallbackQuery($id, $text)
    {
        return $this->call('answerCallbackQuery', array('callback_query_id' => $id, 'text' => $text));
    }
}
