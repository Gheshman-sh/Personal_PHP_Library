<?php
declare(strict_types=1);

final class PPL
{
    private readonly string $host;
    private readonly string $username;
    private readonly string $password;
    private readonly string $database;
    private \mysqli $connection;
    private static ?self $instance = null;

    public static function getInstance(string $host, string $username, string $password, string $database): self
    {
        return self::$instance ??= new self($host, $username, $password, $database);
    }

    private function __construct(string $host, string $username, string $password, string $database)
    {
        $this->host = $host;
        $this->username = $username;
        $this->password = $password;
        $this->database = $database;
        $this->connect();
    }

    private function connect(): void
    {
        $this->connection = new \mysqli('p:' . $this->host, $this->username, $this->password, $this->database);
        if ($this->connection->connect_error) {
            throw new \RuntimeException('Connection failed: ' . $this->connection->connect_error);
        }
        $this->connection->set_charset('utf8mb4');
    }

    public function readTableWithJoin(string $table, array $joins, string $columns = '*', ?int $limit = null, int $page = 1, ?string $order = null, ?string $where = null, array $whereParams = [], bool $useFiber = false): array
    {
        $offset = max(0, $page - 1) * (int)($limit ?? 0);
        $sql = 'SELECT ' . $this->safeColumns($columns) . ' FROM ' . $this->ident($table);
        foreach ($joins as $join) {
            $type = isset($join['type']) ? strtoupper((string)$join['type']) : 'INNER';
            $tableJ = (string)($join['table'] ?? '');
            $on = (string)($join['on'] ?? '');
            $type = match ($type) {
                'INNER', 'LEFT', 'RIGHT', 'LEFT OUTER', 'RIGHT OUTER' => $type,
                default => 'INNER',
            };
            if ($tableJ === '' || $on === '') {
                throw new \InvalidArgumentException('Invalid join specification.');
            }
            $sql .= ' ' . $type . ' JOIN ' . $this->ident($tableJ) . ' ON ' . $on;
        }
        if ($where !== null && $where !== '') {
            $sql .= ' WHERE ' . $where;
        }
        if ($order !== null && $order !== '') {
            $sql .= ' ORDER BY ' . $this->safeOrder($order);
        }
        if ($limit !== null) {
            $sql .= ' LIMIT ' . (int)$limit;
            if ($offset > 0) {
                $sql .= ' OFFSET ' . $offset;
            }
        }
        return $this->runQuery($sql, $whereParams, $useFiber);
    }

    public function readTable(string $table, ?int $limit = null, ?string $order = null, ?string $where = null, array $whereParams = [], bool $useFiber = false): array
    {
        $sql = 'SELECT * FROM ' . $this->ident($table);
        if ($where !== null && $where !== '') {
            $sql .= ' WHERE ' . $where;
        }
        if ($order !== null && $order !== '') {
            $sql .= ' ORDER BY ' . $this->safeOrder($order);
        }
        if ($limit !== null) {
            $sql .= ' LIMIT ' . (int)$limit;
        }
        return $this->runQuery($sql, $whereParams, $useFiber);
    }

    public function readTablePaginated(string $table, int $limitPerPage, int $page = 1, ?string $order = null, ?string $where = null, array $whereParams = [], bool $useFiber = false): array
    {
        $offset = max(0, $page - 1) * $limitPerPage;
        $sql = 'SELECT * FROM ' . $this->ident($table);
        if ($where !== null && $where !== '') {
            $sql .= ' WHERE ' . $where;
        }
        if ($order !== null && $order !== '') {
            $sql .= ' ORDER BY ' . $this->safeOrder($order);
        }
        $sql .= ' LIMIT ' . (int)$limitPerPage . ' OFFSET ' . (int)$offset;
        return $this->runQuery($sql, $whereParams, $useFiber);
    }

    public function partialSearch(string $table, string $columnName, string $search, ?int $limit = null, ?string $order = null, bool $useFiber = false): array
    {
        $sql = 'SELECT * FROM ' . $this->ident($table) . ' WHERE ' . $this->ident($columnName) . ' LIKE ?';
        $params = ['%' . $search . '%'];
        if ($order !== null && $order !== '') {
            $sql .= ' ORDER BY ' . $this->safeOrder($order);
        }
        if ($limit !== null) {
            $sql .= ' LIMIT ' . (int)$limit;
        }
        return $this->runQuery($sql, $params, $useFiber);
    }

