<?php

class Database
{
    private $host;
    private $username;
    private $password;
    private $database;
    private $connection;
    private static $instance = null;

    public static function getInstance($host, $username, $password, $database)
    {
        if (self::$instance === null) {
            self::$instance = new self($host, $username, $password, $database);
        }
        return self::$instance;
    }

    private function __construct($host, $username, $password, $database)
    {
        $this->host = $host;
        $this->username = $username;
        $this->password = $password;
        $this->database = $database;

        $this->connect();
    }

    private function connect()
    {
        $this->connection = new mysqli($this->host, $this->username, $this->password, $this->database);

        if ($this->connection->connect_error) {
            throw new Exception("Connection failed: " . $this->connection->connect_error);
        }
    }

    public function readTableWithJoin($table, $joins, $columns, $limit = null, $page = 1, $order = null, $where = null)
    {
        $offset = ($page - 1) * ($limit ?? 0);
        $sql = "SELECT {$columns} FROM {$table}";

        foreach ($joins as $join) {
            $sql .= " {$join['type']} JOIN {$join['table']} ON {$join['on']}";
        }

        if ($where) {
            $sql .= " WHERE {$where}";
        }

        if ($order) {
            $sql .= " ORDER BY {$order}";
        }

        if ($limit) {
            $sql .= " LIMIT {$limit} OFFSET {$offset}";
        }

        return $this->executeQuery($sql);
    }

    public function readTable($table, $limit = null, $order = null, $where = null)
    {
        $sql = "SELECT * FROM {$table}";
        $sql .= $this->buildWhereClause($where);
        $sql .= $this->buildOrderClause($order);
        $sql .= $this->buildLimitClause($limit);

        return $this->executeQuery($sql);
    }

    public function readTablePaginated($table, $limitPerPage, $page = 1, $order = null, $where = null)
    {
        $offset = ($page - 1) * $limitPerPage;
        $sql = "SELECT * FROM {$table}";
        $sql .= $this->buildWhereClause($where);
        $sql .= $this->buildOrderClause($order);
        $sql .= $this->buildLimitClause($limitPerPage, $offset);

        return $this->executeQuery($sql);
    }

    public function partialSearch($table, $column_name, $search_variable, $limit = null, $order = null)
    {
        $search_term = '%' . $this->escape($search_variable) . '%';
        $sql = "SELECT * FROM {$table} WHERE {$column_name} LIKE ?";
        $sql .= $this->buildOrderClause($order);
        $sql .= $this->buildLimitClause($limit);

        $stmt = $this->connection->prepare($sql);
        if ($stmt === false) {
            throw new Exception("Error: Unable to prepare statement");
        }

        $stmt->bind_param("s", $search_term);
        $stmt->execute();

        return $this->fetchResults($stmt);
    }

    public function updateRecord($table, $values, $where)
    {
        $set = [];
        foreach ($values as $key => $value) {
            $set[] = "{$key} = ?";
        }

        $setClause = implode(", ", $set);
        $sql = "UPDATE {$table} SET {$setClause} WHERE {$where}";

        $stmt = $this->connection->prepare($sql);
        if ($stmt === false) {
            throw new Exception("Error: Unable to prepare statement");
        }

        $this->bindValues($stmt, array_values($values));
        $stmt->execute();

        return $stmt->affected_rows > 0;
    }

    public function writeRecord($table, $values)
    {
        $keys = implode(", ", array_keys($values));
        $placeholders = implode(", ", array_fill(0, count($values), "?"));
        $sql = "INSERT INTO {$table} ({$keys}) VALUES ({$placeholders})";

        $stmt = $this->connection->prepare($sql);
        if ($stmt === false) {
            throw new Exception("Unable to prepare statement: " . $this->connection->error);
        }

        $this->bindValues($stmt, array_values($values));
        if (!$stmt->execute()) {
            throw new Exception("Execute failed: (" . $stmt->errno . ") " . $stmt->error);
        }

        return $stmt->insert_id;
    }

