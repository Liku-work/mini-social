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

$postId = $_GET['post_id'] ?? '';
if (!$postId) {
    redirect('home.php');
}

$post = getPostById($postId);

if (!$post) {
    redirect('home.php');
}

// Handle comment actions via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    $response = ['success' => false, 'message' => ''];
    
    if ($action === 'add_comment') {
        $text = trim($_POST['text'] ?? '');
        $parentId = $_POST['parent_id'] ?? null;
        
        if (!empty($text)) {
            // Obtener el post actualizado
            $currentPost = getPostById($postId);
            if (!$currentPost) {
                $response['message'] = 'Publicación no encontrada';
                echo json_encode($response);
                exit;
            }
            
            if (!isset($currentPost['comments'])) {
                $currentPost['comments'] = [];
            }
            
            $newComment = [
                'id' => generateId(),
                'author_id' => $currentUser['id'],
                'author_name' => $currentUser['name'],
                'author_username' => $currentUser['username'],
                'author_avatar' => $currentUser['avatar'],
                'text' => $text,
                'created_at' => date('Y-m-d H:i:s'),
                'parent_id' => $parentId
            ];
            
            if ($parentId) {
                // Buscar el comentario padre y agregar como reply
                $found = false;
                foreach ($currentPost['comments'] as &$comment) {
                    if ($comment['id'] === $parentId) {
                        if (!isset($comment['replies'])) {
                            $comment['replies'] = [];
                        }
                        $comment['replies'][] = $newComment;
                        $found = true;
                        break;
                    }
                    // Buscar en replies existentes
                    if (isset($comment['replies'])) {
                        foreach ($comment['replies'] as &$reply) {
                            if ($reply['id'] === $parentId) {
                                if (!isset($reply['replies'])) {
                                    $reply['replies'] = [];
                                }
                                $reply['replies'][] = $newComment;
                                $found = true;
                                break;
                            }
                        }
                    }
                }
                if (!$found) {
                    // Si no se encuentra el padre, agregar como comentario normal
                    $currentPost['comments'][] = $newComment;
                }
            } else {
                $currentPost['comments'][] = $newComment;
            }
            
            // Actualizar el post con los nuevos comentarios
            if (updatePost($postId, ['comments' => $currentPost['comments']])) {
                $response['success'] = true;
                $response['comment'] = $newComment;
            } else {
                $response['message'] = 'Error al guardar el comentario';
            }
        } else {
            $response['message'] = 'El comentario está vacío';
        }
        echo json_encode($response);
        exit;
    }
    
    if ($action === 'delete_comment') {
        $commentId = $_POST['comment_id'] ?? '';
        $currentPost = getPostById($postId);
        
        if ($currentPost && isset($currentPost['comments'])) {
            // Función recursiva para eliminar comentario
            function removeCommentById(&$comments, $commentId, $userId) {
                foreach ($comments as $key => &$comment) {
                    if ($comment['id'] === $commentId && $comment['author_id'] === $userId) {
                        unset($comments[$key]);
                        return true;
                    }
                    if (isset($comment['replies']) && !empty($comment['replies'])) {
                        if (removeCommentById($comment['replies'], $commentId, $userId)) {
                            $comment['replies'] = array_values($comment['replies']);
                            return true;
                        }
                    }
                }
                return false;
            }
            
            $deleted = removeCommentById($currentPost['comments'], $commentId, $currentUser['id']);
            $currentPost['comments'] = array_values($currentPost['comments']);
            
            if ($deleted && updatePost($postId, ['comments' => $currentPost['comments']])) {
                $response['success'] = true;
            } else {
                $response['message'] = 'Error al eliminar el comentario';
            }
        } else {
            $response['message'] = 'Comentario no encontrado';
        }
        echo json_encode($response);
        exit;
    }
    
    echo json_encode($response);
    exit;
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

