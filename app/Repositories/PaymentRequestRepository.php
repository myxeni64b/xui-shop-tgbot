<?php
class PaymentRequestRepository extends BaseRepository
{
    public function __construct(JsonStore $store)
    {
        parent::__construct($store, 'payment_requests');
    }

    public function pending()
    {
        $out = array();
        foreach ($this->all() as $row) {
            if ($row['status'] === 'pending') {
                $out[] = $row;
            }
        }
        usort($out, function ($a, $b) {
            return $a['id'] > $b['id'] ? 1 : -1;
        });
        return $out;
    }
}
