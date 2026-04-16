<?php
require_once 'config.php';

$posts = getPosts();
$updated = false;

foreach ($posts as &$post) {
    // Convert comments from int to array if needed
    if (isset($post['comments']) && is_int($post['comments'])) {
        $post['comments'] = [];
        $updated = true;
    }
    // Ensure comments exists and is array
    if (!isset($post['comments']) || !is_array($post['comments'])) {
        $post['comments'] = [];
        $updated = true;
    }
}

if ($updated) {
    savePosts($posts);
    echo "Posts migrados correctamente.\n";
} else {
    echo "No se necesitaron migraciones.\n";
}
?>