function renderComment($comment, $postId, $currentUserId, $level = 0) {
    $marginLeft = $level * 40;
    $isAuthor = $comment['author_id'] === $currentUserId;
    
    $html = '<div class="comment" data-comment-id="' . escapeHtml($comment['id']) . '" style="margin-left: ' . $marginLeft . 'px;">';
    $html .= '<div class="comment-header">';
    $html .= '<img class="comment-avatar" src="' . escapeHtml($comment['author_avatar']) . '" alt="avatar">';
    $html .= '<div class="comment-content">';
    $html .= '<div class="comment-author-row">';
    $html .= '<span class="comment-name">' . escapeHtml($comment['author_name']) . '</span>';
    $html .= '<span class="comment-username">@' . escapeHtml($comment['author_username']) . '</span>';
    $html .= '<span class="comment-time">' . formatTimeAgo($comment['created_at']) . '</span>';
    if ($isAuthor) {
        $html .= '<button class="delete-comment-btn" onclick="deleteComment(\'' . escapeHtml($comment['id']) . '\')"><i class="fas fa-trash"></i></button>';
    }
    $html .= '</div>';
    $html .= '<div class="comment-text">' . nl2br(escapeHtml($comment['text'])) . '</div>';
    $html .= '<button class="reply-btn" onclick="showReplyForm(\'' . escapeHtml($comment['id']) . '\')"><i class="fas fa-reply"></i> Responder</button>';
    $html .= '<div class="reply-form" id="reply-form-' . escapeHtml($comment['id']) . '" style="display: none;">';
    $html .= '<textarea class="reply-textarea" id="reply-text-' . escapeHtml($comment['id']) . '" rows="2" placeholder="Escribe tu respuesta..."></textarea>';
    $html .= '<div class="reply-actions">';
    $html .= '<button class="cancel-reply-btn" onclick="hideReplyForm(\'' . escapeHtml($comment['id']) . '\')">Cancelar</button>';
    $html .= '<button class="submit-reply-btn" onclick="submitReply(\'' . escapeHtml($comment['id']) . '\')">Responder</button>';
    $html .= '</div>';
    $html .= '</div>';
    $html .= '</div>';
    $html .= '</div>';
    $html .= '</div>';
    
    // Render replies
    if (isset($comment['replies']) && is_array($comment['replies']) && !empty($comment['replies'])) {
        foreach ($comment['replies'] as $reply) {
            $html .= renderComment($reply, $postId, $currentUserId, $level + 1);
        }
    }
    
    return $html;
}

// Organizar comentarios con sus respuestas
function organizeComments($comments) {
    $organized = [];
    $commentMap = [];
    
    // Primero, crear un mapa de comentarios por ID
    foreach ($comments as $comment) {
        $comment['replies'] = [];
        $commentMap[$comment['id']] = $comment;
    }
    
    // Luego, organizar jerárquicamente
    foreach ($commentMap as $id => $comment) {
        if ($comment['parent_id'] && isset($commentMap[$comment['parent_id']])) {
            $commentMap[$comment['parent_id']]['replies'][] = $comment;
        } else {
            $organized[] = $comment;
        }
    }
    
    return $organized;
}

