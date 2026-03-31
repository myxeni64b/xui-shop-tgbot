<?php
class ItemRepository extends BaseRepository
{
    public function __construct(JsonStore $store)
    {
        parent::__construct($store, 'items');
    }

    public function byCategory($categoryId)
    {
        $out = array();
        foreach ($this->all() as $row) {
            if ((string)$row['category_id'] === (string)$categoryId) {
                $out[] = $row;
            }
        }
        return $out;
    }

    public function availableByCategory($categoryId)
    {
        $out = array();
        foreach ($this->byCategory($categoryId) as $row) {
            if ($row['status'] === 'available') {
                $out[] = $row;
            }
        }
        return $out;
    }
}
