<?php
class Utils
{
    public static function now()
    {
        return date('Y-m-d H:i:s');
    }

    public static function nowTs()
    {
        return time();
    }

    public static function h($value)
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }

    public static function safeText($value, $maxLen)
    {
        $value = trim((string)$value);
        $value = preg_replace('/\x00+/','', $value);
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $maxLen, 'UTF-8');
        }
        return substr($value, 0, $maxLen);
    }

    public static function normalizeLineEndings($value)
    {
        return preg_replace("/(\r\n|\r|\n)/", "\n", (string)$value);
    }

    public static function explodeLines($value)
    {
        $value = self::normalizeLineEndings($value);
        return explode("\n", $value);
    }

    public static function isPositiveAmount($value)
    {
        return is_numeric($value) && (float)$value > 0;
    }

    public static function fmtMoney($number)
    {
        return number_format((float)$number, 2, '.', '');
    }

    public static function randomToken($len)
    {
        if (function_exists('random_bytes')) {
            return bin2hex(random_bytes((int)ceil($len / 2)));
        }
        if (function_exists('openssl_random_pseudo_bytes')) {
            return bin2hex(openssl_random_pseudo_bytes((int)ceil($len / 2)));
        }
        $s = '';
        while (strlen($s) < $len) {
            $s .= md5(uniqid(mt_rand(), true));
        }
        return substr($s, 0, $len);
    }

    public static function ensureDir($dir, $perm)
    {
        if (!is_dir($dir)) {
            mkdir($dir, $perm, true);
        }
    }

    public static function sanitizeUsername($username)
    {
        $username = trim((string)$username);
        return preg_replace('/[^A-Za-z0-9_]/', '', $username);
    }

    public static function normalizeAmountInput($value)
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }
        $value = preg_replace('/[^0-9\.,]/', '', $value);
        if ($value === '') {
            return null;
        }
        $lastDot = strrpos($value, '.');
        $lastComma = strrpos($value, ',');
        $decimalSep = null;
        if ($lastDot !== false && $lastComma !== false) {
            $decimalSep = $lastDot > $lastComma ? '.' : ',';
        } elseif ($lastDot !== false) {
            $decimalSep = (substr_count($value, '.') === 1 && strlen(substr($value, $lastDot + 1)) <= 2) ? '.' : null;
        } elseif ($lastComma !== false) {
            $decimalSep = (substr_count($value, ',') === 1 && strlen(substr($value, $lastComma + 1)) <= 2) ? ',' : null;
        }

        if ($decimalSep === '.') {
            $value = str_replace(',', '', $value);
        } elseif ($decimalSep === ',') {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } else {
            $value = str_replace(array('.', ','), '', $value);
        }

        if ($value === '' || !preg_match('/^[0-9]+(?:\.[0-9]+)?$/', $value)) {
            return null;
        }

        return number_format((float)$value, 2, '.', '');
    }

    public static function looksLikeLink($value)
    {
        $value = trim((string)$value);
        if ($value === '') {
            return false;
        }
        return (bool)preg_match('/^(https?:\/\/|tg:\/\/|vmess:\/\/|vless:\/\/|trojan:\/\/|ss:\/\/|ssr:\/\/|tuic:\/\/|hysteria2?:\/\/)/i', $value);
    }


    public static function parseCallbackData($data)
    {
        return trim((string)$data);
    }
}

