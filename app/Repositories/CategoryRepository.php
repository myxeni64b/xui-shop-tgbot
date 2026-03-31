<?php
class CategoryRepository extends BaseRepository
{
    public function __construct(JsonStore $store)
    {
        parent::__construct($store, 'categories');
    }

    public function active()
    {
        $out = array();
        foreach ($this->all() as $row) {
            if (!empty($row['is_active']) && empty($row['is_deleted'])) {
                $out[] = $row;
            }
        }
        return $out;
    }

    public function visible()
    {
        $out = array();
        foreach ($this->all() as $row) {
            if (empty($row['is_deleted'])) {
                $out[] = $row;
            }
        }
        return $out;
    }
}
