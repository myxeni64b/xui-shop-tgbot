<?php
class RateLimiter
{
    protected $store;

    public function __construct(JsonStore $store)
    {
        $this->store = $store;
    }

    public function hit($key, $limit, $window)
    {
        $allowed = true;
        $retryAfter = 0;
        $name = 'ratelimit_' . sha1($key);
        $now = time();
        $this->store->mutate($name, array('hits' => array()), function ($data) use ($now, $limit, $window, &$allowed, &$retryAfter) {
            $hits = array();
            if (isset($data['hits']) && is_array($data['hits'])) {
                foreach ($data['hits'] as $ts) {
                    if ((int)$ts > ($now - $window)) {
                        $hits[] = (int)$ts;
                    }
                }
            }
            if (count($hits) >= $limit) {
                $allowed = false;
                sort($hits);
                $retryAfter = ($hits[0] + $window) - $now;
            } else {
                $hits[] = $now;
                $allowed = true;
            }
            return array('hits' => $hits);
        });
        return array($allowed, $retryAfter);
    }
}
