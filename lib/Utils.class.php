<?php

class Utils {
    public function set_csrf()
    {
        if (!isset($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(50));
        }
        echo '<input type="hidden" name="csrf" value="' . $_SESSION['csrf'] . '">';
    }

    public function Load_Auto($classes) {
        $path = 'controller/';
        $extentsion = '.controller.php';
        $fullPath = $path . $classes . $extentsion;

        include_once $fullPath;
    }

    public function hashPassword($password)
    {
        $salt = $this->generateSalt();
        $hashedPassword = password_hash($password . $salt, PASSWORD_DEFAULT);

        return [
            'hashedPassword' => $hashedPassword,
            'salt' => $salt
        ];
    }

    public function verifyPassword($password, $hashedPassword, $salt)
    {
        if (password_verify($password . $salt, $hashedPassword)) {
            return true;
        } else {
            return false;
        }
    }

    public function generateSalt($length = 32)
    {
        return bin2hex(random_bytes($length));
    }

    public function shortenText($text, $maxLength)
    {
        if (strlen($text) > $maxLength) {
            $shortenedText = substr($text, 0, $maxLength - 3) . '...';
        } else {
            $shortenedText = $text;
        }
        return $shortenedText;
    }


    public function render($view, $data = [])
    {
        extract($data);

        ob_start();
        include_once dirname(__DIR__) . "/views/$view";
        $content = ob_get_clean();

        echo $content;
    }

    public function redirect($path)
    {
        header("Location: $path");
        exit;
    }

    public function redirectWithMessage($url, $msg) {
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['msg'] = $msg;

        header("Location: $url");
        exit();
    }
}