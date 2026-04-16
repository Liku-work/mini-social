<?php
// get_users_list.php - Helper endpoint for getting following/followers lists
require_once 'config.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'No autorizado']);
    exit;
}

$type = $_GET['type'] ?? '';
$userId = $_GET['user_id'] ?? '';

if (!$userId) {
    http_response_code(400);
    echo json_encode(['error' => 'Usuario no especificado']);
    exit;
}

if ($type === 'following') {
    $users = getFollowing($userId);
} elseif ($type === 'followers') {
    $users = getFollowers($userId);
} else {
    $users = [];
}

echo json_encode($users);
?>