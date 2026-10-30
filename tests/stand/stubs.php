<?php
// Minimal stand-ins for the WHMCS classes and functions the module uses.
// Only what chatwoot/*.php and tools/*.php call is implemented.

namespace {
    if (!defined('WHMCS')) {
        define('WHMCS', true);
    }

    final class CwStand
    {
        /** @var array<string, array<int, array<string, mixed>>> */
        public static $db = [];
        public static $user = null;
        public static $client = null;
        /** @var array<string, callable[]> */
        public static $hooks = [];
        /** @var array<int, array<string, mixed>> GetClientsDetails by client id */
        public static $clients = [];
        /** File that counts localAPI calls across requests. */
        public static $counter = null;

        public static function countApiCall(): void
        {
            if (self::$counter === null) {
                return;
            }
            $n = is_file(self::$counter) ? (int) file_get_contents(self::$counter) : 0;
            file_put_contents(self::$counter, (string) ($n + 1));
        }
    }

    final class CwStandUser
    {
        public $id;
        public $email;
        public $first_name;
        public $last_name;
        /** @var int[] */
        private $owns;

        public function __construct(int $id, string $email, string $first, string $last, array $owns)
        {
            $this->id = $id;
            $this->email = $email;
            $this->first_name = $first;
            $this->last_name = $last;
            $this->owns = $owns;
        }

        public function isOwner($client): bool
        {
            return $client !== null && in_array((int) $client->id, $this->owns, true);
        }
    }

    final class CwStandClient
    {
        public $id;

        public function __construct(int $id)
        {
            $this->id = $id;
        }
    }

    final class CwStandCollection implements IteratorAggregate, Countable
    {
        private $items;

        public function __construct(array $items)
        {
            $this->items = $items;
        }

        public function all(): array
        {
            return $this->items;
        }

        public function toArray(): array
        {
            return $this->items;
        }

        public function getIterator(): Iterator
        {
            return new ArrayIterator($this->items);
        }

        public function count(): int
        {
            return count($this->items);
        }
    }

    final class CwStandQuery
    {
        private $table;
        private $wheres = [];
        private $order = null;

        public function __construct(string $table)
        {
            $this->table = $table;
        }

        public function select(...$columns): self
        {
            return $this;
        }

        public function where($column, $op = null, $value = null): self
        {
            if (func_num_args() === 2) {
                $value = $op;
                $op = '=';
            }
            $this->wheres[] = [$column, $op, $value];
            return $this;
        }

        public function orderBy($column, $direction = 'asc'): self
        {
            $this->order = [$column, strtolower($direction)];
            return $this;
        }

        private function rows(): array
        {
            $rows = array_values(array_filter(CwStand::$db[$this->table] ?? [], function ($row) {
                foreach ($this->wheres as [$column, $op, $value]) {
                    $actual = $row[$column] ?? null;
                    $ok = $op === '=' ? (string) $actual === (string) $value
                        : ($op === '!=' || $op === '<>' ? (string) $actual !== (string) $value : false);
                    if (!$ok) {
                        return false;
                    }
                }
                return true;
            }));
            if ($this->order) {
                [$column, $direction] = $this->order;
                usort($rows, function ($a, $b) use ($column, $direction) {
                    $cmp = $a[$column] <=> $b[$column];
                    return $direction === 'desc' ? -$cmp : $cmp;
                });
            }
            return $rows;
        }

        public function get($columns = ['*']): CwStandCollection
        {
            return new CwStandCollection(array_map(function ($row) {
                return (object) $row;
            }, $this->rows()));
        }

        public function first($columns = ['*'])
        {
            $rows = $this->rows();
            return $rows ? (object) $rows[0] : null;
        }

        public function value($column)
        {
            $rows = $this->rows();
            return $rows ? ($rows[0][$column] ?? null) : null;
        }

        public function pluck($value, $key = null): CwStandCollection
        {
            $out = [];
            foreach ($this->rows() as $row) {
                if ($key === null) {
                    $out[] = $row[$value] ?? null;
                } else {
                    $out[$row[$key]] = $row[$value] ?? null;
                }
            }
            return new CwStandCollection($out);
        }

        public function count(): int
        {
            return count($this->rows());
        }

        public function delete(): int
        {
            $keep = [];
            $deleted = 0;
            $match = array_map('serialize', $this->rows());
            foreach (CwStand::$db[$this->table] ?? [] as $row) {
                if (in_array(serialize($row), $match, true)) {
                    $deleted++;
                } else {
                    $keep[] = $row;
                }
            }
            CwStand::$db[$this->table] = $keep;
            return $deleted;
        }
    }

    function add_hook($name, $priority, $callback)
    {
        CwStand::$hooks[$name][] = $callback;
    }

    function localAPI($command, $data)
    {
        CwStand::countApiCall();
        if ($command !== 'GetClientsDetails') {
            return ['result' => 'error', 'message' => 'not stubbed'];
        }
        $id = (int) ($data['clientid'] ?? 0);
        return CwStand::$clients[$id] ?? ['result' => 'error', 'message' => 'Client Not Found'];
    }
}

namespace WHMCS\Database {
    final class Capsule
    {
        public static function table($table)
        {
            return new \CwStandQuery($table);
        }
    }
}

namespace WHMCS\Config {
    final class Setting
    {
        public static function getValue($setting)
        {
            foreach (\CwStand::$db['tblconfiguration'] ?? [] as $row) {
                if ($row['setting'] === $setting) {
                    return $row['value'];
                }
            }
            return null;
        }
    }
}

namespace WHMCS\Authentication {
    final class CurrentUser
    {
        public function user()
        {
            return \CwStand::$user;
        }

        public function client()
        {
            return \CwStand::$client;
        }
    }
}
