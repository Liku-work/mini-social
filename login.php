<?php
require_once 'config.php';

if (isLoggedIn()) {
    redirect('home.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']) && $_POST['remember'] === 'on';
    
    if (empty($username) || empty($password)) {
        $error = 'Por favor ingresa usuario y contraseña';
    } else {
        $user = getUserByUsername($username);
        
        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['name'] = $user['name'];
            $_SESSION['avatar'] = $user['avatar'];
            $_SESSION['bio'] = $user['bio'] ?? 'Miembro de LS Community';
            
            // Configurar "Recordarme" por 3 años si está marcado
            if ($remember) {
                setRememberMe($user['id'], true);
            }
            
            // Renovar la cookie de sesión
            $tresAnios = 3 * 365 * 24 * 60 * 60;
            setcookie(session_name(), session_id(), time() + $tresAnios, '/');
            
            redirect('home.php');
        } else {
            $error = 'Usuario o contraseña incorrectos';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar sesión · LS Community</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: #000;
            color: #e7e9ea;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        body.light {
            background: #fff;
            color: #0f1419;
        }
        .auth-container {
            max-width: 480px;
            width: 90%;
            padding: 40px 32px;
            background: #000;
            border: 0px solid #2f3336;
            border-radius: 32px;
        }
        body.light .auth-container {
            background: #fff;
            border-color: #eff3f4;
        }
        .logo {
            text-align: center;
            margin-bottom: 32px;
        }
        .logo i {
            font-size: 48px;
            color: #1d9bf0;
        }
        h1 {
            font-size: 28px;
            margin-bottom: 24px;
            text-align: center;
        }
        .input-group {
            margin-bottom: 20px;
        }
        input {
            width: 100%;
            padding: 16px;
            background: #000;
            border: 1px solid #2f3336;
            border-radius: 8px;
            color: #e7e9ea;
            font-size: 16px;
        }
        body.light input {
            background: #fff;
            border-color: #cfd9de;
            color: #0f1419;
        }
        input:focus {
            outline: none;
            border-color: #1d9bf0;
        }
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 20px 0;
            cursor: pointer;
        }
        .checkbox-group input {
            width: auto;
            margin: 0;
            padding: 0;
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
        .checkbox-group label {
            cursor: pointer;
            color: var(--text-secondary);
            font-size: 14px;
        }
        .btn-primary {
            width: 100%;
            padding: 14px;
            background: #1d9bf0;
            color: white;
            border: none;
            border-radius: 40px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            margin-top: 12px;
        }
        .btn-primary:hover {
            background: #1a8cd8;
        }
        .error {
            background: rgba(244, 33, 46, 0.1);
            border: 1px solid #f4212e;
            color: #f4212e;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            text-align: center;
        }
        .link {
            text-align: center;
            margin-top: 24px;
            color: #71767b;
        }
        .link a {
            color: #1d9bf0;
            text-decoration: none;
        }
        .theme-toggle {
            position: fixed;
            top: 20px;
            right: 20px;
            background: #1d9bf0;
            border: none;
            color: white;
            padding: 10px 16px;
            border-radius: 30px;
            cursor: pointer;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <button style="display: none;" class="theme-toggle" id="themeToggle"><i class="fas fa-adjust"></i> Tema</button>
    <div class="auth-container">
        <div class="logo">
            <img style="border-radius: 0px; height: 35px; width: auto; border: none; padding: 6px; background: red; border-radius: 5px;" src="https://ls.dilivel.com/img/logico.png" alt="Fresh smoothie">
        </div>
        <h1>Iniciar sesión</h1>
        
        <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <form method="POST" action="">
            <div class="input-group">
                <input type="text" name="username" placeholder="Nombre de usuario" value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" required>
            </div>
            <div class="input-group">
                <input type="password" name="password" placeholder="Contraseña" required>
            </div>
            <div class="checkbox-group">
                <input type="checkbox" name="remember" id="remember">
                <label for="remember">Recordarme por 3 años</label>
            </div>
            <button type="submit" class="btn-primary">Iniciar sesión</button>
        </form>
        <div class="link">
            ¿No tienes cuenta? <a href="signup.php">Regístrate</a>
        </div>
    </div>
    <script>
        const themeToggle = document.getElementById('themeToggle');
        if (localStorage.getItem('theme') === 'light') {
            document.body.classList.add('light');
        }
        themeToggle.addEventListener('click', () => {
            document.body.classList.toggle('light');
            localStorage.setItem('theme', document.body.classList.contains('light') ? 'light' : 'dark');
        });
    </script>
</body>
</html>