<?php

declare(strict_types=1);

final class PPL
{
    /* =========================
       DATABASE
    ========================== */

    private readonly string $host;
    private readonly string $username;
    private readonly string $password;
    private readonly string $database;
    private \mysqli $connection;
    private static ?self $instance = null;

    /**
     * Singleton accessor.
     */
    public static function getInstance(
        string $host,
        string $username,
        string $password,
        string $database
    ): self {
        return self::$instance ??= new self($host, $username, $password, $database);
    }

    /**
     * Private constructor.
     */
    private function __construct(string $host, string $username, string $password, string $database)
    {
        $this->host     = $host;
        $this->username = $username;
        $this->password = $password;
        $this->database = $database;
        $this->connect();
    }

    /**
     * (Re)connect to the database.
     */
    private function connect(): void
    {
        $this->connection = new \mysqli(
            'p:' . $this->host,
            $this->username,
            $this->password,
            $this->database
        );
        if ($this->connection->connect_error) {
            throw new \RuntimeException('Connection failed: ' . $this->connection->connect_error);
        }
        $this->connection->set_charset('utf8mb4');
    }

    /* -------------------------------------------------
       Public DB methods (kept signatures as requested)
       ------------------------------------------------- */

    public function readTableWithJoin(
        string $table,
        array $joins,
        string $columns = '*',
        ?int $limit = null,
        int $page = 1,
        ?string $order = null,
        ?string $where = null,
        array $whereParams = [],
        bool $useFiber = false
    ): array {
        $offset = max(0, $page - 1) * (int)($limit ?? 0);

        $sql = 'SELECT ' . $this->safeColumns($columns) . ' FROM ' . $this->ident($table);

        foreach ($joins as $join) {
            $type   = isset($join['type']) ? strtoupper((string)$join['type']) : 'INNER';
            $tableJ = (string)($join['table'] ?? '');
            $on     = (string)($join['on'] ?? '');

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

    public function readTable(
        string $table,
        ?int $limit = null,
        ?string $order = null,
        ?string $where = null,
        array $whereParams = [],
        bool $useFiber = false
    ): array {
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

    public function readTablePaginated(
        string $table,
        int $limitPerPage,
        int $page = 1,
        ?string $order = null,
        ?string $where = null,
        array $whereParams = [],
        bool $useFiber = false
    ): array {
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

    public function partialSearch(
        string $table,
        string $columnName,
        string $search,
        ?int $limit = null,
        ?string $order = null,
        bool $useFiber = false
    ): array {
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

    public function updateRecord(
        string $table,
        array $values,
        string $where,
        array $whereParams = [],
        bool $useFiber = false
    ): int {
        if ($values === []) {
            return 0;
        }

        $setParts = [];
        $params   = [];
        foreach ($values as $k => $v) {
            $setParts[] = $this->ident((string)$k) . ' = ?';
            $params[]   = $v;
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

        $insertId = $this->runInsert($sql, array_values($values), $useFiber);
        return $insertId;
    }

    public function deleteRecord(
        string $table,
        string $where,
        array $whereParams = [],
        bool $useFiber = false
    ): int {
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

    public function countPartialSearch(
        string $table,
        string $columnName,
        string $search,
        bool $useFiber = false
    ): int {
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

    /* -------------------------------------------------
       Internals (centralized execution)
       ------------------------------------------------- */

    /**
     * Core SELECT runner. Uses prepared statements if params are provided.
     * Kept public wrappers (runQuery/runExecute/runInsert) for backward compatibility.
     */
    private function runQuery(string $sql, array $params = [], bool $useFiber = false): array
    {
        $runner = function () use ($sql, $params): array {
            // If no params and mysqlnd available, direct query is OK
            if ($params === []) {
                $result = $this->connection->query($sql);
                if ($result === false) {
                    throw new \RuntimeException('Query error: ' . $this->connection->error);
                }
                /** @var \mysqli_result $result */
                return $this->fetchAllAssoc($result);
            }

            $stmt = $this->prepareAndBind($sql, $params);
            $this->execOrThrow($stmt);
            $res = $stmt->get_result();
            if ($res === false) {
                // Fallback for environments without mysqlnd
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
            // bind_param requires references
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
                is_int($v)   => 'i',
                is_float($v) => 'd',
                is_null($v)  => 's',
                default      => 's',
            };
        }
        return $types;
    }

    public function escape_string(string $string): string
    {
        return $this->connection->real_escape_string($string);
    }

    // ident, safeColumns, safeOrder stay as-is below (kept for compatibility)
    private function ident(string $name): string
    {
        $name = trim($name);
        $parts = preg_split('/\s+/', $name, 2);
        $id    = $parts[0] ?? '';
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
        $safe  = [];
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

    /* End of database section */
    /* =========================
       ROUTING
    ========================= */

    private array $routes = [
        'GET' => [],
        'POST' => [],
        'PUT' => [],
        'PATCH' => [],
        'DELETE' => [],
    ];
    private array $middlewares = [];
    private ?string $staticPath = null;
    private string $routeGroupPrefix = '';
    // small cache to avoid reparsing routes on each request
    private array $parsedRouteCache = [];

    // === Registering routes ===
    public function get(string $route, callable|string $callback, array $middleware = []): void
    {
        $this->addRoute('GET', $route, $callback, $middleware);
    }

    public function post(string $route, callable|string $callback, array $middleware = []): void
    {
        $this->addRoute('POST', $route, $callback, $middleware);
    }

    public function put(string $route, callable|string $callback, array $middleware = []): void
    {
        $this->addRoute('PUT', $route, $callback, $middleware);
    }

    public function patch(string $route, callable|string $callback, array $middleware = []): void
    {
        $this->addRoute('PATCH', $route, $callback, $middleware);
    }

    public function delete(string $route, callable|string $callback, array $middleware = []): void
    {
        $this->addRoute('DELETE', $route, $callback, $middleware);
    }

    // === Static path ===
    public function useStatic(string $path): void
    {
        $this->staticPath = rtrim($path, '/');
    }

    // === Middleware ===
    public function middleware(callable $middleware, array $routes = []): void
    {
        if (empty($routes)) {
            $this->middlewares['global'][] = $middleware;
        } else {
            foreach ($routes as $route) {
                $this->middlewares[$route][] = $middleware;
            }
        }
    }

    // === Route grouping ===
    public function group(string $prefix, callable $callback): void
    {
        $previousPrefix = $this->routeGroupPrefix;
        $this->routeGroupPrefix .= $prefix;
        $callback();
        $this->routeGroupPrefix = $previousPrefix;
    }

    // === Add route helper ===
    private function addRoute(string $method, string $route, callable|string $callback, array $middleware = []): void
    {
        $route = $this->routeGroupPrefix . $route;
        $this->routes[$method][$route] = [
            'callback' => $callback,
            'middleware' => $middleware,
        ];
        $this->parsedRouteCache[$method][$route] = $this->parseRouteToParts($route);
    }

    // parse route string into parts for matching (cached)
    private function parseRouteToParts(string $route): array
    {
        return array_values(array_filter(explode('/', trim($route, '/')), fn($p) => $p !== ''));
    }

    // === Run router ===
    public function run(): void
    {
        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        if ($this->staticPath && $this->serveStatic($uri)) {
            return;
        }

        if (!isset($this->routes[$method])) {
            http_response_code(405);
            echo '405 Method Not Allowed';
            return;
        }

        if ($method === 'POST' && !$this->isCsrfValid()) {
            http_response_code(403);
            echo "Invalid CSRF token";
            return;
        }

        $this->handleRequest($method, $uri);
    }

    private function handleRequest(string $method, string $uri): void
    {
        $matchedCallback = null;
        $matchedMiddleware = [];
        $params = [];

        if (isset($this->middlewares['global'])) {
            $matchedMiddleware = array_merge($matchedMiddleware, $this->middlewares['global']);
        }

        $requestParts = array_values(array_filter(explode('/', trim($uri, '/')), fn($p) => $p !== ''));

        foreach ($this->routes[$method] as $route => $data) {
            $routeParts = $this->parsedRouteCache[$method][$route] ?? $this->parseRouteToParts($route);

            if (count($routeParts) !== count($requestParts)) {
                continue;
            }

            $isMatch = true;
            $params = [];

            foreach ($routeParts as $index => $routePart) {
                if (preg_match('/^{(.+)}$/', $routePart, $matches)) {
                    // route parameter
                    $params[$matches[1]] = $requestParts[$index] ?? null;
                } elseif ($routePart !== $requestParts[$index]) {
                    $isMatch = false;
                    break;
                }
            }

            if ($isMatch) {
                $matchedCallback = $data['callback'];
                $matchedMiddleware = array_merge($matchedMiddleware, $data['middleware']);
                break;
            }
        }

        if (!$matchedCallback) {
            http_response_code(404);
            $this->executeCallback('404');
            return;
        }

        $this->executeMiddleware($matchedMiddleware, $params, function () use ($matchedCallback, $params): void {
            $this->executeCallback($matchedCallback, $params);
        });
    }

    private function executeCallback(callable|string $callback, array $params = []): void
    {
        if (is_callable($callback)) {
            // call with parameters in order (not named)
            call_user_func_array($callback, $params);
        } elseif ($callback === '404') {
            http_response_code(404);
            // call existing render method on this class
            $this->render("partials/404.php");
        } else {
            header("Location: /$callback");
            exit;
        }
    }

    private function serveStatic(string $uri): bool
    {
        if ($this->staticPath === null) {
            return false;
        }

        $requested = ltrim($uri, '/');
        $candidate = realpath($this->staticPath . DIRECTORY_SEPARATOR . $requested);

        // safety: ensure candidate resolves inside staticPath
        $baseReal = realpath($this->staticPath);
        if ($candidate && is_file($candidate) && $baseReal !== false && str_starts_with($candidate, $baseReal)) {
            $mimeType = $this->getMimeType($candidate);
            header('Content-Type: ' . $mimeType);
            readfile($candidate);
            return true;
        }

        return false;
    }

    private function getMimeType(string $filePath): string
    {
        // prefer finfo
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $type = finfo_file($finfo, $filePath);
            finfo_close($finfo);
            if ($type !== false) {
                return $type;
            }
        }

        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        return match ($extension) {
            'css' => 'text/css',
            'js' => 'application/javascript',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'html' => 'text/html',
            default => 'application/octet-stream',
        };
    }

    private function executeMiddleware(array $middleware, array $params, callable $next): void
    {
        if (empty($middleware)) {
            $next();
            return;
        }

        $middlewareFunc = array_shift($middleware);

        $middlewareFunc($params, function () use ($middleware, $params, $next): void {
            $this->executeMiddleware($middleware, $params, $next);
        });
    }

    private function isCsrfValid(): bool
    {
        return isset($_SESSION['csrf'], $_POST['csrf'])
            && hash_equals((string)$_SESSION['csrf'], (string)$_POST['csrf']);
    }

    /* End of routing section */

    /* =========================
       RENDERING / RESPONSE
    ========================= */

    public function render(string $view, array $data = []): void
    {
        echo $this->fetchViewContent($view, $data);
    }

    public function sendHTML(string $view, array $data = [], string $mainView = "index.php"): void
    {
        if ($this->isHtmxRequest()) {
            echo $this->fetchViewContent($view, $data);
        } else {
            echo $this->fetchViewContent($mainView, $data);
        }
    }

    public function sendJSON(mixed $data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    private function isHtmxRequest(): bool
    {
        return ($_SERVER['HTTP_HX_REQUEST'] ?? '') === 'true';
    }

    private function getViewPath(string $view): string
    {
        $dir = __DIR__;
        while ($dir !== dirname($dir)) {
            if (is_dir($dir . '/views')) {
                return $dir . "/views/$view";
            }
            $dir = dirname($dir);
        }
        return __DIR__ . "/views/$view";
    }

    private function fetchViewContent(string $view, array $data = []): string
    {
        $viewPath = $this->getViewPath($view);

        if (!file_exists($viewPath)) {
            http_response_code(404);
            return "View not found: $view";
        }

        ob_start();
        extract($data, EXTR_SKIP);
        include $viewPath;
        return ob_get_clean() ?: '';
    }

    /* End of rendering section */

    /* =========================
       UTILITIES / MISC
    ========================= */

    /**
     * Generate or reuse CSRF token and output hidden input.
     */
    public static function setCsrf(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (!isset($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(50));
        }

        echo '<input type="hidden" name="csrf" value="' . htmlspecialchars($_SESSION['csrf'], ENT_QUOTES, 'UTF-8') . '">';
    }

    /**
     * Simple autoloader for controllers.
     */
    public static function loadAuto(string $class): void
    {
        $path = "controller/{$class}.controller.php";
        if (is_file($path)) {
            include_once $path;
        }
    }

    /**
     * Secure password hash (built-in salt).
     */
    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    /**
     * Verify password against hash.
     */
    public static function verifyPassword(string $password, string $hashedPassword): bool
    {
        return password_verify($password, $hashedPassword);
    }

    /**
     * Shorten text with ellipsis.
     */
    public static function shortenText(string $text, int $maxLength): string
    {
        return strlen($text) > $maxLength
            ? substr($text, 0, $maxLength - 3) . '...'
            : $text;
    }

    /**
     * Redirect to path.
     */
    public static function redirect(string $path): never
    {
        header("Location: $path");
        exit;
    }

    /**
     * Redirect with session message.
     */
    public static function redirectWithMessage(string $url, string $msg): never
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $_SESSION['msg'] = $msg;
        header("Location: $url");
        exit;
    }

    /**
     * Fetch URL with optional headers. Fiber-supported (PHP 8.1+).
     */
    public static function fetch(string $url, array $headers = [], bool $useFibers = false): array
    {
        if ($useFibers && class_exists(\Fiber::class)) {
            $fiber = new \Fiber(fn() => self::curlRequest($url, $headers));
            return $fiber->start(); // return result of curlRequest
        }

        return self::curlRequest($url, $headers);
    }

    /**
     * Perform curl request synchronously.
     */
    private static function curlRequest(string $url, array $headers = []): array
    {
        $ch = curl_init($url);

        $defaultOptions = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FAILONERROR => false,
        ];

        curl_setopt_array($ch, $defaultOptions);

        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            return ['error' => $error, 'status_code' => $httpCode];
        }

        curl_close($ch);
        return ['data' => $response, 'status_code' => $httpCode];
    }

    public static function getCurrentPage()
    {
        return $_SERVER['REQUEST_URI'] ?? $_SERVER['PHP_SELF'];
    }

    public static function parseMarkdown(string $text): string
    {
        // Escape HTML first to prevent injection
        $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        // Code blocks (``` ... ```)
        $text = preg_replace_callback('/```(.*?)```/s', function ($m) {
            $code = htmlspecialchars(trim($m[1]), ENT_QUOTES, 'UTF-8');
            return "<pre><code>$code</code></pre>";
        }, $text);

        // Headings: #, ##, ### ...
        $text = preg_replace('/^###### (.+)$/m', '<h6>$1</h6>', $text);
        $text = preg_replace('/^##### (.+)$/m', '<h5>$1</h5>', $text);
        $text = preg_replace('/^#### (.+)$/m', '<h4>$1</h4>', $text);
        $text = preg_replace('/^### (.+)$/m', '<h3>$1</h3>', $text);
        $text = preg_replace('/^## (.+)$/m', '<h2>$1</h2>', $text);
        $text = preg_replace('/^# (.+)$/m', '<h1>$1</h1>', $text);

        // Horizontal rules: --- *** ___
        $text = preg_replace('/^(?:-{3,}|\*{3,}|_{3,})$/m', '<hr>', $text);

        // Bold: **text** or __text__
        $text = preg_replace('/(\*\*|__)(.*?)\1/', '<strong>$2</strong>', $text);

        // Italic: *text* or _text_
        $text = preg_replace('/(\*|_)(.*?)\1/', '<em>$2</em>', $text);

        // Strikethrough: ~~text~~
        $text = preg_replace('/~~(.*?)~~/', '<del>$1</del>', $text);

        // Inline code: `code`
        $text = preg_replace('/`(.+?)`/', '<code>$1</code>', $text);

        // Links: [text](url)
        $text = preg_replace('/\[(.*?)\]\((.*?)\)/', '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>', $text);

        // Images: ![alt](url)
        $text = preg_replace('/!\[(.*?)\]\((.*?)\)/', '<img src="$2" alt="$1" style="max-width:100%;">', $text);

        // Blockquotes: > text
        $text = preg_replace('/^> (.+)$/m', '<blockquote>$1</blockquote>', $text);

        // Unordered lists
        $text = preg_replace_callback('/(?:^|\n)([-*] .+(?:\n[-*] .+)*)/m', function ($matches) {
            $items = preg_replace('/[-*] (.+)/', '<li>$1</li>', $matches[1]);
            return "<ul>$items</ul>";
        }, $text);

        // Ordered lists
        $text = preg_replace_callback('/(?:^|\n)(\d+\. .+(?:\n\d+\. .+)*)/m', function ($matches) {
            $items = preg_replace('/\d+\. (.+)/', '<li>$1</li>', $matches[1]);
            return "<ol>$items</ol>";
        }, $text);

        // Simple tables: | head | head | ...
        $text = preg_replace_callback('/((?:\|.+\|(?:\n|$))+)/', function ($matches) {
            $rows = explode("\n", trim($matches[1]));
            $html = "<table>";
            foreach ($rows as $row) {
                if (trim($row) === '') continue;
                $cols = array_map('trim', explode('|', trim($row, '| ')));
                $html .= "<tr>";
                foreach ($cols as $c) {
                    $html .= "<td>$c</td>";
                }
                $html .= "</tr>";
            }
            return "$html</table>";
        }, $text);

        // Paragraphs: split on two+ newlines
        $parts = preg_split('/\n{2,}/', trim($text));
        $text = implode("</p><p>", array_map('trim', $parts));
        $text = "<p>$text</p>";

        // Convert single newlines to <br>
        $text = preg_replace('/\n/', '<br>', $text);

        // Avoid wrapping block elements in <p>
        $text = preg_replace(
            '/<p>(\s*<(?:h\d|ul|ol|li|hr|pre|code|blockquote|table|tr|td|img)[^>]*>.*?<\/(?:h\d|ul|ol|li|hr|pre|code|blockquote|table|tr|td|img)>)<\/p>/s',
            '$1',
            $text
        );

        return $text;
    }
}
