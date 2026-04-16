<?php
// ==================== CONFIGURACIÓN DE ERRORES ====================
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/data/php_errors.log');

if (!file_exists(__DIR__ . '/data/')) {
    mkdir(__DIR__ . '/data/', 0777, true);
}

// ==================== CONFIGURACIÓN DE SESIÓN DE LARGA DURACIÓN (3 AÑOS) ====================
$tresAnios = 3 * 365 * 24 * 60 * 60;

ini_set('session.cookie_lifetime', $tresAnios);
ini_set('session.gc_maxlifetime', $tresAnios);
ini_set('session.cookie_secure', 0);
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.use_strict_mode', 1);

$sessionPath = __DIR__ . '/data/sessions/';
if (!file_exists($sessionPath)) {
    mkdir($sessionPath, 0777, true);
}
ini_set('session.save_path', $sessionPath);

session_start();

if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), $_COOKIE[session_name()], time() + $tresAnios, '/');
}

define('DATA_DIR', __DIR__ . '/data/');
define('USERS_DIR', DATA_DIR . 'users/');
define('USERS_INDEX_FILE', DATA_DIR . 'users_index.json');
define('POSTS_DIR', DATA_DIR . 'posts/');
define('POSTS_INDEX_FILE', DATA_DIR . 'posts_index.json');
define('AVATARS_DIR', DATA_DIR . 'avatars/');
define('FOLLOWS_FILE', DATA_DIR . 'follows.json');
define('NOTES_DIR', DATA_DIR . 'notes/');
define('NOTIFICATIONS_FILE', DATA_DIR . 'notifications.json');
define('USERS_PER_BLOCK', 20);
define('POSTS_PER_BLOCK', 10);
define('NOTES_PER_BLOCK', 20);
define('DEBUG_MODE', true);

if (!file_exists(DATA_DIR)) mkdir(DATA_DIR, 0777, true);
if (!file_exists(USERS_DIR)) mkdir(USERS_DIR, 0777, true);
if (!file_exists(POSTS_DIR)) mkdir(POSTS_DIR, 0777, true);
if (!file_exists(AVATARS_DIR)) mkdir(AVATARS_DIR, 0777, true);
if (!file_exists(NOTES_DIR)) mkdir(NOTES_DIR, 0777, true);
if (!file_exists($sessionPath)) mkdir($sessionPath, 0777, true);

function initFile($file, $defaultData) {
    if (!file_exists($file)) {
        file_put_contents($file, json_encode($defaultData, JSON_PRETTY_PRINT));
        chmod($file, 0666);
    }
}

initFile(USERS_INDEX_FILE, [
    'blocks' => [],
    'total_users' => 0,
    'last_block' => 0,
    'users_per_block' => USERS_PER_BLOCK
]);
initFile(POSTS_INDEX_FILE, [
    'blocks' => [],
    'total_posts' => 0,
    'last_block' => 0,
    'posts_per_block' => POSTS_PER_BLOCK
]);
initFile(FOLLOWS_FILE, []);
initFile(NOTIFICATIONS_FILE, []);

// ==================== FUNCIONES PARA MANEJO DE USUARIOS POR BLOQUES ====================

function getUserBlockFilename($blockIndex) {
    return USERS_DIR . 'user_block_' . str_pad($blockIndex, 7, '0', STR_PAD_LEFT) . '.json';
}

function getUsersIndex() {
    try {
        if (!file_exists(USERS_INDEX_FILE)) {
            return [
                'blocks' => [],
                'total_users' => 0,
                'last_block' => 0,
                'users_per_block' => USERS_PER_BLOCK
            ];
        }
        
        $content = file_get_contents(USERS_INDEX_FILE);
        if ($content === false) {
            return [
                'blocks' => [],
                'total_users' => 0,
                'last_block' => 0,
                'users_per_block' => USERS_PER_BLOCK
            ];
        }
        
        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'blocks' => [],
                'total_users' => 0,
                'last_block' => 0,
                'users_per_block' => USERS_PER_BLOCK
            ];
        }
        
        if (!isset($data['blocks'])) $data['blocks'] = [];
        if (!isset($data['total_users'])) $data['total_users'] = 0;
        if (!isset($data['last_block'])) $data['last_block'] = 0;
        if (!isset($data['users_per_block'])) $data['users_per_block'] = USERS_PER_BLOCK;
        
        return $data;
        
    } catch (Exception $e) {
        error_log("Excepción en getUsersIndex: " . $e->getMessage());
        return [
            'blocks' => [],
            'total_users' => 0,
            'last_block' => 0,
            'users_per_block' => USERS_PER_BLOCK
        ];
    }
}

function saveUsersIndex($index) {
    try {
        $json = json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return false;
        }
        return file_put_contents(USERS_INDEX_FILE, $json) !== false;
    } catch (Exception $e) {
        error_log("Excepción en saveUsersIndex: " . $e->getMessage());
        return false;
    }
}

function getAllUsersFromBlocks() {
    $index = getUsersIndex();
    $allUsers = [];
    
    foreach ($index['blocks'] as $blockIndex => $blockInfo) {
        $blockFile = getUserBlockFilename($blockIndex);
        if (file_exists($blockFile)) {
            $content = file_get_contents($blockFile);
            if ($content !== false) {
                $blockData = json_decode($content, true);
                if (json_last_error() === JSON_ERROR_NONE && isset($blockData['users'])) {
                    foreach ($blockData['users'] as $user) {
                        $allUsers[] = $user;
                    }
                }
            }
        }
    }
    
    return $allUsers;
}