    public function updateRecord(string $table, array $values, string $where, array $whereParams = [], bool $useFiber = false): int
    {
        if ($values === []) {
            return 0;
        }
        $setParts = [];
        $params = [];
        foreach ($values as $k => $v) {
            $setParts[] = $this->ident((string)$k) . ' = ?';
            $params[] = $v;
        }
        $sql = 'UPDATE ' . $this->ident($table) . ' SET ' . implode(', ', $setParts) . ' WHERE ' . $where;
        $params = array_merge($params, $whereParams);
        return $this->runExecute($sql, $params, $useFiber);
    }

    public function writeRecord(string $table, array $values, bool $useFiber = false): int
    {
        if ($values === []) {
            throw new \InvalidArgumentException('Values cannot be empty for insert.');
        }
        $cols = array_map(fn($c) => $this->ident((string)$c), array_keys($values));
        $placeholders = implode(', ', array_fill(0, count($values), '?'));
        $sql = 'INSERT INTO ' . $this->ident($table) . ' (' . implode(', ', $cols) . ') VALUES (' . $placeholders . ')';
        return $this->runInsert($sql, array_values($values), $useFiber);
    }

    public function deleteRecord(string $table, string $where, array $whereParams = [], bool $useFiber = false): int
    {
        $sql = 'DELETE FROM ' . $this->ident($table) . ' WHERE ' . $where;
        return $this->runExecute($sql, $whereParams, $useFiber);
    }

    public function customQuery(string $query, array $params = [], bool $useFiber = false): array
    {
        $trim = ltrim($query);
        $isSelect = strncasecmp($trim, 'SELECT', 6) === 0 || strncasecmp($trim, 'SHOW', 4) === 0 || strncasecmp($trim, 'DESCRIBE', 8) === 0;
        if ($isSelect) {
            return $this->runQuery($query, $params, $useFiber);
        }
        $affected = $this->runExecute($query, $params, $useFiber);
        return ['affected_rows' => $affected];
    }

    public function countRows(string $table, ?string $where = null, array $whereParams = [], bool $useFiber = false): int
    {
        $sql = 'SELECT COUNT(*) AS cnt FROM ' . $this->ident($table);
        if ($where !== null && $where !== '') {
            $sql .= ' WHERE ' . $where;
        }
        $rows = $this->runQuery($sql, $whereParams, $useFiber);
        return (int)($rows[0]['cnt'] ?? 0);
    }

    public function countPartialSearch(string $table, string $columnName, string $search, bool $useFiber = false): int
    {
        $sql = 'SELECT COUNT(*) AS cnt FROM ' . $this->ident($table) . ' WHERE ' . $this->ident($columnName) . ' LIKE ?';
        $rows = $this->runQuery($sql, ['%' . $search . '%'], $useFiber);
        return (int)($rows[0]['cnt'] ?? 0);
    }

    public function transaction(callable $work): mixed
    {
        $this->connection->begin_transaction();
        try {
            $result = $work($this);
            $this->connection->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->connection->rollback();
            throw $e;
        }
    }

    public function closeDBConnection(): void
    {
        $this->connection->close();
    }

    private function runQuery(string $sql, array $params = [], bool $useFiber = false): array
    {
        $runner = function () use ($sql, $params): array {
            if ($params === []) {
                $result = $this->connection->query($sql);
                if ($result === false) {
                    throw new \RuntimeException('Query error: ' . $this->connection->error);
                }
                return $this->fetchAllAssoc($result);
            }
            $stmt = $this->prepareAndBind($sql, $params);
            $this->execOrThrow($stmt);
            $res = $stmt->get_result();
            if ($res === false) {
                return $this->fetchAllAssocCompat($stmt);
            }
            return $this->fetchAllAssoc($res);
        };
        return $useFiber ? $this->runInFiber($runner) : $runner();
    }

    private function runExecute(string $sql, array $params = [], bool $useFiber = false): int
    {
        $runner = function () use ($sql, $params): int {
            if ($params === []) {
                $ok = $this->connection->query($sql);
                if ($ok === false) {
                    throw new \RuntimeException('Execute error: ' . $this->connection->error);
                }
                return $this->connection->affected_rows;
            }
            $stmt = $this->prepareAndBind($sql, $params);
            $this->execOrThrow($stmt);
            return $stmt->affected_rows;
        };
        return $useFiber ? $this->runInFiber($runner) : $runner();
    }

    private function runInsert(string $sql, array $params = [], bool $useFiber = false): int
    {
        $runner = function () use ($sql, $params): int {
            if ($params === []) {
                $ok = $this->connection->query($sql);
                if ($ok === false) {
                    throw new \RuntimeException('Insert error: ' . $this->connection->error);
                }
                return (int)$this->connection->insert_id;
            }
            $stmt = $this->prepareAndBind($sql, $params);
            $this->execOrThrow($stmt);
            return (int)$stmt->insert_id;
        };
        return $useFiber ? $this->runInFiber($runner) : $runner();
    }

