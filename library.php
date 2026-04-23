<?php

declare(strict_types=1);

class Library
{
    /* -------------------------------------------------------------------------
     * Database properties
     * ---------------------------------------------------------------------- */
    private string $host;
    private string $username;
    private string $password;
    private string $database;
    private \mysqli $connection;
    private static ?self $instance = null;

    /* -------------------------------------------------------------------------
     * Router properties
     * ---------------------------------------------------------------------- */
    private array $routes = [
        'GET'    => [],
        'POST'   => [],
        'PUT'    => [],
        'PATCH'  => [],
        'DELETE' => [],
    ];
    private array $middlewares       = [];
    private ?string $staticPath      = null;
    private string $routeGroupPrefix = '';
    private array $parsedRouteCache  = [];
    private ?string $viewBasePath    = null;

    /* =========================================================================
     * Singleton / Construction
     * ====================================================================== */

    public static function getInstance(
        string $host,
        string $username,
        string $password,
        string $database
    ): self {
        if (self::$instance !== null) {
            return self::$instance;
        }
        return self::$instance = new self($host, $username, $password, $database);
    }

    private function __construct(string $host, string $username, string $password, string $database)
    {
        $this->host     = $host;
        $this->username = $username;
        $this->password = $password;
        $this->database = $database;
        $this->connect();
    }

    /* =========================================================================
     * Database — Connection
     * ====================================================================== */

