<?php

class Database
{
    private $host;
    private $username;
    private $password;
    private $database;
    private $connection;

    /**
     * Constructor to initialize database connection.
     */
    public function __construct($host, $username, $password, $database)
    {
        $this->host = $host;
        $this->username = $username;
        $this->password = $password;
        $this->database = $database;

        $this->connect();
    }

    /**
     * Establishes database connection.
     */
    private function connect()
    {
        $this->connection = new mysqli($this->host, $this->username, $this->password, $this->database);

        if ($this->connection->connect_error) {
            throw new Exception("Connection failed: " . $this->connection->connect_error);
        }
    }

    /**
     * Executes a SELECT query on the database.
     */
    public function readTable($table, $limit = null, $order = null, $where = null)
    {
        $sql = "SELECT * FROM {$table}";
        $sql .= $this->buildWhereClause($where);
        $sql .= $this->buildOrderClause($order);
        $sql .= $this->buildLimitClause($limit);

        return $this->executeQuery($sql);
    }

    /**
     * Executes a SELECT query with pagination.
     */
    public function readTablePaginated($table, $limit, $offset, $order = null, $where = null)
    {
        $sql = "SELECT * FROM {$table}";
        $sql .= $this->buildWhereClause($where);
        $sql .= $this->buildOrderClause($order);
        $sql .= " LIMIT {$limit} OFFSET {$offset}";

        return $this->executeQuery($sql);
    }

    /**
     * Executes a partial search query.
     */
    public function partialSearch($table, $column_name, $search_variable, $limit = null, $order = null)
    {
        $search_term = '%' . $search_variable . '%';
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

    /**
     * Updates records in the database.
     */
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

        return true;
    }

    /**
     * Inserts a record into the database.
     */
    public function writeRecord($table, $values)
    {
        $keys = implode(", ", array_keys($values));
        $placeholders = implode(", ", array_fill(0, count($values), "?"));
        $sql = "INSERT INTO {$table} ({$keys}) VALUES ({$placeholders})";

        $stmt = $this->connection->prepare($sql);
        if ($stmt === false) {
            throw new Exception("Error: Unable to prepare statement");
        }

        $this->bindValues($stmt, array_values($values));
        $stmt->execute();

        return true;
    }

    /**
     * Delete a record from the database.
     */
    public function deleteRecord($table, $where)
    {
        $sql = "DELETE FROM {$table} WHERE {$where}";

        $stmt = $this->connection->prepare($sql);
        if ($stmt === false) {
            throw new Exception("Error: Unable to prepare statement");
        }

        $stmt->execute();

        return true;
    }

    /**
     * Closes the database connection.
     */
    public function closeDBConnection()
    {
        $this->connection->close();
    }

    /**
     * Helper function to build WHERE clause.
     */
    private function buildWhereClause($where)
    {
        return ($where !== null) ? " WHERE {$where}" : "";
    }

    /**
     * Helper function to build ORDER BY clause.
     */
    private function buildOrderClause($order)
    {
        return ($order !== null) ? " ORDER BY {$order}" : "";
    }

    /**
     * Helper function to build LIMIT clause.
     */
    private function buildLimitClause($limit)
    {
        return ($limit !== null) ? " LIMIT {$limit}" : "";
    }

    /**
     * Executes a SQL query and returns results as associative array.
     */
    private function executeQuery($sql)
    {
        $result = $this->connection->query($sql);

        if (!$result) {
            throw new Exception("Error: " . $this->connection->error);
        }

        return $this->fetchResults($result);
    }

    /**
     * Fetches results from a MySQLi result object.
     */
    private function fetchResults($result)
    {
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Binds values to a prepared statement.
     */
    private function bindValues($stmt, $values)
    {
        $types = str_repeat("s", count($values));
        $stmt->bind_param($types, ...$values);
    }
}
