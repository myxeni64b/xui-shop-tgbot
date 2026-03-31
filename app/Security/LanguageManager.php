<?php
class LanguageManager
{
    protected $dir;
    protected $cache = array();

    public function __construct($dir)
    {
        $this->dir = $dir;
        Utils::ensureDir($dir, 0750);
    }

    public function load($lang)
    {
        $lang = preg_replace('/[^a-z_]/i', '', $lang);
        if (isset($this->cache[$lang])) {
            return $this->cache[$lang];
        }
        $fileJson = $this->dir . '/' . $lang . '.json';
        $fileIni  = $this->dir . '/' . $lang . '.ini';
        $data = array();
        if (is_file($fileJson)) {
            $decoded = json_decode(file_get_contents($fileJson), true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        } elseif (is_file($fileIni)) {
            $parsed = parse_ini_file($fileIni, false, INI_SCANNER_RAW);
            if (is_array($parsed)) {
                $data = $parsed;
            }
        }
        $this->cache[$lang] = $data;
        return $data;
    }

    public function t($lang, $key)
    {
        $data = $this->load($lang);
        if (isset($data[$key])) {
            return $data[$key];
        }
        if ($lang !== 'en') {
            $fallback = $this->load('en');
            if (isset($fallback[$key])) {
                return $fallback[$key];
            }
        }
        return $key;
    }
}