    private function connect(): void
    {
        // Persistent connection via 'p:' prefix
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

    public function closeDBConnection(): void
    {
        $this->connection->close();
    }

    /* =========================================================================
     * Database — Public Query Methods
     * ====================================================================== */

    /**
     * Read rows from a table with optional WHERE / ORDER BY / LIMIT.
     */
    public function readTable(
        string  $table,
        string  $columns     = '*',
        ?int    $limit       = null,
        int     $page        = 1,
        ?string $order       = null,
        ?string $where       = null,
        array   $whereParams = []
    ): array {
        $sql = 'SELECT ' . $this->safeColumns($columns) . ' FROM ' . $this->ident($table);

        if ($where !== null && $where !== '') {
            $sql .= ' WHERE ' . $where;
        }
        if ($order !== null && $order !== '') {
            $sql .= ' ORDER BY ' . $this->safeOrder($order);
        }
        if ($limit !== null) {
            $offset = max(0, $page - 1) * $limit;
            $sql   .= ' LIMIT ' . $limit;
            if ($offset > 0) {
                $sql .= ' OFFSET ' . $offset;
            }
        }

        return $this->runQuery($sql, $whereParams);
    }

    /**
     * Read rows from a table with one or more JOINs.
     *
     * Each join entry: ['type' => 'LEFT', 'table' => 'orders', 'on' => 'users.id = orders.user_id']
     */
    public function readTableWithJoin(
        string  $table,
        array   $joins,
        string  $columns     = '*',
        ?int    $limit       = null,
        int     $page        = 1,
        ?string $order       = null,
        ?string $where       = null,
        array   $whereParams = []
    ): array {
        $sql = 'SELECT ' . $this->safeColumns($columns) . ' FROM ' . $this->ident($table);

        foreach ($joins as $join) {
            $type   = isset($join['type']) ? strtoupper((string) $join['type']) : 'INNER';
            $tableJ = (string) ($join['table'] ?? '');
            $on     = (string) ($join['on'] ?? '');

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
            $offset = max(0, $page - 1) * $limit;
            $sql   .= ' LIMIT ' . $limit;
            if ($offset > 0) {
                $sql .= ' OFFSET ' . $offset;
            }
        }

        return $this->runQuery($sql, $whereParams);
    }

    /**
     * Full-text LIKE search on a single column.
     */
    public function partialSearch(
        string  $table,
        string  $columnName,
        string  $search,
        string  $columns = '*',
        ?int    $limit   = null,
        ?string $order   = null
    ): array {
        $sql    = 'SELECT ' . $this->safeColumns($columns) . ' FROM ' . $this->ident($table)
                . ' WHERE ' . $this->ident($columnName) . ' LIKE ?';
        $params = ['%' . $search . '%'];

        if ($order !== null && $order !== '') {
            $sql .= ' ORDER BY ' . $this->safeOrder($order);
        }
        if ($limit !== null) {
            $sql .= ' LIMIT ' . $limit;
        }

        return $this->runQuery($sql, $params);
    }

    /**
     * Count rows in a table, with an optional WHERE clause.
     */
    public function countRows(string $table, ?string $where = null, array $whereParams = []): int
    {
        $sql = 'SELECT COUNT(*) AS cnt FROM ' . $this->ident($table);

        if ($where !== null && $where !== '') {
            $sql .= ' WHERE ' . $where;
        }

        $rows = $this->runQuery($sql, $whereParams);
        return (int) ($rows[0]['cnt'] ?? 0);
    }

    /**
     * Count rows matching a LIKE search on a column.
     */
    public function countPartialSearch(string $table, string $columnName, string $search): int
    {
        $sql  = 'SELECT COUNT(*) AS cnt FROM ' . $this->ident($table)
              . ' WHERE ' . $this->ident($columnName) . ' LIKE ?';
        $rows = $this->runQuery($sql, ['%' . $search . '%']);
        return (int) ($rows[0]['cnt'] ?? 0);
    }

    /**
     * Insert a row and return the new auto-increment ID.
     */
    public function writeRecord(string $table, array $values): int
    {
        if ($values === []) {
            throw new \InvalidArgumentException('Values cannot be empty for insert.');
        }

        $cols         = array_map(fn($c) => $this->ident((string) $c), array_keys($values));
        $placeholders = implode(', ', array_fill(0, count($values), '?'));
        $sql          = 'INSERT INTO ' . $this->ident($table)
                      . ' (' . implode(', ', $cols) . ')'
                      . ' VALUES (' . $placeholders . ')';

        return $this->runInsert($sql, array_values($values));
    }

    /**
     * Update rows matching $where and return affected row count.
     */
    public function updateRecord(
        string $table,
        array  $values,
        string $where,
        array  $whereParams = []
    ): int {
        if ($values === []) {
            return 0;
        }

        $setParts = [];
        $params   = [];
        foreach ($values as $k => $v) {
            $setParts[] = $this->ident((string) $k) . ' = ?';
            $params[]   = $v;
        }

        $sql    = 'UPDATE ' . $this->ident($table)
                . ' SET ' . implode(', ', $setParts)
                . ' WHERE ' . $where;
        $params = array_merge($params, $whereParams);

        return $this->runExecute($sql, $params);
    }

    /**
     * Delete rows matching $where and return affected row count.
     *
     * Requires a non-empty WHERE clause — pass '1' explicitly to delete all.
     */
    public function deleteRecord(string $table, string $where, array $whereParams = []): int
    {
        if ($where === '') {
            throw new \InvalidArgumentException(
                'deleteRecord requires a WHERE clause. Pass \'1\' to delete all rows.'
            );
        }

        $sql = 'DELETE FROM ' . $this->ident($table) . ' WHERE ' . $where;
        return $this->runExecute($sql, $whereParams);
    }

    /**
     * Run a raw SELECT / SHOW / DESCRIBE query and return rows.
     */
    public function customSelect(string $query, array $params = []): array
    {
        $keyword = strtoupper(substr(ltrim($query), 0, 8));
        if (!in_array(substr($keyword, 0, 6), ['SELECT', 'SHOW  ', 'DESCRI'], true)
            && strncasecmp(ltrim($query), 'DESCRIBE', 8) !== 0
            && strncasecmp(ltrim($query), 'SHOW', 4) !== 0
            && strncasecmp(ltrim($query), 'SELECT', 6) !== 0
        ) {
            throw new \InvalidArgumentException('customSelect only accepts SELECT / SHOW / DESCRIBE queries.');
        }

        return $this->runQuery($query, $params);
    }

    /**
     * Run a raw non-SELECT query (INSERT / UPDATE / DELETE / …) and return affected rows.
     */
    public function customExecute(string $query, array $params = []): int
    {
        return $this->runExecute($query, $params);
    }

    /**
     * Wrap a callable in a DB transaction. Rolls back on any exception.
     */
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

    /* =========================================================================
     * Database — Internal Query Runners
     * ====================================================================== */

    private function runQuery(string $sql, array $params = []): array
    {
        if ($params === []) {
            $result = $this->connection->query($sql);
            if ($result === false) {
                throw new \RuntimeException('Query error: ' . $this->connection->error);
            }
            return $result->fetch_all(MYSQLI_ASSOC) ?: [];
        }

        $stmt = $this->prepareAndBind($sql, $params);
        $this->execOrThrow($stmt);
        $res = $stmt->get_result();
        if ($res === false) {
            throw new \RuntimeException('get_result() failed — ensure the mysqlnd driver is installed.');
        }
        return $res->fetch_all(MYSQLI_ASSOC) ?: [];
    }

    private function runExecute(string $sql, array $params = []): int
    {
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
    }

    private function runInsert(string $sql, array $params = []): int
    {
        if ($params === []) {
            $ok = $this->connection->query($sql);
            if ($ok === false) {
                throw new \RuntimeException('Insert error: ' . $this->connection->error);
            }
            return (int) $this->connection->insert_id;
        }

        $stmt = $this->prepareAndBind($sql, $params);
        $this->execOrThrow($stmt);
        return (int) $stmt->insert_id;
    }

    private function prepareAndBind(string $sql, array $params): \mysqli_stmt
    {
        $stmt = $this->connection->prepare($sql);
        if ($stmt === false) {
            throw new \RuntimeException('Unable to prepare statement: ' . $this->connection->error);
        }

        $types = $this->inferTypes($params);
        $refs  = [];
        foreach ($params as $k => $v) {
            $refs[$k] = &$params[$k];
        }
        $stmt->bind_param($types, ...$refs);

        return $stmt;
    }

    private function execOrThrow(\mysqli_stmt $stmt): void
    {
        if (!$stmt->execute()) {
            throw new \RuntimeException('Execute failed: (' . $stmt->errno . ') ' . $stmt->error);
        }
    }

    private function inferTypes(array $params): string
    {
        $types = '';
        foreach ($params as $v) {
            // null binds as string — mysqli treats it correctly
            $types .= match (true) {
                is_int($v)   => 'i',
                is_float($v) => 'd',
                default      => 's',
            };
        }
        return $types;
    }

    /* =========================================================================
     * Database — SQL Safety Helpers
     * ====================================================================== */

    /**
     * Quote and validate a table/column identifier (supports dot-notation and aliases).
     */
    private function ident(string $name): string
    {
        $name  = trim($name);
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

    /**
     * Validate and quote a column list (supports *, table.*, functions, AS aliases).
     */
    private function safeColumns(string $cols): string
    {
        $cols = trim($cols);
        if ($cols === '*') {
            return '*';
        }

        $out = [];
        foreach (explode(',', $cols) as $raw) {
            $raw = trim($raw);

            // table.*
            if (preg_match('/^([A-Za-z0-9_]+)\.\*$/', $raw, $m)) {
                $out[] = '`' . $m[1] . '`.*';
                continue;
            }

            // SQL functions — pass through as-is (caller's responsibility)
            if (str_contains($raw, '(') || str_contains($raw, ')')) {
                $out[] = $raw;
                continue;
            }

            // col AS alias
            $parts = preg_split('/\s+AS\s+/i', $raw);
            if (count($parts) === 2) {
                $out[] = $this->ident($parts[0]) . ' AS ' . $this->ident($parts[1]);
                continue;
            }

            $out[] = $this->ident($raw);
        }

        return implode(', ', $out);
    }

    /**
     * Validate an ORDER BY string (comma-separated col [ASC|DESC] pairs).
     */
    private function safeOrder(string $order): string
    {
        $parts = array_map('trim', explode(',', $order));
        $safe  = [];

        foreach ($parts as $p) {
            if ($p === '') {
                continue;
            }
            $tokens = preg_split('/\s+/', $p);
            $col    = $this->ident($tokens[0]);
            $dir    = '';

            if (isset($tokens[1])) {
                $d   = strtoupper($tokens[1]);
                $dir = in_array($d, ['ASC', 'DESC'], true) ? ' ' . $d : '';
            }

            $safe[] = $col . $dir;
        }

        return implode(', ', $safe);
    }

    /* =========================================================================
     * Router
     * ====================================================================== */

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

    /**
     * Set the directory from which static files are served.
     */
    public function useStatic(string $path): void
    {
        $this->staticPath = rtrim($path, '/');
    }

    /**
     * Set the base directory for views (instead of auto-traversal).
     */
    public function setViewPath(string $path): void
    {
        $this->viewBasePath = rtrim($path, '/');
    }

    /**
     * Register a global or route-specific middleware.
     */
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

    /**
     * Group routes under a shared prefix.
     */
    public function group(string $prefix, callable $callback): void
    {
        $previousPrefix        = $this->routeGroupPrefix;
        $this->routeGroupPrefix .= $prefix;
        $callback();
        $this->routeGroupPrefix = $previousPrefix;
    }

    /**
     * Dispatch the current HTTP request.
     */
    public function run(): void
    {
        $uri    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        if ($this->staticPath && $this->serveStatic($uri)) {
            return;
        }

        if (!isset($this->routes[$method])) {
            http_response_code(405);
            header('Allow: ' . implode(', ', array_keys($this->routes)));
            echo '405 Method Not Allowed';
            return;
        }

        // CSRF protection for all state-mutating methods
        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) && !$this->isCsrfValid()) {
            http_response_code(403);
            echo 'Invalid CSRF token';
            return;
        }

        $this->handleRequest($method, $uri);
    }

