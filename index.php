<?php
require_once 'config.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

$currentUser = getUserById($_SESSION['user_id']);
if (!$currentUser) {
    session_destroy();
    redirect('login.php');
}

// Handle post actions via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    $response = ['success' => false, 'message' => ''];
    
    if ($action === 'create_post') {
        $text = trim($_POST['text'] ?? '');
        $image = $_POST['image_data'] ?? null;
        
        if (!empty($text) || $image) {
            $postData = [
                'user_id' => $currentUser['id'],
                'content' => $text,
                'image' => $image
            ];
            
            $postId = createPost($postData);
            if ($postId) {
                $newPost = getPostById($postId);
                if ($newPost) {
                    $response['success'] = true;
                    $response['post'] = $newPost;
                } else {
                    $response['message'] = 'Error al obtener la publicación';
                }
            } else {
                $response['message'] = 'Error al crear la publicación';
            }
        } else {
            $response['message'] = 'El post está vacío';
        }
        echo json_encode($response);
        exit;
    }
    
    if ($action === 'delete_post') {
        $postId = $_POST['post_id'] ?? '';
        $post = getPostById($postId);
        
        if ($post && $post['user_id'] === $currentUser['id']) {
            if (deletePost($postId)) {
                $response['success'] = true;
            } else {
                $response['message'] = 'Error al eliminar la publicación';
            }
        } else {
            $response['message'] = 'No autorizado';
        }
        echo json_encode($response);
        exit;
    }
    
    if ($action === 'edit_post') {
        $postId = $_POST['post_id'] ?? '';
        $text = trim($_POST['text'] ?? '');
        $post = getPostById($postId);
        
        if ($post && $post['user_id'] === $currentUser['id']) {
            if (updatePost($postId, ['content' => $text])) {
                $updatedPost = getPostById($postId);
                $response['success'] = true;
                $response['post'] = $updatedPost;
            } else {
                $response['message'] = 'Error al actualizar la publicación';
            }
        } else {
            $response['message'] = 'No autorizado';
        }
        echo json_encode($response);
        exit;
    }
    
    if ($action === 'like_post') {
        $postId = $_POST['post_id'] ?? '';
        $post = getPostById($postId);
        
        if ($post) {
            $likes = $post['likes'] ?? [];
            $found = false;
            $newLikes = [];
            
            foreach ($likes as $like) {
                if ($like['user_id'] === $currentUser['id']) {
                    $found = true;
                } else {
                    $newLikes[] = $like;
                }
            }
            
            if (!$found) {
                $newLikes[] = [
                    'user_id' => $currentUser['id'],
                    'user_name' => $currentUser['name'],
                    'user_avatar' => $currentUser['avatar'],
                    'created_at' => date('Y-m-d H:i:s')
                ];
            }
            
            if (updatePost($postId, ['likes' => $newLikes])) {
                $updatedPost = getPostById($postId);
                $response['success'] = true;
                $response['likes'] = $updatedPost['likes'];
                $response['liked'] = !$found;
            } else {
                $response['message'] = 'Error al actualizar like';
            }
        } else {
            $response['message'] = 'Publicación no encontrada';
        }
        echo json_encode($response);
        exit;
    }
    
    if ($action === 'retweet_post') {
        $postId = $_POST['post_id'] ?? '';
        $post = getPostById($postId);
        
        if ($post) {
            $retweets = ($post['retweets'] ?? 0) + 1;
            if (updatePost($postId, ['retweets' => $retweets])) {
                $updatedPost = getPostById($postId);
                $response['success'] = true;
                $response['retweets'] = $updatedPost['retweets'];
            } else {
                $response['message'] = 'Error al actualizar retweet';
            }
        } else {
            $response['message'] = 'Publicación no encontrada';
        }
        echo json_encode($response);
        exit;
    }
    
    if ($action === 'follow_user') {
        $userId = $_POST['user_id'] ?? '';
        $follow = $_POST['follow'] === 'true';
        
        if ($userId && $userId !== $currentUser['id']) {
            if ($follow) {
                $result = followUser($currentUser['id'], $userId);
            } else {
                $result = unfollowUser($currentUser['id'], $userId);
            }
            
            if ($result) {
                $response['success'] = true;
                $response['following'] = isFollowing($currentUser['id'], $userId);
                $stats = getFollowStats($userId);
                $response['followers'] = $stats['followers'];
            } else {
                $response['message'] = 'Error al actualizar seguimiento';
            }
        } else {
            $response['message'] = 'Usuario no válido';
        }
        echo json_encode($response);
        exit;
    }
    
    if ($action === 'update_profile') {
        $avatarPath = null;
        
        if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
            $uploadedAvatar = saveAvatar($_FILES['avatar'], $currentUser['id']);
            if ($uploadedAvatar) {
                $avatarPath = $uploadedAvatar;
            }
        }
        
        $name = trim($_POST['name']);
        $bio = trim($_POST['bio']);
        
        $updateData = [];
        if (!empty($name)) $updateData['name'] = $name;
        if (!empty($bio)) $updateData['bio'] = $bio;
        if ($avatarPath) $updateData['avatar'] = $avatarPath;
        
        if (!empty($updateData)) {
            updateUser($currentUser['id'], $updateData);
            
            if ($avatarPath) {
                $_SESSION['avatar'] = $avatarPath;
            }
            if (!empty($name)) {
                $_SESSION['name'] = $name;
            }
            if (!empty($bio)) {
                $_SESSION['bio'] = $bio;
            }
            
            // Update posts with new user info
            $allPosts = getAllPostsFromBlocks();
            foreach ($allPosts as &$post) {
                if ($post['user_id'] === $currentUser['id']) {
                    if (!empty($name)) $post['author_name'] = $name;
                    if ($avatarPath) $post['author_avatar'] = $avatarPath;
                }
            }
            reorganizePostsIntoBlocks($allPosts);
        }
        
        $response['success'] = true;
        $response['user'] = getUserById($currentUser['id']);
        echo json_encode($response);
        exit;
    }
    
    echo json_encode($response);
    exit;
}

// Get posts with pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$currentView = $_GET['view'] ?? 'home';
$searchQuery = $_GET['search'] ?? '';
$username = $_GET['user'] ?? null;

// Get user by username if provided
$viewedUser = null;
if ($username) {
    $viewedUser = getUserByUsername($username);
    if (!$viewedUser) {
        // User not found, redirect to home
        redirect('home.php');
    }
}
$userId = $viewedUser ? $viewedUser['id'] : null;

