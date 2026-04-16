<?php
// ==================== logout.php ====================
require_once 'config.php';

// Limpiar token de "Recordarme"
if (isset($_COOKIE['remember_token'])) {
    $token = $_COOKIE['remember_token'];
    $rememberFile = DATA_DIR . 'remember_tokens.json';
    if (file_exists($rememberFile)) {
        $tokens = json_decode(file_get_contents($rememberFile), true);
        unset($tokens[$token]);
        file_put_contents($rememberFile, json_encode($tokens, JSON_PRETTY_PRINT));
    }
    setcookie('remember_token', '', time() - 3600, '/');
}

session_destroy();
redirect('login.php');
?>