    private function addRoute(string $method, string $route, callable|string $callback, array $middleware = []): void
    {
        $route = $this->routeGroupPrefix . $route;
        $this->routes[$method][$route] = [
            'callback'   => $callback,
            'middleware' => $middleware,
        ];
        $this->parsedRouteCache[$method][$route] = $this->parseRouteToParts($route);
    }

    private function parseRouteToParts(string $route): array
    {
        return array_values(array_filter(explode('/', trim($route, '/')), fn($p) => $p !== ''));
    }

    private function handleRequest(string $method, string $uri): void
    {
        $matchedCallback   = null;
        $matchedMiddleware = $this->middlewares['global'] ?? [];
        $params            = [];

        $requestParts = array_values(array_filter(explode('/', trim($uri, '/')), fn($p) => $p !== ''));

        foreach ($this->routes[$method] as $route => $data) {
            $routeParts = $this->parsedRouteCache[$method][$route] ?? $this->parseRouteToParts($route);

            if (count($routeParts) !== count($requestParts)) {
                continue;
            }

            $isMatch    = true;
            $tempParams = [];

            foreach ($routeParts as $index => $routePart) {
                if (preg_match('/^\{(.+)\}$/', $routePart, $matches)) {
                    $tempParams[$matches[1]] = $requestParts[$index] ?? null;
                } elseif ($routePart !== $requestParts[$index]) {
                    $isMatch = false;
                    break;
                }
            }

            if ($isMatch) {
                $matchedCallback   = $data['callback'];
                $matchedMiddleware = array_merge($matchedMiddleware, $data['middleware']);
                $params            = $tempParams;
                break;
            }
        }

        if ($matchedCallback === null) {
            http_response_code(404);
            $this->render('partials/404.php');
            return;
        }

        $this->executeMiddleware($matchedMiddleware, $params, function () use ($matchedCallback, $params): void {
            $this->executeCallback($matchedCallback, $params);
        });
    }

