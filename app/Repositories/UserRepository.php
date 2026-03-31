<?php
class UserRepository extends BaseRepository
{
    public function __construct(JsonStore $store)
    {
        parent::__construct($store, 'users');
    }

    public function findByTelegramId($telegramId)
    {
        foreach ($this->all() as $row) {
            if ((string)$row['telegram_id'] === (string)$telegramId) {
                return $row;
            }
        }
        return null;
    }

    public function saveByTelegramId($telegramId, array $data)
    {
        $user = $this->findByTelegramId($telegramId);
        if (!$user) {
            return $this->insert($data);
        }
        return $this->updateById($user['id'], $data);
    }
}
