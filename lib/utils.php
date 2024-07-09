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