$comments = $post['comments'] ?? [];
$organizedComments = organizeComments($comments);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Comentarios · LS Community</title>
    <link rel="icon" type="image/x-icon" href="https://ls.dilivel.com/img/lov.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
        :root { --bg-primary: #000000; --bg-secondary: #000000; --bg-card: #000000; --bg-hover: #080808; --border-color: #2f3336; --text-primary: #e7e9ea; --text-secondary: #71767b; --accent: #1d9bf0; }
        body.light { --bg-primary: #ffffff; --bg-secondary: #ffffff; --bg-card: #ffffff; --bg-hover: #f7f7f7; --border-color: #eff3f4; --text-primary: #0f1419; --text-secondary: #536471; --accent: #1d9bf0; }
        body { background-color: var(--bg-primary); color: var(--text-primary); transition: background-color 0.2s; }
        .container { max-width: 700px; margin: 0 auto; padding: 20px; }
        .back-btn { display: inline-block; margin-bottom: 20px; color: var(--accent); text-decoration: none; font-size: 16px; }
        .back-btn i { margin-right: 8px; }
        .original-post { background: transparent; border-radius: 0px; padding: 16px; margin-bottom: 24px; border-bottom: 1px solid var(--border-color); }
        .post-header { display: flex; gap: 12px; }
        .post-avatar { width: 48px; height: 48px; border-radius: 50%; object-fit: cover; }
        .post-name { font-weight: 700; font-size: 15px; }
        .post-username { font-size: 14px; color: var(--text-secondary); }
        .post-time { font-size: 14px; color: var(--text-secondary); }
        .post-time::before { content: "·"; margin-right: 4px; }
        .post-text { font-size: 15px; line-height: 1.5; margin-top: 8px; white-space: pre-wrap; }
        .post-image { margin-top: 12px; border-radius: 16px; max-width: 100%; max-height: 300px; object-fit: cover; cursor: pointer; }
        .comments-section { margin-top: 24px; }
        .comments-title { font-size: 20px; font-weight: 700; margin-bottom: 20px; }
        .new-comment { display: flex; gap: 12px; margin-bottom: 24px; }
        .comment-avatar { width: 40px; height: 40px; border-radius: 50%; object-fit: cover; }
        .comment-input-area { flex: 1; }
        .comment-textarea { width: 100%; background: var(--bg-hover); border: 1px solid var(--border-color); border-radius: 20px; padding: 12px 16px; color: var(--text-primary); font-size: 15px; resize: none; font-family: inherit; outline: none; }
        .comment-textarea:focus { border-color: var(--accent); }
        .submit-comment { background: var(--accent); color: white; border: none; border-radius: 30px; padding: 8px 20px; font-weight: 700; cursor: pointer; margin-top: 8px; }
        .comments-list { margin-top: 16px; }
        .comment { padding: 16px 0; border-bottom: 1px solid var(--border-color); }
        .comment-header { display: flex; gap: 12px; }
        .comment-content { flex: 1; }
        .comment-author-row { display: flex; align-items: baseline; flex-wrap: wrap; gap: 6px; margin-bottom: 4px; }
        .comment-name { font-weight: 700; font-size: 14px; }
        .comment-username { font-size: 12px; color: var(--text-secondary); }
        .comment-time { font-size: 12px; color: var(--text-secondary); }
        .comment-time::before { content: "·"; margin-right: 4px; }
        .comment-text { font-size: 14px; line-height: 1.4; margin: 8px 0; white-space: pre-wrap; }
        .reply-btn, .delete-comment-btn { background: none; border: none; color: var(--text-secondary); font-size: 12px; cursor: pointer; padding: 4px 8px; border-radius: 20px; transition: all 0.2s; }
        .reply-btn:hover { background: var(--bg-hover); color: var(--accent); }
        .delete-comment-btn:hover { background: var(--bg-hover); color: #f4212e; }
        .reply-form { margin-top: 12px; margin-left: 48px; }
        .reply-textarea { width: 100%; background: var(--bg-hover); border: 1px solid var(--border-color); border-radius: 16px; padding: 8px 12px; color: var(--text-primary); font-size: 14px; resize: none; font-family: inherit; outline: none; }
        .reply-actions { display: flex; gap: 8px; margin-top: 8px; justify-content: flex-end; }
        .cancel-reply-btn { background: none; border: none; color: var(--text-secondary); padding: 6px 12px; cursor: pointer; border-radius: 20px; }
        .cancel-reply-btn:hover { background: var(--bg-hover); }
        .submit-reply-btn { background: var(--accent); color: white; border: none; border-radius: 20px; padding: 6px 16px; cursor: pointer; font-weight: 600; }
        .theme-toggle { position: fixed; bottom: 20px; right: 20px; background: var(--accent); border: none; color: white; padding: 10px 16px; border-radius: 30px; cursor: pointer; font-weight: 600; z-index: 100; }
        .loading { text-align: center; padding: 20px; color: var(--text-secondary); }
        @media (max-width: 600px) { .container { padding: 12px; } .reply-form { margin-left: 40px; } }
    </style>
</head>
<body>
<div class="container">
    <a href="home.php" class="back-btn"><i class="fas fa-arrow-left"></i> Volver al inicio</a>
    
    <div class="original-post">
        <div class="post-header">
            <img class="post-avatar" src="<?php echo escapeHtml($post['author_avatar']); ?>" alt="avatar">
            <div>
                <div>
                    <span class="post-name"><?php echo escapeHtml($post['author_name']); ?></span>
                    <span class="post-username">@<?php echo escapeHtml($post['author_username']); ?></span>
                    <span class="post-time"><?php echo formatTimeAgo($post['created_at']); ?></span>
                </div>
                <div class="post-text"><?php echo nl2br(escapeHtml($post['content'])); ?></div>
                <?php if ($post['image']): ?>
                    <img class="post-image" src="<?php echo escapeHtml($post['image']); ?>" alt="post image" onclick="window.open(this.src)">
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <div class="comments-section">
        <h3 class="comments-title"><i class="fas fa-comments"></i> Comentarios</h3>
        
        <div class="new-comment">
            <img class="comment-avatar" src="<?php echo escapeHtml($currentUser['avatar']); ?>" alt="avatar">
            <div class="comment-input-area">
                <textarea id="commentText" class="comment-textarea" rows="2" placeholder="Escribe un comentario..."></textarea>
                <button class="submit-comment" id="submitCommentBtn">Publicar comentario</button>
            </div>
        </div>
        
        <div class="comments-list" id="commentsList">
            <?php if (empty($organizedComments)): ?>
                <div style="text-align: center; padding: 40px; color: var(--text-secondary);">
                    <i class="fas fa-comment-dots" style="font-size: 48px; opacity: 0.5;"></i>
                    <p style="margin-top: 12px;">No hay comentarios aún. ¡Sé el primero en comentar!</p>
                </div>
            <?php else: ?>
                <?php foreach ($organizedComments as $comment): ?>
                    <?php echo renderComment($comment, $postId, $currentUser['id']); ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<button class="theme-toggle" id="themeToggle"><i class="fas fa-adjust"></i> Tema</button>

<script>
const postId = '<?php echo $postId; ?>';
const currentUserId = '<?php echo $currentUser['id']; ?>';

// Add comment with AJAX
document.getElementById('submitCommentBtn')?.addEventListener('click', async function() {
    const text = document.getElementById('commentText').value.trim();
    
    if (!text) {
        alert('Escribe un comentario');
        return;
    }
    
    // Deshabilitar botón para evitar envíos múltiples
    const submitBtn = this;
    submitBtn.disabled = true;
    submitBtn.textContent = 'Enviando...';
    
    const formData = new FormData();
    formData.append('action', 'add_comment');
    formData.append('text', text);
    
    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            body: formData
        });
        const result = await response.json();
        
        if (result.success) {
            // Recargar para mostrar el nuevo comentario
            location.reload();
        } else {
            alert(result.message || 'Error al publicar comentario');
            submitBtn.disabled = false;
            submitBtn.textContent = 'Publicar comentario';
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Error al publicar comentario');
        submitBtn.disabled = false;
        submitBtn.textContent = 'Publicar comentario';
    }
});

// Submit reply
async function submitReply(parentId) {
    const textarea = document.getElementById(`reply-text-${parentId}`);
    const text = textarea?.value.trim();
    
    if (!text) {
        alert('Escribe una respuesta');
        return;
    }
    
    const replyBtn = document.querySelector(`#reply-form-${parentId} .submit-reply-btn`);
    if (replyBtn) {
        replyBtn.disabled = true;
        replyBtn.textContent = 'Enviando...';
    }
    
    const formData = new FormData();
    formData.append('action', 'add_comment');
    formData.append('text', text);
    formData.append('parent_id', parentId);
    
    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            body: formData
        });
        const result = await response.json();
        
        if (result.success) {
            location.reload();
        } else {
            alert(result.message || 'Error al publicar respuesta');
            if (replyBtn) {
                replyBtn.disabled = false;
                replyBtn.textContent = 'Responder';
            }
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Error al publicar respuesta');
        if (replyBtn) {
            replyBtn.disabled = false;
            replyBtn.textContent = 'Responder';
        }
    }
}

