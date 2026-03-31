<?php
class SettingRepository
{
    protected $store;
    protected $name = 'settings';

    public function __construct(JsonStore $store)
    {
        $this->store = $store;
    }

    public function all()
    {
        return $this->store->read($this->name, array());
    }

    public function get($key, $default = '')
    {
        $all = $this->all();
        return isset($all[$key]) ? $all[$key] : $default;
    }

    public function set($key, $value)
    {
        $this->store->mutate($this->name, array(), function ($data) use ($key, $value) {
            $data[$key] = $value;
            return $data;
        });
    }
}
