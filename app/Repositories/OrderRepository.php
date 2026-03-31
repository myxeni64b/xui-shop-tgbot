<?php
class OrderRepository extends BaseRepository
{
    public function __construct(JsonStore $store)
    {
        parent::__construct($store, 'orders');
    }

    public function byUser($telegramId)
    {
        $out = array();
        foreach ($this->all() as $row) {
            if ((string)$row['user_telegram_id'] === (string)$telegramId) {
                $out[] = $row;
            }
        }
        usort($out, function ($a, $b) {
            return $a['id'] < $b['id'] ? 1 : -1;
        });
        return $out;
    }
}
