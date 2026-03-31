<?php
class PaymentMethodRepository extends BaseRepository
{
    public function __construct(JsonStore $store)
    {
        parent::__construct($store, 'payment_methods');
    }

    public function active()
    {
        $out = array();
        foreach ($this->all() as $row) {
            if (!empty($row['is_active']) && empty($row['is_deleted'])) {
                $out[] = $row;
            }
        }
        usort($out, function ($a, $b) {
            $sa = isset($a['sort_order']) ? (int)$a['sort_order'] : 0;
            $sb = isset($b['sort_order']) ? (int)$b['sort_order'] : 0;
            if ($sa === $sb) {
                return $a['id'] > $b['id'] ? -1 : 1;
            }
            return $sa > $sb ? 1 : -1;
        });
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
        usort($out, function ($a, $b) {
            $sa = isset($a['sort_order']) ? (int)$a['sort_order'] : 0;
            $sb = isset($b['sort_order']) ? (int)$b['sort_order'] : 0;
            if ($sa === $sb) {
                return $a['id'] > $b['id'] ? -1 : 1;
            }
            return $sa > $sb ? 1 : -1;
        });
        return $out;
    }
}
