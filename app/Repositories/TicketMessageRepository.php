<?php
class TicketMessageRepository extends BaseRepository
{
    public function __construct(JsonStore $store)
    {
        parent::__construct($store, 'ticket_messages');
    }
}