    private function executeCallback(callable|string $callback, array $params = []): void
    {
        if (is_callable($callback)) {
            $callback(...array_values($params));
            return;
        }

        // String: treat as a redirect target
        header('Location: /' . ltrim($callback, '/'));
        exit;
    }

    private function executeMiddleware(array $middleware, array $params, callable $next): void
    {
        if (empty($middleware)) {
            $next();
            return;
        }

        $fn = array_shift($middleware);
        $fn($params, function () use ($middleware, $params, $next): void {
            $this->executeMiddleware($middleware, $params, $next);
        });
    }

    private function isCsrfValid(): bool
    {
        return isset($_SESSION['csrf'], $_POST['csrf'])
            && hash_equals((string) $_SESSION['csrf'], (string) $_POST['csrf']);
    }

    /* =========================================================================
     * Static File Serving
     * ====================================================================== */

    private function serveStatic(string $uri): bool
    {
        if ($this->staticPath === null) {
            return false;
        }

        $requested = ltrim($uri, '/');
        $baseReal  = realpath($this->staticPath);
        $candidate = realpath($this->staticPath . DIRECTORY_SEPARATOR . $requested);

        if (
            $candidate !== false
            && $baseReal !== false
            && is_file($candidate)
            && str_starts_with($candidate, $baseReal . DIRECTORY_SEPARATOR)
        ) {
            header('Content-Type: ' . $this->getMimeType($candidate));
            readfile($candidate);
            return true;
        }

        return false;
    }

