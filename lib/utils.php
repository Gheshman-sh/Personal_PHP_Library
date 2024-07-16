<?php

function hashPassword($password)
{
    $salt = generateSalt();
    $hashedPassword = password_hash($password . $salt, PASSWORD_DEFAULT);

    return [
        'hashedPassword' => $hashedPassword,
        'salt' => $salt
    ];
}

function verifyPassword($password, $hashedPassword, $salt)
{
    if (password_verify($password . $salt, $hashedPassword)) {
        return true;
    } else {
        return false;
    }
}

function generateSalt($length = 32)
{
    return bin2hex(random_bytes($length));
}

function shortenText($text, $maxLength)
{
    if (strlen($text) > $maxLength) {
        $shortenedText = substr($text, 0, $maxLength - 3) . '...';
    } else {
        $shortenedText = $text;
    }
    return $shortenedText;
}

function redirectWithMessage($url, $msg) {
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['msg'] = $msg;

    header("Location: $url");
    exit();
}

function redirect($path)
{
    header("Location: $path");
    exit;
}

function render($view, $data = [])
{
    extract($data);

    ob_start();
    include_once dirname(__DIR__) . "/views/$view";
    $content = ob_get_clean();

    echo $content;
}