    public function deleteRecord($table, $where)
    {
        $sql = "DELETE FROM {$table} WHERE {$where}";

        $stmt = $this->connection->prepare($sql);
        if ($stmt === false) {
            throw new Exception("Error: Unable to prepare statement");
        }

        $stmt->execute();

        return $stmt->affected_rows > 0;
    }

    public function batchInsert($table, $columns, $values)
    {
        $columnsString = implode(", ", $columns);
        $placeholders = implode(", ", array_fill(0, count($columns), "?"));
        $sql = "INSERT INTO {$table} ({$columnsString}) VALUES ({$placeholders})";

        $stmt = $this->connection->prepare($sql);
        if ($stmt === false) {
            throw new Exception("Unable to prepare statement: " . $this->connection->error);
        }

        foreach ($values as $rowValues) {
            $this->bindValues($stmt, $rowValues);
            if (!$stmt->execute()) {
                throw new Exception("Execute failed: (" . $stmt->errno . ") " . $stmt->error);
            }
        }

        return true;
    }

    public function customQuery($query)
    {
        $result = $this->connection->query($query);
        if (!$result) {
            throw new Exception("Query Failed: " . $this->connection->error);
        }
        return $result->fetch_all(MYSQLI_ASSOC);
    }

    public function escape($string)
    {
        return $this->connection->real_escape_string($string);
    }

    public function countRows($table, $where = null)
    {
        $sql = "SELECT COUNT(*) as count FROM {$table}";

        if (!empty($where)) {
            $sql .= " WHERE " . $where;
        }

        $result = $this->connection->query($sql);

        if (!$result) {
            throw new Exception("Error: " . $this->connection->error);
        }

        $row = $result->fetch_assoc();
        return $row['count'];
    }

    public function countPartialSearch($table, $column_name, $search_variable)
    {
        $search_term = '%' . $this->escape($search_variable) . '%';
        $sql = "SELECT COUNT(*) as count FROM {$table} WHERE {$column_name} LIKE ?";

        $stmt = $this->connection->prepare($sql);
        if ($stmt === false) {
            throw new Exception("Error: Unable to prepare statement");
        }

        $stmt->bind_param("s", $search_term);
        $stmt->execute();

        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        return $row['count'];
    }

    public function closeDBConnection()
    {
        $this->connection->close();
    }

    public function beginTransaction()
    {
        $this->connection->begin_transaction();
    }

    public function commitTransaction()
    {
        $this->connection->commit();
    }

    public function rollbackTransaction()
    {
        $this->connection->rollback();
    }

    private function buildWhereClause($where)
    {
        return ($where !== null) ? " WHERE {$where}" : "";
    }

    private function buildOrderClause($order)
    {
        return ($order !== null) ? " ORDER BY {$order}" : "";
    }

    private function buildLimitClause($limit, $offset = null)
    {
        $limitClause = ($limit !== null) ? " LIMIT {$limit}" : "";
        if ($offset !== null) {
            $limitClause .= " OFFSET {$offset}";
        }
        return $limitClause;
    }

    private function executeQuery($sql)
    {
        $result = $this->connection->query($sql);

        if (!$result) {
            throw new Exception("Error: " . $this->connection->error);
        }

        return $this->fetchResults($result);
    }

    private function fetchResults($result)
    {
        if ($result instanceof mysqli_stmt) {
            $result->store_result();
            $meta = $result->result_metadata();
            $fields = [];
            $row = [];

            while ($field = $meta->fetch_field()) {
                $fields[] = &$row[$field->name];
            }

            call_user_func_array([$result, 'bind_result'], $fields);

            $rows = [];
            while ($result->fetch()) {
                $rows[] = array_map('htmlspecialchars', $row);
            }

            return $rows;
        } elseif ($result instanceof mysqli_result) {
            $rows = [];
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
            return $rows;
        } else {
            throw new Exception("Unsupported result type");
        }
    }

    private function bindValues($stmt, $values)
    {
        $types = str_repeat("s", count($values));
        $stmt->bind_param($types, ...$values);
    }

    public function escape_string($string)
    {
        return $this->connection->real_escape_string($string);
    }
}