    private function prepareAndBind(string $sql, array $params): \mysqli_stmt
    {
        $stmt = $this->connection->prepare($sql);
        if ($stmt === false) {
            throw new \RuntimeException('Unable to prepare statement: ' . $this->connection->error);
        }
        $types = $this->inferTypes($params);
        if ($params !== []) {
            $refs = [];
            foreach ($params as $k => $v) {
                $refs[$k] = &$params[$k];
            }
            $stmt->bind_param($types, ...$refs);
        }
        return $stmt;
    }

    private function execOrThrow(\mysqli_stmt $stmt): void
    {
        if (!$stmt->execute()) {
            throw new \RuntimeException('Execute failed: (' . $stmt->errno . ') ' . $stmt->error);
        }
    }

    private function runInFiber(callable $fn): mixed
    {
        if (!class_exists(\Fiber::class)) {
            return $fn();
        }
        $fiber = new \Fiber(function () use ($fn) {
            return $fn();
        });
        return $fiber->start();
    }

    private function fetchAllAssoc(\mysqli_result $res): array
    {
        $rows = $res->fetch_all(MYSQLI_ASSOC);
        return $rows ?: [];
    }

    private function fetchAllAssocCompat(\mysqli_stmt $stmt): array
    {
        $meta = $stmt->result_metadata();
        if ($meta === false) {
            return [];
        }
        $fields = [];
        $row = [];
        $binds = [];
        while ($field = $meta->fetch_field()) {
            $fields[] = $field->name;
            $row[$field->name] = null;
            $binds[] = &$row[$field->name];
        }
        $stmt->bind_result(...$binds);
        $data = [];
        while ($stmt->fetch()) {
            $data[] = array_combine($fields, array_map(static fn($v) => $v, $row));
        }
        return $data;
    }

    private function inferTypes(array $params): string
    {
        $types = '';
        foreach ($params as $v) {
            $types .= match (true) {
                is_int($v) => 'i',
                is_float($v) => 'd',
                is_null($v) => 's',
                default => 's',
            };
        }
        return $types;
    }

    public function escape_string(string $string): string
    {
        return $this->connection->real_escape_string($string);
    }

    private function ident(string $name): string
    {
        $name = trim($name);
        $parts = preg_split('/\s+/', $name, 2);
        $id = $parts[0] ?? '';
        $alias = $parts[1] ?? null;
        $segments = explode('.', $id);
        foreach ($segments as $seg) {
            if (!preg_match('/^[A-Za-z0-9_]+$/', $seg)) {
                throw new \InvalidArgumentException("Invalid identifier segment: {$seg}");
            }
        }
        $quoted = implode('.', array_map(fn($s) => '`' . $s . '`', $segments));
        if ($alias !== null) {
            if (!preg_match('/^[A-Za-z0-9_]+$/', $alias)) {
                throw new \InvalidArgumentException("Invalid alias: {$alias}");
            }
            return $quoted . ' ' . $alias;
        }
        return $quoted;
    }

    private function safeColumns(string $cols): string
    {
        $cols = trim($cols);
        if ($cols === '*') {
            return '*';
        }
        $out = [];
        foreach (explode(',', $cols) as $raw) {
            $raw = trim($raw);
            if (preg_match('/^([A-Za-z0-9_]+)\.\*$/', $raw, $m)) {
                $out[] = '`' . $m[1] . '`.*';
                continue;
            }
            if (str_contains($raw, '(') || str_contains($raw, ')')) {
                $out[] = $raw;
                continue;
            }
            $parts = preg_split('/\s+AS\s+/i', $raw);
            if (count($parts) === 2) {
                $out[] = $this->ident($parts[0]) . ' AS ' . $this->ident($parts[1]);
                continue;
            }
            $out[] = $this->ident($raw);
        }
        return implode(', ', $out);
    }

    private function safeOrder(string $order): string
    {
        $parts = array_map('trim', explode(',', $order));
        $safe = [];
        foreach ($parts as $p) {
            if ($p === '') continue;
            $tokens = preg_split('/\s+/', $p);
            $col = $this->ident($tokens[0]);
            $dir = '';
            if (isset($tokens[1])) {
                $d = strtoupper($tokens[1]);
                $dir = in_array($d, ['ASC', 'DESC'], true) ? ' ' . $d : '';
            }
            $safe[] = $col . $dir;
        }
        return implode(', ', $safe);
    }
}
