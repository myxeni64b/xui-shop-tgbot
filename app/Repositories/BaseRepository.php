<?php
class BaseRepository
{
    protected $store;
    protected $name;

    public function __construct(JsonStore $store, $name)
    {
        $this->store = $store;
        $this->name = $name;
    }

    protected function allData()
    {
        return $this->store->read($this->name, array('last_id' => 0, 'rows' => array()));
    }

    protected function mutate(callable $fn)
    {
        return $this->store->mutate($this->name, array('last_id' => 0, 'rows' => array()), $fn);
    }

    public function all()
    {
        $data = $this->allData();
        return isset($data['rows']) ? $data['rows'] : array();
    }

    public function findById($id)
    {
        $rows = $this->all();
        foreach ($rows as $row) {
            if ((string)$row['id'] === (string)$id) {
                return $row;
            }
        }
        return null;
    }

    public function insert(array $row)
    {
        $result = null;
        $this->mutate(function ($data) use ($row, &$result) {
            $data['last_id'] = isset($data['last_id']) ? ((int)$data['last_id'] + 1) : 1;
            $row['id'] = $data['last_id'];
            $data['rows'][] = $row;
            $result = $row;
            return $data;
        });
        return $result;
    }

    public function updateById($id, array $newValues)
    {
        $updated = null;
        $this->mutate(function ($data) use ($id, $newValues, &$updated) {
            foreach ($data['rows'] as $k => $row) {
                if ((string)$row['id'] === (string)$id) {
                    $data['rows'][$k] = array_merge($row, $newValues);
                    $updated = $data['rows'][$k];
                    break;
                }
            }
            return $data;
        });
        return $updated;
    }

    public function deleteById($id)
    {
        $deleted = false;
        $this->mutate(function ($data) use ($id, &$deleted) {
            $rows = array();
            foreach ($data['rows'] as $row) {
                if ((string)$row['id'] === (string)$id) {
                    $deleted = true;
                    continue;
                }
                $rows[] = $row;
            }
            $data['rows'] = array_values($rows);
            return $data;
        });
        return $deleted;
    }
}
