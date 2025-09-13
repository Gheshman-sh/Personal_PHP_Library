<?php 
require "./helper/ppl.min.php";

session_start();

$app = ppl::getInstance('localhost', 'root', '', 'marks');

$app->useStatic(__DIR__ . '/public');

$app->get('/', function () use ($app) {
    $search = trim($_GET['search'] ?? "");
    $test = $app->readTable(table: 'test', limit: 5, order: 'id DESC', where: "%$search%");

    $app->render('home.php', [
        'title' => $test["title"] 
    ]);
});

$app->run();