function getUserById($id) {
    try {
        $allUsers = getAllUsersFromBlocks();
        foreach ($allUsers as $user) {
            if ($user['id'] === $id) {
                return $user;
            }
        }
        return null;
    } catch (Exception $e) {
        error_log("Excepción en getUserById: " . $e->getMessage());
        return null;
    }
}

function getUserByUsername($username) {
    try {
        $allUsers = getAllUsersFromBlocks();
        foreach ($allUsers as $user) {
            if ($user['username'] === $username) {
                return $user;
            }
        }
        return null;
    } catch (Exception $e) {
        error_log("Excepción en getUserByUsername: " . $e->getMessage());
        return null;
    }
}

function createUser($userData) {
    try {
        $index = getUsersIndex();
        $newUserId = generateId();
        
        $newUser = [
            'id' => $newUserId,
            'username' => $userData['username'],
            'password' => $userData['password'],
            'name' => $userData['name'],
            'bio' => $userData['bio'] ?? 'Miembro de LS Community',
            'avatar' => $userData['avatar'] ?? "https://api.dicebear.com/7.x/avataaars/svg?seed=" . urlencode($userData['username']),
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        $currentBlock = $index['last_block'];
        $blockFile = getUserBlockFilename($currentBlock);
        
        if (file_exists($blockFile)) {
            $content = file_get_contents($blockFile);
            if ($content !== false) {
                $blockData = json_decode($content, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    if (count($blockData['users']) >= USERS_PER_BLOCK) {
                        $currentBlock = $index['last_block'] + 1;
                        $blockFile = getUserBlockFilename($currentBlock);
                        $blockData = [
                            'block_index' => $currentBlock,
                            'users' => [],
                            'created_at' => date('Y-m-d H:i:s'),
                            'user_count' => 0
                        ];
                        $index['last_block'] = $currentBlock;
                        $index['blocks'][$currentBlock] = [
                            'block_index' => $currentBlock,
                            'user_ids' => [],
                            'user_count' => 0,
                            'created_at' => date('Y-m-d H:i:s')
                        ];
                    }
                }
            }
        } else {
            $blockData = [
                'block_index' => $currentBlock,
                'users' => [],
                'created_at' => date('Y-m-d H:i:s'),
                'user_count' => 0
            ];
            $index['blocks'][$currentBlock] = [
                'block_index' => $currentBlock,
                'user_ids' => [],
                'user_count' => 0,
                'created_at' => date('Y-m-d H:i:s')
            ];
        }
        
        $blockData['users'][] = $newUser;
        $blockData['user_count'] = count($blockData['users']);
        
        $index['blocks'][$currentBlock]['user_ids'][] = $newUserId;
        $index['blocks'][$currentBlock]['user_count'] = $blockData['user_count'];
        $index['total_users']++;
        
        $json = json_encode($blockData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if (file_put_contents($blockFile, $json) === false) {
            return false;
        }
        
        if (!saveUsersIndex($index)) {
            return false;
        }
        
        return $newUserId;
        
    } catch (Exception $e) {
        error_log("Excepción en createUser: " . $e->getMessage());
        return false;
    }
}

function updateUser($userId, $data) {
    try {
        $allUsers = getAllUsersFromBlocks();
        $found = false;
        
        foreach ($allUsers as &$user) {
            if ($user['id'] === $userId) {
                foreach ($data as $key => $value) {
                    if (in_array($key, ['name', 'bio', 'avatar'])) {
                        $user[$key] = $value;
                    }
                }
                $user['updated_at'] = date('Y-m-d H:i:s');
                $found = true;
                break;
            }
        }
        
        if ($found) {
            reorganizeUsersIntoBlocks($allUsers);
            return true;
        }
        
        return false;
        
    } catch (Exception $e) {
        error_log("Excepción en updateUser: " . $e->getMessage());
        return false;
    }
}

function reorganizeUsersIntoBlocks($allUsers) {
    $existingBlocks = glob(USERS_DIR . 'user_block_*.json');
    foreach ($existingBlocks as $blockFile) {
        unlink($blockFile);
    }
    
    $newIndex = [
        'blocks' => [],
        'total_users' => count($allUsers),
        'last_block' => 0,
        'users_per_block' => USERS_PER_BLOCK
    ];
    
    $currentBlock = 0;
    $usersInCurrentBlock = 0;
    $currentBlockUsers = [];
    
    foreach ($allUsers as $user) {
        if ($usersInCurrentBlock >= USERS_PER_BLOCK) {
            saveUserBlockToFile($currentBlock, $currentBlockUsers, $newIndex);
            $currentBlock++;
            $usersInCurrentBlock = 0;
            $currentBlockUsers = [];
        }
        
        $currentBlockUsers[] = $user;
        $usersInCurrentBlock++;
    }
    
    if (count($currentBlockUsers) > 0) {
        saveUserBlockToFile($currentBlock, $currentBlockUsers, $newIndex);
    }
    
    $newIndex['last_block'] = $currentBlock;
    saveUsersIndex($newIndex);
}

function saveUserBlockToFile($blockIndex, $users, &$index) {
    $blockFile = getUserBlockFilename($blockIndex);
    $blockData = [
        'block_index' => $blockIndex,
        'users' => $users,
        'created_at' => date('Y-m-d H:i:s'),
        'user_count' => count($users)
    ];
    
    file_put_contents($blockFile, json_encode($blockData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    
    $userIds = array_column($users, 'id');
    $index['blocks'][$blockIndex] = [
        'block_index' => $blockIndex,
        'user_ids' => $userIds,
        'user_count' => count($users),
        'created_at' => date('Y-m-d H:i:s')
    ];
}

function getUsers($page = 1, $limit = null) {
    try {
        $allUsers = getAllUsersFromBlocks();
        
        if ($limit === null) $limit = 20;
        $startUser = ($page - 1) * $limit;
        $paginatedUsers = array_slice($allUsers, $startUser, $limit);
        
        return [
            'users' => $paginatedUsers,
            'total' => count($allUsers),
            'page' => $page,
            'limit' => $limit,
            'total_pages' => ceil(count($allUsers) / $limit)
        ];
        
    } catch (Exception $e) {
        error_log("Excepción en getUsers: " . $e->getMessage());
        return ['users' => [], 'total' => 0, 'page' => 1, 'limit' => $limit, 'total_pages' => 0];
    }
}

// ==================== FUNCIONES PARA MANEJO DE POSTS POR BLOQUES ====================

function getPostBlockFilename($blockIndex) {
    return POSTS_DIR . 'post_block_' . str_pad($blockIndex, 7, '0', STR_PAD_LEFT) . '.json';
}

function getPostsIndex() {
    try {
        if (!file_exists(POSTS_INDEX_FILE)) {
            return [
                'blocks' => [],
                'total_posts' => 0,
                'last_block' => 0,
                'posts_per_block' => POSTS_PER_BLOCK
            ];
        }
        
        $content = file_get_contents(POSTS_INDEX_FILE);
        if ($content === false) {
            return [
                'blocks' => [],
                'total_posts' => 0,
                'last_block' => 0,
                'posts_per_block' => POSTS_PER_BLOCK
            ];
        }
        
        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'blocks' => [],
                'total_posts' => 0,
                'last_block' => 0,
                'posts_per_block' => POSTS_PER_BLOCK
            ];
        }
        
        if (!isset($data['blocks'])) $data['blocks'] = [];
        if (!isset($data['total_posts'])) $data['total_posts'] = 0;
        if (!isset($data['last_block'])) $data['last_block'] = 0;
        if (!isset($data['posts_per_block'])) $data['posts_per_block'] = POSTS_PER_BLOCK;
        
        return $data;
        
    } catch (Exception $e) {
        error_log("Excepción en getPostsIndex: " . $e->getMessage());
        return [
            'blocks' => [],
            'total_posts' => 0,
            'last_block' => 0,
            'posts_per_block' => POSTS_PER_BLOCK
        ];
    }
}

function savePostsIndex($index) {
    try {
        $json = json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return false;
        }
        return file_put_contents(POSTS_INDEX_FILE, $json) !== false;
    } catch (Exception $e) {
        error_log("Excepción en savePostsIndex: " . $e->getMessage());
        return false;
    }
}

function getAllPostsFromBlocks() {
    $index = getPostsIndex();
    $allPosts = [];
    
    foreach ($index['blocks'] as $blockIndex => $blockInfo) {
        $blockFile = getPostBlockFilename($blockIndex);
        if (file_exists($blockFile)) {
            $content = file_get_contents($blockFile);
            if ($content !== false) {
                $blockData = json_decode($content, true);
                if (json_last_error() === JSON_ERROR_NONE && isset($blockData['posts'])) {
                    foreach ($blockData['posts'] as $post) {
                        if (!isset($post['author_name']) && isset($post['user_id'])) {
                            $author = getUserById($post['user_id']);
                            if ($author) {
                                $post['author_name'] = $author['name'];
                                $post['author_username'] = $author['username'];
                                $post['author_avatar'] = $author['avatar'];
                            } else {
                                $post['author_name'] = 'Usuario';
                                $post['author_username'] = 'usuario';
                                $post['author_avatar'] = 'data/avatars/default.png';
                            }
                        }
                        if (!isset($post['comments'])) $post['comments'] = [];
                        if (!isset($post['likes'])) $post['likes'] = [];
                        if (!isset($post['retweets'])) $post['retweets'] = 0;
                        if (!isset($post['content']) && isset($post['text'])) $post['content'] = $post['text'];
                        
                        $allPosts[] = $post;
                    }
                }
            }
        }
    }
    
    usort($allPosts, function($a, $b) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });
    
    return $allPosts;
}