// Delete comment
async function deleteComment(commentId) {
    if (!confirm('¿Eliminar este comentario?')) return;
    
    const formData = new FormData();
    formData.append('action', 'delete_comment');
    formData.append('comment_id', commentId);
    
    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            body: formData
        });
        const result = await response.json();
        
        if (result.success) {
            location.reload();
        } else {
            alert(result.message || 'Error al eliminar comentario');
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Error al eliminar comentario');
    }
}

// Show reply form
function showReplyForm(commentId) {
    hideAllReplyForms();
    const form = document.getElementById(`reply-form-${commentId}`);
    if (form) {
        form.style.display = 'block';
        const textarea = document.getElementById(`reply-text-${commentId}`);
        if (textarea) textarea.focus();
    }
}

function hideReplyForm(commentId) {
    const form = document.getElementById(`reply-form-${commentId}`);
    if (form) form.style.display = 'none';
}

function hideAllReplyForms() {
    document.querySelectorAll('.reply-form').forEach(form => {
        form.style.display = 'none';
    });
}

// Theme toggle
function setTheme(theme) {
    if (theme === 'light') {
        document.body.classList.add('light');
        localStorage.setItem('theme', 'light');
    } else {
        document.body.classList.remove('light');
        localStorage.setItem('theme', 'dark');
    }
}
const savedTheme = localStorage.getItem('theme');
if (savedTheme === 'light') setTheme('light');
else setTheme('dark');
document.getElementById('themeToggle').addEventListener('click', () => {
    setTheme(document.body.classList.contains('light') ? 'dark' : 'light');
});

// Auto-resize textarea
const commentTextarea = document.getElementById('commentText');
if (commentTextarea) {
    commentTextarea.addEventListener('input', function() {
        this.style.height = 'auto';
        this.style.height = Math.min(this.scrollHeight, 100) + 'px';
    });
}

// Presionar Enter para enviar comentario (Ctrl+Enter o Cmd+Enter)
if (commentTextarea) {
    commentTextarea.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
            e.preventDefault();
            document.getElementById('submitCommentBtn')?.click();
        }
    });
}
</script>
</body>
</html>