    private function getMimeType(string $filePath): string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $type  = finfo_file($finfo, $filePath);
            finfo_close($finfo);
            if ($type !== false) {
                return $type;
            }
        }

        return match (strtolower(pathinfo($filePath, PATHINFO_EXTENSION))) {
            'css'        => 'text/css',
            'js'         => 'application/javascript',
            'png'        => 'image/png',
            'jpg', 'jpeg'=> 'image/jpeg',
            'gif'        => 'image/gif',
            'svg'        => 'image/svg+xml',
            'webp'       => 'image/webp',
            'woff'       => 'font/woff',
            'woff2'      => 'font/woff2',
            'ico'        => 'image/x-icon',
            'html'       => 'text/html',
            'json'       => 'application/json',
            default      => 'application/octet-stream',
        };
    }

    /* =========================================================================
     * View / Response
     * ====================================================================== */

    /**
     * Render a view directly to output.
     */
    public function render(string $view, array $data = []): void
    {
        echo $this->fetchViewContent($view, $data);
    }

    /**
     * Send a view — the full layout for full requests, a partial for HTMX requests.
     */
    public function sendHTML(string $view, array $data = [], string $mainView = 'index.php'): void
    {
        echo $this->fetchViewContent(
            $this->isHtmxRequest() ? $view : $mainView,
            $data
        );
    }

    /**
     * Send a JSON response with the given status code.
     */
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

    /**
     * Resolve a view file path.
     * Uses setViewPath() if configured; otherwise falls back to directory traversal.
     */
    private function getViewPath(string $view): string
    {
        if ($this->viewBasePath !== null) {
            return $this->viewBasePath . '/' . $view;
        }

        // Fallback: walk up until a views/ directory is found
        $dir = __DIR__;
        while ($dir !== dirname($dir)) {
            if (is_dir($dir . '/views')) {
                return $dir . '/views/' . $view;
            }
            $dir = dirname($dir);
        }

        return __DIR__ . '/views/' . $view;
    }

    private function fetchViewContent(string $view, array $data = []): string
    {
        $viewPath = $this->getViewPath($view);

        if (!file_exists($viewPath)) {
            http_response_code(404);
            return 'View not found: ' . htmlspecialchars($view, ENT_QUOTES, 'UTF-8');
        }

        ob_start();
        extract($data, EXTR_SKIP);
        include $viewPath;
        return ob_get_clean() ?: '';
    }

    /* =========================================================================
     * Utility — Static Helpers
     * ====================================================================== */

    /**
     * Emit a CSRF hidden input and initialise the session token if needed.
     */
    public static function setCsrf(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (!isset($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }

        echo '<input type="hidden" name="csrf" value="'
           . htmlspecialchars((string) $_SESSION['csrf'], ENT_QUOTES, 'UTF-8') . '">';
    }

    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    public static function verifyPassword(string $password, string $hashedPassword): bool
    {
        return password_verify($password, $hashedPassword);
    }

    /**
     * Truncate $text to $maxLength characters, appending '…' if cut.
     */
    public static function shortenText(string $text, int $maxLength): string
    {
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }
        return mb_substr($text, 0, $maxLength - 1) . '…';
    }

    public static function redirect(string $path): never
    {
        header('Location: ' . $path);
        exit;
    }

    public static function redirectWithMessage(string $url, string $msg): never
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $_SESSION['msg'] = $msg;
        header('Location: ' . $url);
        exit;
    }

    /**
     * Make an HTTP GET request via cURL.
     *
     * Returns ['data' => string, 'status_code' => int] on success,
     * or     ['error' => string, 'status_code' => int] on failure.
     */
    public static function fetch(string $url, array $headers = []): array
    {
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_FAILONERROR    => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

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
}