function getPosts($page = 1, $limit = null, $filter = null, $userId = null) {
    try {
        $allPosts = getAllPostsFromBlocks();
        
        if ($filter === 'following' && $userId) {
            $following = getFollowing($userId);
            $followingIds = array_column($following, 'id');
            $allPosts = array_filter($allPosts, function($post) use ($followingIds, $userId) {
                return in_array($post['user_id'], $followingIds) || $post['user_id'] === $userId;
            });
            $allPosts = array_values($allPosts);
        } elseif ($filter === 'profile' && $userId) {
            $allPosts = array_filter($allPosts, function($post) use ($userId) {
                return $post['user_id'] === $userId;
            });
            $allPosts = array_values($allPosts);
        } elseif ($filter === 'random') {
            shuffle($allPosts);
        }
        
        if ($limit === null) $limit = 20;
        $startPost = ($page - 1) * $limit;
        $paginatedPosts = array_slice($allPosts, $startPost, $limit);
        
        return [
            'posts' => $paginatedPosts,
            'total' => count($allPosts),
            'page' => $page,
            'limit' => $limit,
            'total_pages' => ceil(count($allPosts) / $limit)
        ];
        
    } catch (Exception $e) {
        error_log("Excepción en getPosts: " . $e->getMessage());
        return ['posts' => [], 'total' => 0, 'page' => 1, 'limit' => $limit, 'total_pages' => 0];
    }
}

function getPostById($postId) {
    try {
        $allPosts = getAllPostsFromBlocks();
        foreach ($allPosts as $post) {
            if ($post['id'] === $postId) {
                return $post;
            }
        }
        return null;
    } catch (Exception $e) {
        error_log("Excepción en getPostById: " . $e->getMessage());
        return null;
    }
}

