<?php
require_once 'config.php';

if (!isLoggedIn()) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

$currentUser = getUserById($_SESSION['user_id']);
$notes = getNotesForUser($currentUser['id']);

header('Content-Type: application/json');
echo json_encode(['success' => true, 'notes' => $notes]);
?>