// Filter posts based on view
if ($currentView === 'home' && !$userId) {
    // Home view: posts from followed users + current user's posts, mixed randomly
    $following = getFollowing($currentUser['id']);
    $followingIds = array_column($following, 'id');
    $followingIds[] = $currentUser['id']; // Include current user's own posts
    
    // Get all posts
    $allPostsData = getPosts($page, 1000, null, null); // Get many posts to filter
    $allPosts = $allPostsData['posts'];
    
    // Filter posts from following users and current user
    $filteredPosts = array_filter($allPosts, function($post) use ($followingIds) {
        return in_array($post['user_id'], $followingIds);
    });
    
    // Shuffle for random order
    $filteredPosts = array_values($filteredPosts);
    shuffle($filteredPosts);
    
    // Apply pagination
    $limit = 20;
    $startPost = ($page - 1) * $limit;
    $paginatedPosts = array_slice($filteredPosts, $startPost, $limit);
    
    $postsData = [
        'posts' => $paginatedPosts,
        'total' => count($filteredPosts),
        'page' => $page,
        'limit' => $limit,
        'total_pages' => ceil(count($filteredPosts) / $limit)
    ];
} elseif ($currentView === 'explore') {
    // Explore view: random posts from all users
    $postsData = getPosts($page, 20, 'random', null);
} elseif ($userId || $currentView === 'profile') {
    // Profile view: only posts from the specific user, ordered by date (newest first)
    $targetUserId = $userId ?: $currentUser['id'];
    $postsData = getPosts($page, 20, 'profile', $targetUserId);
} else {
    $postsData = getPosts($page, 20, null, null);
}

$allPosts = $postsData['posts'];
$totalPages = $postsData['total_pages'];

// Search filter
if (!empty($searchQuery)) {
    $query = strtolower($searchQuery);
    $allPosts = array_filter($allPosts, function($post) use ($query) {
        return strpos(strtolower($post['content']), $query) !== false ||
               strpos(strtolower($post['author_name']), $query) !== false ||
               strpos(strtolower($post['author_username']), $query) !== false;
    });
    $allPosts = array_values($allPosts);
}

function formatTimeAgo($datetime) {
    $time = strtotime($datetime);
    $now = time();
    $diff = $now - $time;
    
    if ($diff < 60) return 'ahora';
    if ($diff < 3600) return floor($diff / 60) . 'm';
    if ($diff < 86400) return floor($diff / 3600) . 'h';
    if ($diff < 604800) return floor($diff / 86400) . 'd';
    return date('d M', $time);
}