function createPost($postData) {
    try {
        $index = getPostsIndex();
        $newPostId = generateId();
        
        $author = getUserById($postData['user_id']);
        if (!$author) {
            error_log("Autor no encontrado para crear post");
            return false;
        }
        
        $post = [
            'id' => $newPostId,
            'user_id' => $postData['user_id'],
            'author_name' => $author['name'],
            'author_username' => $author['username'],
            'author_avatar' => $author['avatar'],
            'content' => $postData['content'],
            'image' => $postData['image'] ?? null,
            'created_at' => date('Y-m-d H:i:s'),
            'comments' => [],
            'likes' => [],
            'retweets' => 0
        ];
        
        $currentBlock = $index['last_block'];
        $blockFile = getPostBlockFilename($currentBlock);
        
        if (file_exists($blockFile)) {
            $content = file_get_contents($blockFile);
            if ($content !== false) {
                $blockData = json_decode($content, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    if (count($blockData['posts']) >= POSTS_PER_BLOCK) {
                        $currentBlock = $index['last_block'] + 1;
                        $blockFile = getPostBlockFilename($currentBlock);
                        $blockData = [
                            'block_index' => $currentBlock,
                            'posts' => [],
                            'created_at' => date('Y-m-d H:i:s'),
                            'post_count' => 0
                        ];
                        $index['last_block'] = $currentBlock;
                        $index['blocks'][$currentBlock] = [
                            'block_index' => $currentBlock,
                            'post_ids' => [],
                            'post_count' => 0,
                            'created_at' => date('Y-m-d H:i:s')
                        ];
                    }
                }
            }
        } else {
            $blockData = [
                'block_index' => $currentBlock,
                'posts' => [],
                'created_at' => date('Y-m-d H:i:s'),
                'post_count' => 0
            ];
            $index['blocks'][$currentBlock] = [
                'block_index' => $currentBlock,
                'post_ids' => [],
                'post_count' => 0,
                'created_at' => date('Y-m-d H:i:s')
            ];
        }
        
        $blockData['posts'][] = $post;
        $blockData['post_count'] = count($blockData['posts']);
        
        $index['blocks'][$currentBlock]['post_ids'][] = $newPostId;
        $index['blocks'][$currentBlock]['post_count'] = $blockData['post_count'];
        $index['total_posts']++;
        
        $json = json_encode($blockData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if (file_put_contents($blockFile, $json) === false) {
            return false;
        }
        
        if (!savePostsIndex($index)) {
            return false;
        }
        
        return $newPostId;
        
    } catch (Exception $e) {
        error_log("Excepción en createPost: " . $e->getMessage());
        return false;
    }
}

function updatePost($postId, $updatedData) {
    try {
        $allPosts = getAllPostsFromBlocks();
        $found = false;
        
        foreach ($allPosts as &$post) {
            if ($post['id'] === $postId) {
                foreach ($updatedData as $key => $value) {
                    if (in_array($key, ['content', 'image', 'likes', 'retweets', 'comments'])) {
                        $post[$key] = $value;
                    }
                }
                $post['updated_at'] = date('Y-m-d H:i:s');
                $found = true;
                break;
            }
        }
        
        if ($found) {
            reorganizePostsIntoBlocks($allPosts);
            return true;
        }
        
        return false;
        
    } catch (Exception $e) {
        error_log("Excepción en updatePost: " . $e->getMessage());
        return false;
    }
}

function deletePost($postId) {
    try {
        $allPosts = getAllPostsFromBlocks();
        $newPosts = array_filter($allPosts, function($post) use ($postId) {
            return $post['id'] !== $postId;
        });
        
        if (count($newPosts) !== count($allPosts)) {
            reorganizePostsIntoBlocks(array_values($newPosts));
            return true;
        }
        
        return false;
        
    } catch (Exception $e) {
        error_log("Excepción en deletePost: " . $e->getMessage());
        return false;
    }
}

function reorganizePostsIntoBlocks($allPosts) {
    $existingBlocks = glob(POSTS_DIR . 'post_block_*.json');
    foreach ($existingBlocks as $blockFile) {
        unlink($blockFile);
    }
    
    $newIndex = [
        'blocks' => [],
        'total_posts' => count($allPosts),
        'last_block' => 0,
        'posts_per_block' => POSTS_PER_BLOCK
    ];
    
    usort($allPosts, function($a, $b) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });
    
    $currentBlock = 0;
    $postsInCurrentBlock = 0;
    $currentBlockPosts = [];
    
    foreach ($allPosts as $post) {
        if ($postsInCurrentBlock >= POSTS_PER_BLOCK) {
            savePostBlockToFile($currentBlock, $currentBlockPosts, $newIndex);
            $currentBlock++;
            $postsInCurrentBlock = 0;
            $currentBlockPosts = [];
        }
        
        $currentBlockPosts[] = $post;
        $postsInCurrentBlock++;
    }
    
    if (count($currentBlockPosts) > 0) {
        savePostBlockToFile($currentBlock, $currentBlockPosts, $newIndex);
    }
    
    $newIndex['last_block'] = $currentBlock;
    savePostsIndex($newIndex);
}

