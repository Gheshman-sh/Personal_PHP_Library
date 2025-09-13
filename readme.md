# ppl – Minimal PHP Microframework

ppl is a lightweight PHP microframework for building small applications and APIs.

## Features
- Simple routing (GET, POST, etc.)
- Static file serving
- View rendering with layout support
- JSON responses
- HTMX request detection
- Optional database connection

## Project Structure
```
project/
│── helper/
│   └── ppl.min.php
│── public/
│   └── style.css
│── views/
│   ├── index.php
│   └── home.php
│── .htaccess
│── index.php
```

## Installation
Copy `ppl.min.php` into your project (`/helper/ppl.min.php`).

Require it in `index.php`:

```php
<?php
require __DIR__ . "/helper/ppl.min.php";

session_start();

$app = ppl::getInstance('localhost', 'root', '', 'marks');
$app->useStatic(__DIR__ . '/public');

$app->get('/', function () use ($app) {
    $app->render('home.php', [
        'title' => 'Hello World'
    ]);
});

$app->run();
```

## Rendering Views
Views go inside `/views`.

Render a view:
```php
$app->render('home.php', ['title' => 'My App']);
```

Render with layout (`index.php` is default):
```php
$app->sendHTML('home.php', ['title' => 'My App']);
```

HTMX requests will render only the partial view, normal requests render inside the layout.

## JSON Response
```php
$app->sendJSON([
    'success' => true,
    'message' => 'Hello API!'
]);
```

## HTMX Support
HTMX requests are automatically detected with the `HX-Request` header.

## Database (Optional)
Initialize with database credentials:
```php
$app = ppl::getInstance('localhost', 'root', '', 'dbname');
```