function escapeHtml($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

$blockStats = DEBUG_MODE ? getBlockStats() : null;
$followStats = $viewedUser ? getFollowStats($viewedUser['id']) : null;
$isFollowing = $viewedUser && $viewedUser['id'] !== $currentUser['id'] ? isFollowing($currentUser['id'], $viewedUser['id']) : false;
$currentFollowStats = getFollowStats($currentUser['id']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Foro · LS Community</title>
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#000000" id="themeColorMeta">
    <link rel="icon" type="image/x-icon" href="https://ls.dilivel.com/img/lov.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
        :root { --bg-primary: #000000; --bg-secondary: #000000; --bg-card: #000000; --bg-hover: #080808; --border-color: #2f3336; --text-primary: #e7e9ea; --text-secondary: #71767b; --accent: #1d9bf0; --like-color: #f91880; --nav-height: 60px; }
        body.light { --bg-primary: #ffffff; --bg-secondary: #ffffff; --bg-card: #ffffff; --bg-hover: #f7f7f7; --border-color: #eff3f4; --text-primary: #0f1419; --text-secondary: #536471; --accent: #1d9bf0; --like-color: #f91880; }
        body { background-color: var(--bg-primary); color: var(--text-primary); transition: background-color 0.2s; }
        .app-container { display: flex; max-width: 1280px; margin: 0 auto; min-height: 100vh; }
        
        /* Desktop Sidebar (hidden on mobile) */
        .sidebar-left { width: 275px; position: sticky; top: 0; height: 100vh; padding: 8px 12px; border-right: 1px solid var(--border-color); background-color: var(--bg-primary); }
        .logo { padding: 12px; margin-bottom: 20px; }
        .logo i { font-size: 32px; color: var(--accent); }
        .nav-menu { display: flex; flex-direction: column; gap: 8px; }
        .nav-item { display: flex; align-items: center; gap: 16px; padding: 12px; border-radius: 30px; cursor: pointer; transition: background 0.2s; color: var(--text-primary); text-decoration: none; font-size: 20px; }
        .nav-item i { font-size: 26px; width: 30px; }
        .nav-item:hover, .profile-card:hover { background-color: var(--bg-hover); }
        .nav-item.active { font-weight: 700; }
        .profile-card { margin-top: 40px; padding: 12px; border-top: 0px solid var(--border-color); display: flex; align-items: center; gap: 12px; cursor: pointer; border-radius: 30px; text-decoration: none; color: var(--text-primary); }
        .profile-avatar-sm { width: 40px; height: 40px; border-radius: 50%; object-fit: cover; }
        .profile-name-sm { font-weight: 700; font-size: 15px; }
        .profile-username-sm { font-size: 13px; color: var(--text-secondary); }
        
        /* Mobile Bottom Navigation */
        .bottom-nav { display: none; position: fixed; bottom: 0; left: 0; right: 0; background: var(--bg-primary); border-top: 1px solid var(--border-color); padding: 8px 16px; z-index: 100; backdrop-filter: blur(20px); }
        .bottom-nav-items { display: flex; justify-content: space-around; align-items: center; }
        .bottom-nav-item { display: flex; flex-direction: column; align-items: center; gap: 4px; text-decoration: none; color: var(--text-secondary); font-size: 12px; transition: color 0.2s; padding: 4px 0; }
        .bottom-nav-item i { font-size: 24px; }
        .bottom-nav-item.active { color: var(--accent); }
        .bottom-nav-item span { font-size: 11px; }
        .mobile-profile-avatar { width: 28px; height: 28px; border-radius: 50%; object-fit: cover; border: 2px solid transparent; }
        .bottom-nav-item.active .mobile-profile-avatar { border-color: var(--accent); }
        
        .main-feed { flex: 1; max-width: 600px; border-left: 1px solid var(--border-color); border-right: 1px solid var(--border-color); min-height: 100vh; padding-bottom: 80px; }
        .feed-header { position: sticky; top: 0; background-color: var(--bg-primary); backdrop-filter: blur(12px); padding: 9px 15px; border-bottom: 1px solid var(--border-color); z-index: 10; display: flex; justify-content: space-between; align-items: center; }
        .feed-header h2 { font-size: 20px; font-weight: 700; }
        .logout-btn { background: none; border: none; color: var(--text-secondary); cursor: pointer; font-size: 16px; padding: 8px; border-radius: 30px; text-decoration: none; display: inline-block; }
        .logout-btn:hover { background: var(--bg-hover); color: var(--accent); }
        .search-bar-container { padding: 12px 16px; border-bottom: 1px solid var(--border-color); }
        .search-box { background-color: var(--bg-hover); border-radius: 30px; display: flex; align-items: center; padding: 8px 16px; gap: 12px; }
        .search-box i { color: var(--text-secondary); }
        .search-box input { background: transparent; border: none; outline: none; color: var(--text-primary); font-size: 15px; width: 100%; }
        .new-post-section { padding: 16px; border-bottom: 1px solid var(--border-color); }
        .new-post-form { display: flex; gap: 12px; }
        .avatar { min-width: 48px; min-height: 48px; max-width: 48px; max-height: 48px; width: 48px; height: 48px; border-radius: 50%; object-fit: cover; }
        .post-input-area { flex: 1; }
        .post-input { width: 100%; background: transparent; border: none; font-size: 18px; padding: 8px 0; color: var(--text-primary); resize: none; font-family: inherit; outline: none; }
        .image-preview { margin-top: 12px; position: relative; display: inline-block; }
        .image-preview img { max-width: 100%; max-height: 200px; border-radius: 16px; border: 1px solid var(--border-color); }
        .remove-img { position: absolute; top: 8px; left: 8px; background: rgba(0,0,0,0.6); border-radius: 50%; width: 28px; height: 28px; min-width: 28px; min-height: 28px; max-width: 28px; max-height: 28px; display: flex; align-items: center; justify-content: center; cursor: pointer; color: white; }
        .post-actions-bar { display: flex; justify-content: space-between; align-items: center; margin-top: 12px; }
        .media-btn { background: none; border: none; color: var(--accent); font-size: 20px; cursor: pointer; padding: 8px; border-radius: 50%; }
        .media-btn:hover { background-color: rgba(29, 155, 240, 0.1); }
        .submit-post { background-color: var(--accent); color: white; border: none; border-radius: 24px; padding: 8px 20px; font-weight: 700; cursor: pointer; }
        .posts-feed { display: flex; flex-direction: column; }
        .post-card { padding: 10px; border-bottom: 1px solid var(--border-color); transition: background 0.2s; position: relative; }
        .post-card2 { padding: 10px; border-bottom: 1px solid var(--border-color); transition: background 0.2s; position: relative; }
        .post-card:hover { background-color: var(--bg-hover); }
        .post-header { display: flex; gap: 12px; }
        .post-avatar { min-width: 40px; min-height: 40px; max-width: 40px; max-height: 40px; width: 40px; height: 40px; border-radius: 50%; object-fit: cover; cursor: pointer; }
        .post-content { flex: 1; }
        .post-author-row { display: flex; align-items: baseline; flex-wrap: wrap; gap: 6px; margin-bottom: 4px; }
        .post-name { font-weight: 700; font-size: 15px; cursor: pointer; }
        .post-name:hover { text-decoration: underline; }
        .post-username { font-size: 14px; color: var(--text-secondary); cursor: pointer; }
        .post-username:hover { text-decoration: underline; }
        .post-time { font-size: 14px; color: var(--text-secondary); }
        .post-time::before { content: "·"; margin-right: 4px; }
        .post-text { font-size: 15px; line-height: 1.5; margin-bottom: 12px; white-space: pre-wrap; word-wrap: break-word; overflow-wrap: break-word; }
        .post-link { color: #1da1f2; text-decoration: none; word-break: break-all; }
        .post-link:hover { text-decoration: underline; }
        .post-mention { color: #1da1f2; text-decoration: none; font-weight: 500; }
        .post-mention:hover { text-decoration: underline; }
        .post-hashtag { color: #1da1f2; text-decoration: none; }
        .post-hashtag:hover { text-decoration: underline; cursor: pointer; }
        .post-image { margin-top: 8px; border-radius: 16px; max-width: 100%; max-height: 400px; object-fit: cover; border: 1px solid var(--border-color); cursor: pointer; }
        .post-actions { display: flex; justify-content: space-between; max-width: 425px; margin-top: 12px; gap: 8px; }
        .action-btn { display: flex; align-items: center; gap: 8px; background: none; border: none; color: var(--text-secondary); font-size: 14px; cursor: pointer; padding: 6px 8px; border-radius: 30px; transition: all 0.15s; }
        .action-btn i { font-size: 18px; }
        .action-btn:hover { background-color: rgba(29, 155, 240, 0.1); color: var(--accent); }
        .action-btn.liked { color: var(--like-color); }
        .post-menu { position: relative; display: inline-block; margin-left: auto; }
        .menu-btn { background: none; border: none; color: var(--text-secondary); cursor: pointer; padding: 4px 8px; border-radius: 50%; }
        .menu-btn:hover { background: var(--bg-hover); }
        .dropdown-menu { display: none; position: absolute; right: 0; top: 30px; background: var(--bg-primary); border: 1px solid var(--border-color); border-radius: 12px; z-index: 20; min-width: 120px; }
        .dropdown-menu.show { display: block; }
        .dropdown-item { padding: 10px 16px; cursor: pointer; font-size: 14px; }
        .dropdown-item:hover { background: var(--bg-hover); }
        .dropdown-item.delete { color: #f4212e; }
        .sidebar-right { width: 350px; padding: 16px 20px; position: sticky; top: 0; height: 100vh; overflow-y: auto; }
        .profile-card-large { background-color: transparent; border-bottom: 1px solid var(--border-color); border-radius: 0px; padding: 20px; margin-bottom: 20px; text-align: center; }
        .profile-avatar-large { width: 100px; height: 100px; border-radius: 50%; object-fit: cover; margin-bottom: 12px; }
        .profile-name-large { font-size: 20px; font-weight: 700; margin-bottom: 4px; }
        .profile-username-large { color: var(--text-secondary); margin-bottom: 12px; }
        .profile-bio { font-size: 14px; margin-bottom: 16px; }
        .follow-stats { display: flex; justify-content: center; gap: 24px; margin-bottom: 16px; padding: 12px 0; border-top: 1px solid var(--border-color); border-bottom: 1px solid var(--border-color); }
        .stat-item { text-align: center; cursor: pointer; }
        .stat-number { font-weight: 700; font-size: 18px; }
        .stat-label { color: var(--text-secondary); font-size: 14px; }
        .follow-btn { background-color: var(--accent); color: white; border: none; border-radius: 30px; padding: 10px 20px; font-weight: 700; cursor: pointer; width: 100%; }
        .follow-btn.following { background-color: var(--bg-hover); color: var(--text-primary); border: 1px solid var(--border-color); }
        .trends-card { background-color: var(--bg-hover); border-radius: 16px; padding: 16px; margin-bottom: 20px; }
        .trends-card h3 { font-size: 20px; margin-bottom: 16px; }
        .trend-item { padding: 12px 0; cursor: pointer; }
        .trend-category { font-size: 13px; color: var(--text-secondary); }
        .trend-name { font-weight: 700; font-size: 15px; }
        .theme-toggle-side { margin-top: 20px; padding: 12px; background-color: var(--bg-hover); border-radius: 30px; display: flex; align-items: center; justify-content: space-between; cursor: pointer; }
        .profile-modal, .edit-post-modal { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.8); z-index: 1000; justify-content: center; align-items: center; }
        .profile-modal-content, .edit-modal-content { background: var(--bg-primary); border-radius: 20px; height: auto; max-width: 500px; width: 90%; padding: 24px; border: 1px solid var(--border-color); }
        .profile-header { display: flex; justify-content: space-between; margin-bottom: 20px; }
        .close-modal { cursor: pointer; font-size: 24px; }
        .profile-details { text-align: center; }
        .profile-details img { width: 100px; height: 100px; max-width: 100px; max-height: 100px; min-width: 100px; min-height: 100px; border-radius: 50%; object-fit: cover; margin-bottom: 16px; }
        .edit-input, .edit-textarea { width: 100%; padding: 12px; margin-bottom: 12px; background: var(--bg-hover); border: 1px solid var(--border-color); border-radius: 8px; color: var(--text-primary); }
        .btn-save { background: var(--accent); color: white; border: none; padding: 10px 20px; border-radius: 30px; cursor: pointer; }
        .empty-feed { text-align: center; padding: 60px 20px; }
        .loading { text-align: center; padding: 20px; color: var(--text-secondary); }
        .comment-count { cursor: pointer; }
        .comment-count:hover { text-decoration: underline; }
        .pagination { display: flex; justify-content: center; gap: 8px; padding: 20px; border-top: 1px solid var(--border-color); }
        .page-btn { padding: 8px 12px; background: var(--bg-hover); border: none; color: var(--text-primary); border-radius: 8px; cursor: pointer; }
        .page-btn.active { background: var(--accent); color: white; }
        .page-btn:hover:not(.active) { background: var(--border-color); }
        .user-list-modal { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.8); z-index: 1000; justify-content: center; align-items: center; }
        .user-list-content { background: var(--bg-primary); border-radius: 20px; max-width: 400px; width: 90%; padding: 20px; border: 1px solid var(--border-color); max-height: 80vh; overflow-y: auto; }
        .user-list-header { display: flex; justify-content: space-between; margin-bottom: 16px; }
        .user-list-item { display: flex; align-items: center; gap: 12px; padding: 12px; border-bottom: 0px solid var(--border-color); cursor: pointer; }
        .user-list-item:hover { background: var(--bg-hover); }
        .user-list-avatar { width: 40px; height: 40px; border-radius: 50%; object-fit: cover; }
        .user-list-info { flex: 1; }
        .user-list-name { font-weight: 700; }
        .user-list-username { font-size: 12px; color: var(--text-secondary); }
        
        /* Mobile Floating Action Button */
        .fab-create-post { display: none; position: fixed; bottom: 70px; right: 10px; background-color: var(--accent); color: white; width: 56px; height: 56px; border-radius: 50%; align-items: center; justify-content: center; cursor: pointer; box-shadow: 0 4px 12px rgba(0,0,0,0.3); z-index: 90; transition: transform 0.2s; }
        .fab-create-post:hover { transform: scale(1.05); }
        .fab-create-post i { font-size: 24px; }
        
        @media (max-width: 1000px) { 
            .sidebar-right { display: none; } 
            .sidebar-left { width: 88px; } 
            .nav-item span { display: none; } 
            .profile-info-sm { display: none; } 
            .profile-card { justify-content: center; } 
        }
        
        @media (max-width: 688px) { 
            .sidebar-left { display: none; } 
            .main-feed { max-width: 100%; margin-bottom: 0; border: 0px solid transparent; }
            .bottom-nav { display: block; }
            .fab-create-post { display: flex; }
            .main-feed { padding-bottom: 70px; }
            .new-post-section { display: none; }
        }
    </style>
</head>
<body>
<div class="app-container">
    <!-- Desktop Sidebar -->
    <div class="sidebar-left">
        <div class="logo"><img style="border-radius: 0px; height: 35px; width: auto; border: none; padding: 6px; background: red; border-radius: 5px;" src="https://ls.dilivel.com/img/logico.png" alt="Fresh smoothie"></i></div>
        <div class="nav-menu">
            <a href="home.php?view=home" class="nav-item <?php echo $currentView === 'home' && !$userId ? 'active' : ''; ?>" data-view="home"><i class="fas fa-home"></i><span>Inicio</span></a>
            <a href="home.php?view=explore" class="nav-item <?php echo $currentView === 'explore' ? 'active' : ''; ?>" data-view="explore"><i class="fas fa-hashtag"></i><span>Explorar</span></a>
            <a href="home.php?view=profile" class="nav-item <?php echo $currentView === 'profile' && !$userId ? 'active' : ''; ?>" data-view="profile"><i class="fas fa-user"></i><span>Perfil</span></a>
        </div>
        <a href="home.php?view=profile" class="profile-card">
            <img class="profile-avatar-sm" src="<?php echo escapeHtml($currentUser['avatar']); ?>" alt="avatar">
            <div class="profile-info-sm">
                <div class="profile-name-sm"><?php echo escapeHtml($currentUser['name']); ?></div>
                <div class="profile-username-sm">@<?php echo escapeHtml($currentUser['username']); ?></div>
            </div>
        </a>
    </div>

    <!-- Main Feed -->
    <div class="main-feed">
        <div class="feed-header">
            <h2>
                <?php 
                if ($username && $viewedUser) {
                    echo 'Publicaciones de ' . escapeHtml($viewedUser['name']);
                } elseif ($currentView === 'profile') {
                    echo 'Mis Publicaciones';
                } elseif ($currentView === 'explore') {
                    echo 'Explorar';
                } else {
                    echo 'Inicio';
                }
                ?>
            </h2>
            <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i></a>
        </div>
        
        <div class="search-bar-container">
            <div class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" id="searchInput" placeholder="Buscar en tiempo real..." autocomplete="off">
            </div>
        </div>
        
        <?php if ($username && $viewedUser): ?>
        <div class="profile-card-large">
            <div class="post-card2" style="border-bottom: solid 0px transparent;">
                <div class="post-header">
                    <img class="post-avatar" style="min-width: 90px; min-height: 90px; max-width: 90px; max-height: 90px; width: 90px; height: 90px;" src="<?php echo escapeHtml($viewedUser['avatar']); ?>" alt="avatar">
                    <div style="margin-top: 18px;" class="post-content">
                        <div class="post-author-row">
                            <h2 class="post-name" style="font-size: 22px;"><?php echo escapeHtml($viewedUser['name']); ?></h2>
                        </div>
                        <div style="text-align: left;" class="post-text"><div class="profile-username-large">@<?php echo escapeHtml($viewedUser['username']); ?></div></div>
                    </div>
                </div>
            </div>
            
            <?php if (!empty($viewedUser['bio'])): ?>
            <div style="text-align: left;" class="profile-bio"><?php echo escapeHtml($viewedUser['bio']); ?></div>
            <?php endif; ?>
            <div class="follow-stats">
                <div class="stat-item" onclick="showUserList('following', '<?php echo $viewedUser['id']; ?>')">
                    <div class="stat-number"><?php echo $followStats['following']; ?></div>
                    <div class="stat-label">Siguiendo</div>
                </div>
                <div class="stat-item" onclick="showUserList('followers', '<?php echo $viewedUser['id']; ?>')">
                    <div class="stat-number"><?php echo $followStats['followers']; ?></div>
                    <div class="stat-label">Seguidores</div>
                </div>
            </div>
            <?php if ($viewedUser['id'] !== $currentUser['id']): ?>
            <button class="follow-btn <?php echo $isFollowing ? 'following' : ''; ?>" id="followBtn" onclick="toggleFollow('<?php echo $viewedUser['id']; ?>')">
                <i class="<?php echo $isFollowing ? 'fas fa-user-check' : 'fas fa-user-plus'; ?>"></i>
                <span><?php echo $isFollowing ? 'Siguiendo' : 'Seguir'; ?></span>
            </button>
            <?php else: ?>
            <button class="follow-btn" id="editProfileSideBtn" onclick="openProfileModal()" style="background: var(--bg-hover); color: var(--text-primary); border: 1px solid var(--border-color);">
                <i class="fas fa-edit"></i> Editar perfil
            </button>
            <?php endif; ?>
        </div>
        <?php elseif ($currentView === 'profile' && !$userId): ?>
        <div class="profile-card-large">
            <img class="profile-avatar-large" src="<?php echo escapeHtml($currentUser['avatar']); ?>" alt="avatar">
            <div class="profile-name-large"><?php echo escapeHtml($currentUser['name']); ?></div>
            <div class="profile-username-large">@<?php echo escapeHtml($currentUser['username']); ?></div>
            <?php if (!empty($currentUser['bio'])): ?>
            <div class="profile-bio"><?php echo escapeHtml($currentUser['bio']); ?></div>
            <?php endif; ?>
            <div class="follow-stats">
                <div class="stat-item" onclick="showUserList('following', '<?php echo $currentUser['id']; ?>')">
                    <div class="stat-number"><?php echo $currentFollowStats['following']; ?></div>
                    <div class="stat-label">Siguiendo</div>
                </div>
                <div class="stat-item" onclick="showUserList('followers', '<?php echo $currentUser['id']; ?>')">
                    <div class="stat-number"><?php echo $currentFollowStats['followers']; ?></div>
                    <div class="stat-label">Seguidores</div>
                </div>
            </div>
            <button class="follow-btn" id="editProfileSideBtn" onclick="openProfileModal()" style="background: var(--bg-hover); color: var(--text-primary); border: 1px solid var(--border-color);">
                <i class="fas fa-edit"></i> Editar perfil
            </button>
        </div>
        <?php endif; ?>
        
        <!-- Desktop Create Post Section (hidden on mobile) -->
        <div class="new-post-section desktop-post-section">
            <div class="new-post-form">
                <img class="avatar" src="<?php echo escapeHtml($currentUser['avatar']); ?>" alt="avatar">
                <div class="post-input-area">
                    <textarea class="post-input" id="postTextarea" rows="2" placeholder="¿Qué está pasando?"></textarea>
                    <div id="imagePreviewContainer" class="image-preview" style="display: none;">
                        <img id="previewImage" src="" alt="preview">
                        <div class="remove-img" id="removeImageBtn"><i class="fas fa-times"></i></div>
                    </div>
                    <div class="post-actions-bar">
                        <label class="media-btn">
                            <i class="fas fa-image"></i>
                            <input type="file" id="imageUpload" accept="image/*" style="display: none;">
                        </label>
                        <button class="submit-post" id="submitPostBtn">Publicar</button>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="posts-feed" id="postsFeed">
            <div class="loading">Cargando publicaciones...</div>
        </div>
        
        <?php if ($totalPages > 1 && !$searchQuery): ?>
        <div class="pagination" id="pagination">
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <button class="page-btn <?php echo $i == $page ? 'active' : ''; ?>" data-page="<?php echo $i; ?>"><?php echo $i; ?></button>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Desktop Right Sidebar -->
    <div class="sidebar-right">
        <div class="trends-card">
            <h3><i class="fas fa-chart-line"></i> Tendencias</h3>
            <div class="trend-item" data-trend="#LSEngine"><div class="trend-category">Tecnología</div><div class="trend-name">#LSEngine</div></div>
            <div class="trend-item" data-trend="#WebSockets"><div class="trend-category">Desarrollo</div><div class="trend-name">#WebSockets</div></div>
            <div class="trend-item" data-trend="#PHP"><div class="trend-category">Programación</div><div class="trend-name">#PHP</div></div>
        </div>
        <div class="theme-toggle-side" id="themeToggleSide"><span><i class="fas fa-adjust"></i> Modo oscuro/claro</span><i class="fas fa-moon" id="themeIcon"></i></div>
    </div>
</div>

<!-- Mobile Bottom Navigation -->
<div class="bottom-nav">
    <div class="bottom-nav-items">
        <a href="home.php?view=home" class="bottom-nav-item <?php echo $currentView === 'home' && !$userId ? 'active' : ''; ?>">
            <i class="fas fa-home"></i>
        </a>
        <a href="home.php?view=explore" class="bottom-nav-item <?php echo $currentView === 'explore' ? 'active' : ''; ?>">
            <i class="fas fa-hashtag"></i>
        </a>
        <a href="home.php?view=profile" class="bottom-nav-item <?php echo $currentView === 'profile' && !$userId ? 'active' : ''; ?>">
            <img class="mobile-profile-avatar" src="<?php echo escapeHtml($currentUser['avatar']); ?>" alt="avatar">
        </a>
    </div>
</div>

<!-- Mobile Floating Action Button for Create Post -->
<div class="fab-create-post" id="fabCreatePost">
    <i class="fas fa-plus"></i>
</div>

<!-- Mobile Create Post Modal -->
<div id="mobilePostModal" class="profile-modal">
    <div class="profile-modal-content" style="max-width: 95%;">
        <div class="profile-header">
            <h3>Nueva publicación</h3>
            <span class="close-modal" id="closeMobileModal">&times;</span>
        </div>
        <div class="new-post-form" style="flex-direction: column;">
            <div style="display: flex; gap: 12px;">
                <img class="avatar" src="<?php echo escapeHtml($currentUser['avatar']); ?>" alt="avatar">
                <textarea class="post-input" id="mobilePostTextarea" rows="3" placeholder="¿Qué está pasando?"></textarea>
            </div>
            <div id="mobileImagePreviewContainer" class="image-preview" style="display: none;">
                <img id="mobilePreviewImage" src="" alt="preview">
                <div class="remove-img" id="mobileRemoveImageBtn"><i class="fas fa-times"></i></div>
            </div>
            <div class="post-actions-bar" style="margin-top: 16px;">
                <label class="media-btn">
                    <i class="fas fa-image"></i>
                    <input type="file" id="mobileImageUpload" accept="image/*" style="display: none;">
                </label>
                <button class="submit-post" id="mobileSubmitPostBtn">Publicar</button>
            </div>
        </div>
    </div>
</div>

<!-- User List Modal -->
<div id="userListModal" class="user-list-modal">
    <div class="user-list-content">
        <div class="user-list-header">
            <h3 id="userListTitle">Usuarios</h3>
            <span class="close-modal" onclick="closeUserListModal()">&times;</span>
        </div>
        <div id="userListContainer"></div>
    </div>
</div>

<!-- Profile Modal -->
<div id="profileModal" class="profile-modal">
    <div class="profile-modal-content">
        <div class="profile-header">
            <h3>Editar perfil</h3>
            <span class="close-modal" id="closeProfileModal">&times;</span>
        </div>
        <form id="profileForm" enctype="multipart/form-data">
            <div class="profile-details">
                <div style="position: relative; display: inline-block;">
                    <img id="modalAvatar" src="<?php echo escapeHtml($currentUser['avatar']); ?>" alt="avatar" style="width: 100px; height: 100px; border-radius: 50%; object-fit: cover;">
                    <label for="avatarUpload" style="position: absolute; bottom: 0; right: 0; background: var(--accent); border-radius: 50%; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; cursor: pointer;">
                        <i class="fas fa-camera" style="color: white; font-size: 14px;"></i>
                    </label>
                    <input type="file" id="avatarUpload" name="avatar" accept="image/*" style="display: none;">
                </div>
                <input type="text" name="name" id="profileName" value="<?php echo escapeHtml($currentUser['name']); ?>" class="edit-input" style="margin-top: 16px;">
                <textarea name="bio" id="profileBio" class="edit-textarea" rows="3"><?php echo escapeHtml($currentUser['bio']); ?></textarea>
                <button type="submit" class="btn-save">Guardar cambios</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Post Modal -->
<div id="editPostModal" class="profile-modal">
    <div class="profile-modal-content edit-modal-content">
        <div class="profile-header"><h3>Editar publicación</h3><span class="close-modal" id="closeEditModal">&times;</span></div>
        <div>
            <textarea id="editPostText" class="edit-textarea" rows="4"></textarea>
            <button class="btn-save" id="saveEditBtn">Guardar cambios</button>
        </div>
    </div>
</div>

<script>
const currentUserId = '<?php echo $currentUser['id']; ?>';
let currentEditPostId = null;
let searchTimeout = null;
let allPosts = <?php echo json_encode($allPosts); ?>;

function formatTimeAgo(dateString) {
    const date = new Date(dateString);
    const now = new Date();
    const diff = Math.floor((now - date) / 1000);
    
    if (diff < 60) return 'ahora';
    if (diff < 3600) return Math.floor(diff / 60) + 'm';
    if (diff < 86400) return Math.floor(diff / 3600) + 'h';
    if (diff < 604800) return Math.floor(diff / 86400) + 'd';
    return date.toLocaleDateString('es', { day: 'numeric', month: 'short' });
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function linkifyText(text) {
    if (!text) return '';
    
    let escaped = escapeHtml(text);
    
    const urlRegex = /(https?:\/\/[^\s]+)/g;
    escaped = escaped.replace(urlRegex, function(url) {
        return `<a href="${url}" target="_blank" rel="noopener noreferrer" class="post-link">${url}</a>`;
    });
    
    const mentionRegex = /@(\w+)/g;
    escaped = escaped.replace(mentionRegex, function(mention, username) {
        return `<a href="home.php?user=${encodeURIComponent(username)}" class="post-mention">@${username}</a>`;
    });
    
    const hashtagRegex = /#(\w+)/g;
    escaped = escaped.replace(hashtagRegex, function(hashtag, tag) {
        return `<a href="javascript:void(0)" onclick="searchHashtag('${escapeHtml(tag)}')" class="post-hashtag">#${tag}</a>`;
    });
    
    escaped = escaped.replace(/\n/g, '<br>');
    
    return escaped;
}

function searchHashtag(tag) {
    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.value = '#' + tag;
        searchInput.dispatchEvent(new Event('input'));
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }
}

function renderPosts(posts) {
    const feedContainer = document.getElementById('postsFeed');
    
    if (!posts || posts.length === 0) {
        feedContainer.innerHTML = '<div class="empty-feed"><i class="fas fa-comment-dots" style="font-size: 48px; opacity: 0.5;"></i><p style="margin-top: 16px;">No hay publicaciones</p></div>';
        return;
    }
    
    let html = '';
    for (const post of posts) {
        const isLiked = post.likes && post.likes.some(like => like.user_id === currentUserId);
        const likeCount = post.likes ? post.likes.length : 0;
        const commentCount = post.comments ? post.comments.length : 0;
        
        html += `
            <div class="post-card" data-post-id="${escapeHtml(post.id)}">
                <div class="post-header">
                    <img class="post-avatar" src="${escapeHtml(post.author_avatar)}" alt="avatar" onclick="location.href='home.php?user=${encodeURIComponent(post.author_username)}'">
                    <div class="post-content">
                        <div class="post-author-row">
                            <span class="post-name" onclick="location.href='home.php?user=${encodeURIComponent(post.author_username)}'">${escapeHtml(post.author_name)}</span>
                            <span class="post-username" onclick="location.href='home.php?user=${encodeURIComponent(post.author_username)}'">@${escapeHtml(post.author_username)}</span>
                            <span class="post-time">${formatTimeAgo(post.created_at)}</span>
                            ${post.user_id === currentUserId ? `
                            <div class="post-menu">
                                <button class="menu-btn" onclick="toggleMenu(this)"><i class="fas fa-ellipsis-h"></i></button>
                                <div class="dropdown-menu">
                                    <div class="dropdown-item" onclick="openEditModal('${escapeHtml(post.id)}', '${escapeHtml(post.content).replace(/'/g, "\\'")}')">Editar</div>
                                    <div class="dropdown-item delete" onclick="deletePost('${escapeHtml(post.id)}')">Eliminar</div>
                                </div>
                            </div>
                            ` : ''}
                        </div>
                        <div class="post-text">${linkifyText(post.content)}</div>
                        ${post.image ? `<img class="post-image" src="${escapeHtml(post.image)}" alt="post image" loading="lazy">` : ''}
                        <div class="post-actions">
                            <button class="action-btn comment-btn" onclick="location.href='comment.php?post_id=${escapeHtml(post.id)}'">
                                <i class="far fa-comment"></i>
                                <span class="comment-count">${commentCount}</span>
                            </button>
                            <button class="action-btn retweet-btn" onclick="retweetPost('${escapeHtml(post.id)}')">
                                <i class="fas fa-retweet"></i>
                                <span>${post.retweets || 0}</span>
                            </button>
                            <button class="action-btn like-btn ${isLiked ? 'liked' : ''}" onclick="likePost('${escapeHtml(post.id)}')">
                                <i class="${isLiked ? 'fas fa-heart' : 'far fa-heart'}"></i>
                                <span class="like-count">${likeCount}</span>
                            </button>
                            <button class="action-btn share-btn" onclick="sharePost('${escapeHtml(post.id)}')">
                                <i class="fas fa-share"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;
    }
    
    feedContainer.innerHTML = html;
}

document.getElementById('searchInput')?.addEventListener('input', function(e) {
    clearTimeout(searchTimeout);
    const query = e.target.value.toLowerCase();
    
    searchTimeout = setTimeout(() => {
        if (query === '') {
            renderPosts(allPosts);
        } else {
            const filtered = allPosts.filter(post => 
                (post.content && post.content.toLowerCase().includes(query)) || 
                post.author_name.toLowerCase().includes(query) ||
                post.author_username.toLowerCase().includes(query)
            );
            renderPosts(filtered);
        }
    }, 300);
});

// Mobile Create Post Modal
const fabCreatePost = document.getElementById('fabCreatePost');
const mobilePostModal = document.getElementById('mobilePostModal');
const closeMobileModal = document.getElementById('closeMobileModal');

if (fabCreatePost) {
    fabCreatePost.addEventListener('click', () => {
        mobilePostModal.style.display = 'flex';
    });
}

if (closeMobileModal) {
    closeMobileModal.addEventListener('click', () => {
        mobilePostModal.style.display = 'none';
    });
}

// Mobile image upload
let mobileImageData = null;
const mobileImageInput = document.getElementById('mobileImageUpload');
const mobilePreviewContainer = document.getElementById('mobileImagePreviewContainer');
const mobilePreviewImage = document.getElementById('mobilePreviewImage');
const mobileRemoveImageBtn = document.getElementById('mobileRemoveImageBtn');

if (mobileImageInput) {
    mobileImageInput.addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function(ev) {
                mobileImageData = ev.target.result;
                mobilePreviewImage.src = mobileImageData;
                mobilePreviewContainer.style.display = 'block';
            };
            reader.readAsDataURL(file);
        }
    });
}

function removeMobileSelectedImage() {
    mobileImageData = null;
    if (mobilePreviewContainer) mobilePreviewContainer.style.display = 'none';
    if (mobilePreviewImage) mobilePreviewImage.src = '';
    if (mobileImageInput) mobileImageInput.value = '';
}

if (mobileRemoveImageBtn) {
    mobileRemoveImageBtn.addEventListener('click', removeMobileSelectedImage);
}

// Mobile submit post
document.getElementById('mobileSubmitPostBtn')?.addEventListener('click', async function() {
    const text = document.getElementById('mobilePostTextarea').value.trim();
    const imageData = mobileImageData || '';
    
    if (!text && !imageData) {
        alert('Escribe algo o agrega una imagen');
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'create_post');
    formData.append('text', text);
    formData.append('image_data', imageData);
    
    try {
        const response = await fetch('home.php', {
            method: 'POST',
            body: formData
        });
        const result = await response.json();
        
        if (result.success) {
            allPosts.unshift(result.post);
            renderPosts(allPosts);
            document.getElementById('mobilePostTextarea').value = '';
            removeMobileSelectedImage();
            mobilePostModal.style.display = 'none';
        } else {
            alert(result.message);
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Error al publicar');
    }
});

// Desktop create post
let currentImageData = null;
const imageInput = document.getElementById('imageUpload');
const previewContainer = document.getElementById('imagePreviewContainer');
const previewImage = document.getElementById('previewImage');
const removeImageBtn = document.getElementById('removeImageBtn');

if (imageInput) {
    imageInput.addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function(ev) {
                currentImageData = ev.target.result;
                previewImage.src = currentImageData;
                previewContainer.style.display = 'block';
            };
            reader.readAsDataURL(file);
        }
    });
}

function removeSelectedImage() {
    currentImageData = null;
    if (previewContainer) previewContainer.style.display = 'none';
    if (previewImage) previewImage.src = '';
    if (imageInput) imageInput.value = '';
}

if (removeImageBtn) {
    removeImageBtn.addEventListener('click', removeSelectedImage);
}

document.getElementById('submitPostBtn')?.addEventListener('click', async function() {
    const text = document.getElementById('postTextarea').value.trim();
    const imageData = currentImageData || '';
    
    if (!text && !imageData) {
        alert('Escribe algo o agrega una imagen');
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'create_post');
    formData.append('text', text);
    formData.append('image_data', imageData);
    
    try {
        const response = await fetch('home.php', {
            method: 'POST',
            body: formData
        });
        const result = await response.json();
        
        if (result.success) {
            allPosts.unshift(result.post);
            renderPosts(allPosts);
            document.getElementById('postTextarea').value = '';
            removeSelectedImage();
            document.getElementById('postTextarea').style.height = 'auto';
        } else {
            alert(result.message);
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Error al publicar');
    }
});

// Like post
async function likePost(postId) {
    const formData = new FormData();
    formData.append('action', 'like_post');
    formData.append('post_id', postId);
    
    try {
        const response = await fetch('home.php', {
            method: 'POST',
            body: formData
        });
        const result = await response.json();
        
        if (result.success) {
            const post = allPosts.find(p => p.id === postId);
            if (post) {
                post.likes = result.likes;
            }
            renderPosts(allPosts);
        }
    } catch (error) {
        console.error('Error:', error);
    }
}

// Retweet post
async function retweetPost(postId) {
    const formData = new FormData();
    formData.append('action', 'retweet_post');
    formData.append('post_id', postId);
    
    try {
        const response = await fetch('home.php', {
            method: 'POST',
            body: formData
        });
        const result = await response.json();
        
        if (result.success) {
            const post = allPosts.find(p => p.id === postId);
            if (post) {
                post.retweets = result.retweets;
            }
            renderPosts(allPosts);
        }
    } catch (error) {
        console.error('Error:', error);
    }
}

// Delete post
async function deletePost(postId) {
    if (!confirm('¿Eliminar esta publicación?')) return;
    
    const formData = new FormData();
    formData.append('action', 'delete_post');
    formData.append('post_id', postId);
    
    try {
        const response = await fetch('home.php', {
            method: 'POST',
            body: formData
        });
        const result = await response.json();
        
        if (result.success) {
            allPosts = allPosts.filter(p => p.id !== postId);
            renderPosts(allPosts);
        } else {
            alert(result.message || 'Error al eliminar');
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Error al eliminar');
    }
}

// Edit post
function openEditModal(postId, text) {
    currentEditPostId = postId;
    document.getElementById('editPostText').value = text;
    document.getElementById('editPostModal').style.display = 'flex';
}

document.getElementById('saveEditBtn')?.addEventListener('click', async function() {
    const newText = document.getElementById('editPostText').value.trim();
    if (!newText) {
        alert('El texto no puede estar vacío');
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'edit_post');
    formData.append('post_id', currentEditPostId);
    formData.append('text', newText);
    
    try {
        const response = await fetch('home.php', {
            method: 'POST',
            body: formData
        });
        const result = await response.json();
        
        if (result.success) {
            const post = allPosts.find(p => p.id === currentEditPostId);
            if (post) {
                post.content = newText;
            }
            renderPosts(allPosts);
            document.getElementById('editPostModal').style.display = 'none';
        } else {
            alert(result.message || 'Error al editar');
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Error al editar');
    }
});

// Share post
function sharePost(postId) {
    const url = `${window.location.origin}${window.location.pathname}?post=${postId}`;
    navigator.clipboard.writeText(url);
    alert('🔗 Enlace copiado al portapapeles');
}

// Follow/Unfollow
async function toggleFollow(userId) {
    const formData = new FormData();
    formData.append('action', 'follow_user');
    formData.append('user_id', userId);
    formData.append('follow', !document.getElementById('followBtn').classList.contains('following'));
    
    try {
        const response = await fetch('home.php', {
            method: 'POST',
            body: formData
        });
        const result = await response.json();
        
        if (result.success) {
            const btn = document.getElementById('followBtn');
            const icon = btn.querySelector('i');
            const span = btn.querySelector('span');
            
            if (result.following) {
                btn.classList.add('following');
                icon.className = 'fas fa-user-check';
                span.textContent = 'Siguiendo';
            } else {
                btn.classList.remove('following');
                icon.className = 'fas fa-user-plus';
                span.textContent = 'Seguir';
            }
            
            const followerStat = document.querySelector('.stat-item:last-child .stat-number');
            if (followerStat) {
                followerStat.textContent = result.followers;
            }
        } else {
            alert(result.message);
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Error al seguir/dejar de seguir');
    }
}

// User list modal
async function showUserList(type, userId) {
    const modal = document.getElementById('userListModal');
    const title = document.getElementById('userListTitle');
    const container = document.getElementById('userListContainer');
    
    title.textContent = type === 'following' ? 'Siguiendo' : 'Seguidores';
    container.innerHTML = '<div class="loading">Cargando...</div>';
    modal.style.display = 'flex';
    
    try {
        const response = await fetch(`get_users_list.php?type=${type}&user_id=${userId}`);
        const users = await response.json();
        
        if (users.length === 0) {
            container.innerHTML = '<div style="text-align: center; padding: 20px;">No hay usuarios</div>';
            return;
        }
        
        let html = '';
        for (const user of users) {
            html += `
                <div class="user-list-item" onclick="location.href='home.php?user=${encodeURIComponent(user.username)}'">
                    <img class="user-list-avatar" src="${escapeHtml(user.avatar)}" alt="avatar">
                    <div class="user-list-info">
                        <div class="user-list-name">${escapeHtml(user.name)}</div>
                        <div class="user-list-username">@${escapeHtml(user.username)}</div>
                    </div>
                </div>
            `;
        }
        container.innerHTML = html;
    } catch (error) {
        console.error('Error:', error);
        container.innerHTML = '<div style="text-align: center; padding: 20px;">Error al cargar usuarios</div>';
    }
}

function closeUserListModal() {
    document.getElementById('userListModal').style.display = 'none';
}

// Profile update
function openProfileModal() {
    document.getElementById('profileModal').style.display = 'flex';
}

document.getElementById('profileForm')?.addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    formData.append('action', 'update_profile');
    
    try {
        const response = await fetch('home.php', {
            method: 'POST',
            body: formData
        });
        const result = await response.json();
        
        if (result.success) {
            alert('Perfil actualizado correctamente');
            location.reload();
        } else {
            alert('Error al actualizar perfil');
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Error al actualizar perfil');
    }
});

// Avatar preview in profile modal
const avatarUploadInput = document.getElementById('avatarUpload');
const modalAvatar = document.getElementById('modalAvatar');

if (avatarUploadInput) {
    avatarUploadInput.addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function(ev) {
                modalAvatar.src = ev.target.result;
            };
            reader.readAsDataURL(file);
        }
    });
}

// Post menu dropdown
function toggleMenu(btn) {
    const dropdown = btn.nextElementSibling;
    document.querySelectorAll('.dropdown-menu').forEach(menu => {
        if (menu !== dropdown) menu.classList.remove('show');
    });
    dropdown.classList.toggle('show');
}

document.addEventListener('click', function(e) {
    if (!e.target.closest('.menu-btn')) {
        document.querySelectorAll('.dropdown-menu').forEach(menu => menu.classList.remove('show'));
    }
});

// Modal handling
const profileModal = document.getElementById('profileModal');
const closeProfileModal = document.getElementById('closeProfileModal');
const editPostModal = document.getElementById('editPostModal');
const closeEditModal = document.getElementById('closeEditModal');

if (closeProfileModal) {
    closeProfileModal.addEventListener('click', () => profileModal.style.display = 'none');
}
if (closeEditModal) {
    closeEditModal.addEventListener('click', () => editPostModal.style.display = 'none');
}
window.addEventListener('click', (e) => {
    if (e.target === profileModal) profileModal.style.display = 'none';
    if (e.target === editPostModal) editPostModal.style.display = 'none';
    if (e.target === mobilePostModal) mobilePostModal.style.display = 'none';
});

// Theme toggle with meta theme-color update
function setTheme(theme) {
    const themeColorMeta = document.getElementById('themeColorMeta');
    if (theme === 'light') {
        document.body.classList.add('light');
        document.getElementById('themeIcon').className = 'fas fa-sun';
        localStorage.setItem('theme', 'light');
        if (themeColorMeta) {
            themeColorMeta.setAttribute('content', '#ffffff');
        }
    } else {
        document.body.classList.remove('light');
        document.getElementById('themeIcon').className = 'fas fa-moon';
        localStorage.setItem('theme', 'dark');
        if (themeColorMeta) {
            themeColorMeta.setAttribute('content', '#000000');
        }
    }
}
const savedTheme = localStorage.getItem('theme');
if (savedTheme === 'light') setTheme('light');
else setTheme('dark');
document.getElementById('themeToggleSide').addEventListener('click', () => {
    setTheme(document.body.classList.contains('light') ? 'dark' : 'light');
});

// Auto-resize textarea
const textarea = document.getElementById('postTextarea');
if (textarea) {
    textarea.addEventListener('input', function() {
        this.style.height = 'auto';
        this.style.height = Math.min(this.scrollHeight, 150) + 'px';
    });
}

const mobileTextarea = document.getElementById('mobilePostTextarea');
if (mobileTextarea) {
    mobileTextarea.addEventListener('input', function() {
        this.style.height = 'auto';
        this.style.height = Math.min(this.scrollHeight, 150) + 'px';
    });
}

// Trend click
document.querySelectorAll('.trend-item').forEach(item => {
    item.addEventListener('click', () => {
        const trend = item.dataset.trend;
        const searchInput = document.getElementById('searchInput');
        if (searchInput) {
            searchInput.value = trend;
            searchInput.dispatchEvent(new Event('input'));
        }
    });
});

// Pagination
document.querySelectorAll('.page-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        const page = this.dataset.page;
        if (page) {
            const url = new URL(window.location.href);
            url.searchParams.set('page', page);
            window.location.href = url.toString();
        }
    });
});

// Initial render
renderPosts(allPosts);
</script>
</body>
</html>