function savePostBlockToFile($blockIndex, $posts, &$index) {
    $blockFile = getPostBlockFilename($blockIndex);
    $blockData = [
        'block_index' => $blockIndex,
        'posts' => $posts,
        'created_at' => date('Y-m-d H:i:s'),
        'post_count' => count($posts)
    ];
    
    file_put_contents($blockFile, json_encode($blockData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    
    $postIds = array_column($posts, 'id');
    $index['blocks'][$blockIndex] = [
        'block_index' => $blockIndex,
        'post_ids' => $postIds,
        'post_count' => count($posts),
        'created_at' => date('Y-m-d H:i:s')
    ];
}

// ==================== FUNCIONES DE SEGUIDORES ====================

function getFollows() {
    try {
        if (!file_exists(FOLLOWS_FILE)) return [];
        $content = file_get_contents(FOLLOWS_FILE);
        if ($content === false) return [];
        $data = json_decode($content, true);
        return json_last_error() === JSON_ERROR_NONE ? ($data ?: []) : [];
    } catch (Exception $e) {
        return [];
    }
}

function saveFollows($follows) {
    try {
        return file_put_contents(FOLLOWS_FILE, json_encode($follows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;
    } catch (Exception $e) {
        return false;
    }
}

function followUser($followerId, $followingId) {
    if ($followerId === $followingId) return false;
    
    $follows = getFollows();
    
    if (!isset($follows[$followerId])) {
        $follows[$followerId] = ['following' => [], 'followers' => []];
    }
    
    if (!in_array($followingId, $follows[$followerId]['following'])) {
        $follows[$followerId]['following'][] = $followingId;
        
        if (!isset($follows[$followingId])) {
            $follows[$followingId] = ['following' => [], 'followers' => []];
        }
        
        if (!in_array($followerId, $follows[$followingId]['followers'])) {
            $follows[$followingId]['followers'][] = $followerId;
        }
        
        $follower = getUserById($followerId);
        createNotification($followingId, 'follow', $followerId, null, "{$follower['name']} te ha seguido");
        
        return saveFollows($follows);
    }
    
    return false;
}

function unfollowUser($followerId, $followingId) {
    $follows = getFollows();
    
    if (isset($follows[$followerId])) {
        $key = array_search($followingId, $follows[$followerId]['following']);
        if ($key !== false) {
            array_splice($follows[$followerId]['following'], $key, 1);
            
            if (isset($follows[$followingId])) {
                $key2 = array_search($followerId, $follows[$followingId]['followers']);
                if ($key2 !== false) {
                    array_splice($follows[$followingId]['followers'], $key2, 1);
                }
            }
            
            return saveFollows($follows);
        }
    }
    
    return false;
}

function isFollowing($followerId, $followingId) {
    $follows = getFollows();
    
    if (isset($follows[$followerId]) && isset($follows[$followerId]['following'])) {
        return in_array($followingId, $follows[$followerId]['following']);
    }
    
    return false;
}

function getFollowing($userId) {
    $follows = getFollows();
    $following = [];
    
    if (isset($follows[$userId]) && isset($follows[$userId]['following'])) {
        foreach ($follows[$userId]['following'] as $followingId) {
            $user = getUserById($followingId);
            if ($user) {
                $following[] = $user;
            }
        }
    }
    
    return $following;
}

function getFollowers($userId) {
    $follows = getFollows();
    $followers = [];
    
    if (isset($follows[$userId]) && isset($follows[$userId]['followers'])) {
        foreach ($follows[$userId]['followers'] as $followerId) {
            $user = getUserById($followerId);
            if ($user) {
                $followers[] = $user;
            }
        }
    }
    
    return $followers;
}

function getFollowStats($userId) {
    return [
        'following' => count(getFollowing($userId)),
        'followers' => count(getFollowers($userId))
    ];
}

// ==================== FUNCIONES PARA NOTAS ====================

function getNoteBlockFilename($blockIndex) {
    return NOTES_DIR . 'note_block_' . str_pad($blockIndex, 7, '0', STR_PAD_LEFT) . '.json';
}

function getAllNotesFromBlocks() {
    $allNotes = [];
    $noteFiles = glob(NOTES_DIR . 'note_block_*.json');
    
    foreach ($noteFiles as $file) {
        $content = file_get_contents($file);
        if ($content !== false) {
            $blockData = json_decode($content, true);
            if (json_last_error() === JSON_ERROR_NONE && isset($blockData['notes'])) {
                foreach ($blockData['notes'] as $note) {
                    $allNotes[] = $note;
                }
            }
        }
    }
    
    usort($allNotes, function($a, $b) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });
    
    return $allNotes;
}

function getNotesForUser($userId) {
    $allNotes = getAllNotesFromBlocks();
    $visibleNotes = [];
    
    foreach ($allNotes as $note) {
        if ($note['user_id'] === $userId) {
            $visibleNotes[] = $note;
        } elseif ($note['visibility'] === 'public') {
            $visibleNotes[] = $note;
        } elseif ($note['visibility'] === 'friends') {
            $isFriend = isFollowing($userId, $note['user_id']) || isFollowing($note['user_id'], $userId);
            if ($isFriend) {
                $visibleNotes[] = $note;
            }
        }
    }
    
    return $visibleNotes;
}

function getMyNotes($userId) {
    $allNotes = getAllNotesFromBlocks();
    $myNotes = [];
    
    foreach ($allNotes as $note) {
        if ($note['user_id'] === $userId) {
            $myNotes[] = $note;
        }
    }
    
    return $myNotes;
}

function createNote($noteData) {
    try {
        $newNoteId = generateId();
        
        $note = [
            'id' => $newNoteId,
            'user_id' => $noteData['user_id'],
            'author_name' => $noteData['author_name'],
            'author_username' => $noteData['author_username'],
            'author_avatar' => $noteData['author_avatar'],
            'content' => $noteData['content'],
            'visibility' => $noteData['visibility'],
            'created_at' => date('Y-m-d H:i:s'),
            'likes' => [],
            'comments' => []
        ];
        
        $blockIndex = floor(count(getAllNotesFromBlocks()) / NOTES_PER_BLOCK);
        $blockFile = getNoteBlockFilename($blockIndex);
        
        if (file_exists($blockFile)) {
            $content = file_get_contents($blockFile);
            if ($content !== false) {
                $blockData = json_decode($content, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    if (count($blockData['notes']) >= NOTES_PER_BLOCK) {
                        $blockIndex++;
                        $blockFile = getNoteBlockFilename($blockIndex);
                        $blockData = [
                            'block_index' => $blockIndex,
                            'notes' => [],
                            'created_at' => date('Y-m-d H:i:s'),
                            'note_count' => 0
                        ];
                    }
                } else {
                    $blockData = [
                        'block_index' => $blockIndex,
                        'notes' => [],
                        'created_at' => date('Y-m-d H:i:s'),
                        'note_count' => 0
                    ];
                }
            } else {
                $blockData = [
                    'block_index' => $blockIndex,
                    'notes' => [],
                    'created_at' => date('Y-m-d H:i:s'),
                    'note_count' => 0
                ];
            }
        } else {
            $blockData = [
                'block_index' => $blockIndex,
                'notes' => [],
                'created_at' => date('Y-m-d H:i:s'),
                'note_count' => 0
            ];
        }
        
        $blockData['notes'][] = $note;
        $blockData['note_count'] = count($blockData['notes']);
        
        $json = json_encode($blockData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if (file_put_contents($blockFile, $json) === false) {
            return false;
        }
        
        return $newNoteId;
        
    } catch (Exception $e) {
        error_log("Excepción en createNote: " . $e->getMessage());
        return false;
    }
}

function deleteNote($noteId, $userId) {
    try {
        $allNotes = getAllNotesFromBlocks();
        $noteToDelete = null;
        
        foreach ($allNotes as $note) {
            if ($note['id'] === $noteId && $note['user_id'] === $userId) {
                $noteToDelete = $note;
                break;
            }
        }
        
        if ($noteToDelete) {
            $newNotes = array_filter($allNotes, function($note) use ($noteId) {
                return $note['id'] !== $noteId;
            });
            
            $existingBlocks = glob(NOTES_DIR . 'note_block_*.json');
            foreach ($existingBlocks as $blockFile) {
                unlink($blockFile);
            }
            
            $newNotesArray = array_values($newNotes);
            $blockIndex = 0;
            $notesInBlock = 0;
            $currentBlockNotes = [];
            
            foreach ($newNotesArray as $note) {
                if ($notesInBlock >= NOTES_PER_BLOCK) {
                    $blockFile = getNoteBlockFilename($blockIndex);
                    $blockData = [
                        'block_index' => $blockIndex,
                        'notes' => $currentBlockNotes,
                        'created_at' => date('Y-m-d H:i:s'),
                        'note_count' => count($currentBlockNotes)
                    ];
                    file_put_contents($blockFile, json_encode($blockData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                    $blockIndex++;
                    $notesInBlock = 0;
                    $currentBlockNotes = [];
                }
                $currentBlockNotes[] = $note;
                $notesInBlock++;
            }
            
            if (count($currentBlockNotes) > 0) {
                $blockFile = getNoteBlockFilename($blockIndex);
                $blockData = [
                    'block_index' => $blockIndex,
                    'notes' => $currentBlockNotes,
                    'created_at' => date('Y-m-d H:i:s'),
                    'note_count' => count($currentBlockNotes)
                ];
                file_put_contents($blockFile, json_encode($blockData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            }
            
            return true;
        }
        
        return false;
        
    } catch (Exception $e) {
        error_log("Excepción en deleteNote: " . $e->getMessage());
        return false;
    }
}

function likeNote($noteId, $userId, $userName, $userAvatar) {
    try {
        $allNotes = getAllNotesFromBlocks();
        
        foreach ($allNotes as &$note) {
            if ($note['id'] === $noteId) {
                $found = false;
                foreach ($note['likes'] as $like) {
                    if ($like['user_id'] === $userId) {
                        $found = true;
                        break;
                    }
                }
                
                if (!$found) {
                    $note['likes'][] = [
                        'user_id' => $userId,
                        'user_name' => $userName,
                        'user_avatar' => $userAvatar,
                        'created_at' => date('Y-m-d H:i:s')
                    ];
                    
                    if ($note['user_id'] !== $userId) {
                        $noteAuthor = getUserById($note['user_id']);
                        if ($noteAuthor) {
                            createNotification($note['user_id'], 'like_note', $userId, $noteId, "{$userName} le gustó tu nota");
                        }
                    }
                    
                    $existingBlocks = glob(NOTES_DIR . 'note_block_*.json');
                    foreach ($existingBlocks as $blockFile) {
                        unlink($blockFile);
                    }
                    
                    $blockIndex = 0;
                    $notesInBlock = 0;
                    $currentBlockNotes = [];
                    
                    foreach ($allNotes as $savedNote) {
                        if ($notesInBlock >= NOTES_PER_BLOCK) {
                            $blockFile = getNoteBlockFilename($blockIndex);
                            $blockData = [
                                'block_index' => $blockIndex,
                                'notes' => $currentBlockNotes,
                                'created_at' => date('Y-m-d H:i:s'),
                                'note_count' => count($currentBlockNotes)
                            ];
                            file_put_contents($blockFile, json_encode($blockData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                            $blockIndex++;
                            $notesInBlock = 0;
                            $currentBlockNotes = [];
                        }
                        $currentBlockNotes[] = $savedNote;
                        $notesInBlock++;
                    }
                    
                    if (count($currentBlockNotes) > 0) {
                        $blockFile = getNoteBlockFilename($blockIndex);
                        $blockData = [
                            'block_index' => $blockIndex,
                            'notes' => $currentBlockNotes,
                            'created_at' => date('Y-m-d H:i:s'),
                            'note_count' => count($currentBlockNotes)
                        ];
                        file_put_contents($blockFile, json_encode($blockData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                    }
                    
                    return true;
                }
                break;
            }
        }
        
        return false;
        
    } catch (Exception $e) {
        error_log("Excepción en likeNote: " . $e->getMessage());
        return false;
    }
}

function unlikeNote($noteId, $userId) {
    try {
        $allNotes = getAllNotesFromBlocks();
        
        foreach ($allNotes as &$note) {
            if ($note['id'] === $noteId) {
                $note['likes'] = array_filter($note['likes'], function($like) use ($userId) {
                    return $like['user_id'] !== $userId;
                });
                $note['likes'] = array_values($note['likes']);
                
                $existingBlocks = glob(NOTES_DIR . 'note_block_*.json');
                foreach ($existingBlocks as $blockFile) {
                    unlink($blockFile);
                }
                
                $blockIndex = 0;
                $notesInBlock = 0;
                $currentBlockNotes = [];
                
                foreach ($allNotes as $savedNote) {
                    if ($notesInBlock >= NOTES_PER_BLOCK) {
                        $blockFile = getNoteBlockFilename($blockIndex);
                        $blockData = [
                            'block_index' => $blockIndex,
                            'notes' => $currentBlockNotes,
                            'created_at' => date('Y-m-d H:i:s'),
                            'note_count' => count($currentBlockNotes)
                        ];
                        file_put_contents($blockFile, json_encode($blockData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                        $blockIndex++;
                        $notesInBlock = 0;
                        $currentBlockNotes = [];
                    }
                    $currentBlockNotes[] = $savedNote;
                    $notesInBlock++;
                }
                
                if (count($currentBlockNotes) > 0) {
                    $blockFile = getNoteBlockFilename($blockIndex);
                    $blockData = [
                        'block_index' => $blockIndex,
                        'notes' => $currentBlockNotes,
                        'created_at' => date('Y-m-d H:i:s'),
                        'note_count' => count($currentBlockNotes)
                    ];
                    file_put_contents($blockFile, json_encode($blockData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                }
                
                return true;
            }
        }
        
        return false;
        
    } catch (Exception $e) {
        error_log("Excepción en unlikeNote: " . $e->getMessage());
        return false;
    }
}

// ==================== FUNCIONES DE NOTIFICACIONES ====================

function createNotification($userId, $type, $fromUserId, $referenceId = null, $message = '') {
    try {
        $notifications = getNotifications();
        
        $fromUser = getUserById($fromUserId);
        $fromUserName = $fromUser ? $fromUser['name'] : 'Usuario';
        $fromUserAvatar = $fromUser ? $fromUser['avatar'] : '';
        
        $notification = [
            'id' => generateId(),
            'user_id' => $userId,
            'type' => $type,
            'from_user_id' => $fromUserId,
            'from_user_name' => $fromUserName,
            'from_user_avatar' => $fromUserAvatar,
            'reference_id' => $referenceId,
            'message' => $message,
            'read' => false,
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        $notifications[] = $notification;
        
        $userNotifications = array_filter($notifications, function($n) use ($userId) {
            return $n['user_id'] === $userId;
        });
        
        if (count($userNotifications) > 500) {
            $notifications = array_filter($notifications, function($n) use ($userId, $userNotifications) {
                if ($n['user_id'] === $userId) {
                    usort($userNotifications, function($a, $b) {
                        return strtotime($b['created_at']) - strtotime($a['created_at']);
                    });
                    $keepIds = array_slice(array_column($userNotifications, 'id'), 0, 500);
                    return in_array($n['id'], $keepIds);
                }
                return true;
            });
            $notifications = array_values($notifications);
        }
        
        return saveNotifications($notifications);
        
    } catch (Exception $e) {
        error_log("Excepción en createNotification: " . $e->getMessage());
        return false;
    }
}

function getNotifications($userId = null, $limit = 1000) {
    try {
        if (!file_exists(NOTIFICATIONS_FILE)) return [];
        $content = file_get_contents(NOTIFICATIONS_FILE);
        if ($content === false) return [];
        $notifications = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) return [];
        
        if ($userId) {
            $notifications = array_filter($notifications, function($n) use ($userId) {
                return $n['user_id'] === $userId;
            });
        }
        
        usort($notifications, function($a, $b) {
            return strtotime($b['created_at']) - strtotime($a['created_at']);
        });
        
        return array_slice($notifications, 0, $limit);
        
    } catch (Exception $e) {
        error_log("Excepción en getNotifications: " . $e->getMessage());
        return [];
    }
}

function getUnreadNotificationsCount($userId) {
    $notifications = getNotifications($userId, 1000);
    $unread = array_filter($notifications, function($n) {
        return !$n['read'];
    });
    return count($unread);
}

function markNotificationAsRead($notificationId, $userId) {
    try {
        $notifications = getNotifications();
        $updated = false;
        
        foreach ($notifications as &$n) {
            if ($n['id'] === $notificationId && $n['user_id'] === $userId) {
                $n['read'] = true;
                $updated = true;
                break;
            }
        }
        
        if ($updated) {
            return saveNotifications($notifications);
        }
        return false;
        
    } catch (Exception $e) {
        error_log("Excepción en markNotificationAsRead: " . $e->getMessage());
        return false;
    }
}

function markAllNotificationsAsRead($userId) {
    try {
        $notifications = getNotifications();
        $updated = false;
        
        foreach ($notifications as &$n) {
            if ($n['user_id'] === $userId && !$n['read']) {
                $n['read'] = true;
                $updated = true;
            }
        }
        
        if ($updated) {
            return saveNotifications($notifications);
        }
        return false;
        
    } catch (Exception $e) {
        error_log("Excepción en markAllNotificationsAsRead: " . $e->getMessage());
        return false;
    }
}

function deleteNotification($notificationId, $userId) {
    try {
        $notifications = getNotifications();
        $newNotifications = array_filter($notifications, function($n) use ($notificationId, $userId) {
            return !($n['id'] === $notificationId && $n['user_id'] === $userId);
        });
        
        if (count($newNotifications) !== count($notifications)) {
            return saveNotifications(array_values($newNotifications));
        }
        return false;
        
    } catch (Exception $e) {
        error_log("Excepción en deleteNotification: " . $e->getMessage());
        return false;
    }
}

function saveNotifications($notifications) {
    try {
        return file_put_contents(NOTIFICATIONS_FILE, json_encode($notifications, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;
    } catch (Exception $e) {
        error_log("Excepción en saveNotifications: " . $e->getMessage());
        return false;
    }
}

// ==================== FUNCIONES AUXILIARES ====================

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function redirect($url) {
    header("Location: $url");
    exit;
}

function generateId() {
    return uniqid() . '_' . bin2hex(random_bytes(4));
}

function saveAvatar($file, $userId) {
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $maxSize = 2 * 1024 * 1024;
    
    if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) return false;
    if (!in_array($file['type'], $allowedTypes)) return false;
    if ($file['size'] > $maxSize) return false;
    
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = $userId . '_' . time() . '.' . $extension;
    $targetPath = AVATARS_DIR . $filename;
    
    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        return 'data/avatars/' . $filename;
    }
    
    return false;
}

function getBlockStats() {
    $userIndex = getUsersIndex();
    $postIndex = getPostsIndex();
    $stats = [
        'users' => [
            'total_blocks' => count($userIndex['blocks']),
            'total_users' => $userIndex['total_users'],
            'users_per_block' => USERS_PER_BLOCK
        ],
        'posts' => [
            'total_blocks' => count($postIndex['blocks']),
            'total_posts' => $postIndex['total_posts'],
            'posts_per_block' => POSTS_PER_BLOCK
        ]
    ];
    return $stats;
}

// ==================== DATOS INICIALES DE DEMO ====================

$allUsers = getAllUsersFromBlocks();
if (empty($allUsers)) {
    $defaultAvatar = 'data/avatars/default.png';
    if (!file_exists(AVATARS_DIR . 'default.png')) {
        $img = imagecreate(100, 100);
        $bg = imagecolorallocate($img, 29, 155, 240);
        $textColor = imagecolorallocate($img, 255, 255, 255);
        imagefill($img, 0, 0, $bg);
        imagestring($img, 5, 35, 45, 'LS', $textColor);
        imagepng($img, AVATARS_DIR . 'default.png');
        imagedestroy($img);
    }
    
    createUser([
        'username' => 'demo',
        'password' => password_hash('demo123', PASSWORD_DEFAULT),
        'name' => 'Usuario Demo',
        'bio' => 'Bienvenido a LS Community',
        'avatar' => $defaultAvatar
    ]);
}

$allPosts = getAllPostsFromBlocks();
if (empty($allPosts)) {
    $demoUser = getUserByUsername('demo');
    if ($demoUser) {
        createPost([
            'user_id' => $demoUser['id'],
            'content' => '¡Bienvenido a LS Community! 🚀 Este es un ejemplo de publicación. Puedes crear tu propio contenido, seguir usuarios y explorar.',
            'image' => null
        ]);
        
        createPost([
            'user_id' => $demoUser['id'],
            'content' => 'Prueba nuestro sistema de seguimiento en tiempo real. ¡Sigue a otros usuarios para ver sus publicaciones en tu feed de inicio!',
            'image' => null
        ]);
    }
}

// ==================== FUNCIONES DE RECORDAR SESIÓN ====================

function setRememberMe($userId, $remember = true) {
    if ($remember) {
        $token = bin2hex(random_bytes(32));
        $expiry = time() + (3 * 365 * 24 * 60 * 60);
        
        setcookie('remember_token', $token, $expiry, '/', '', false, true);
        
        $rememberFile = DATA_DIR . 'remember_tokens.json';
        $tokens = file_exists($rememberFile) ? json_decode(file_get_contents($rememberFile), true) : [];
        $tokens[$token] = [
            'user_id' => $userId,
            'expiry' => $expiry
        ];
        file_put_contents($rememberFile, json_encode($tokens, JSON_PRETTY_PRINT));
    } else {
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
    }
}

function checkRememberToken() {
    if (isset($_COOKIE['remember_token'])) {
        $token = $_COOKIE['remember_token'];
        $rememberFile = DATA_DIR . 'remember_tokens.json';
        
        if (file_exists($rememberFile)) {
            $tokens = json_decode(file_get_contents($rememberFile), true);
            if (isset($tokens[$token]) && $tokens[$token]['expiry'] > time()) {
                $user = getUserById($tokens[$token]['user_id']);
                if ($user) {
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['name'] = $user['name'];
                    $_SESSION['avatar'] = $user['avatar'];
                    $_SESSION['bio'] = $user['bio'] ?? 'Miembro de LS Community';
                    return true;
                }
            }
        }
    }
    return false;
}

if (!isLoggedIn()) {
    checkRememberToken();
}
?>