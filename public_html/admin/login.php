<?php
session_start();
require_once __DIR__ . '/config.php';

// Redirect if already logged in
if (isset($_SESSION['admin_id'])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username && $password) {
        try {
            $pdo = getDB();
            $stmt = $pdo->prepare('SELECT id, username, password_hash, display_name FROM admin_users WHERE username = ?');
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                $_SESSION['admin_id'] = $user['id'];
                $_SESSION['admin_name'] = $user['display_name'];
                $_SESSION['admin_user'] = $user['username'];

                // Update last login
                $pdo->prepare('UPDATE admin_users SET last_login = NOW() WHERE id = ?')->execute([$user['id']]);

                header('Location: dashboard.php');
                exit;
            } else {
                $error = 'Invalid username or password.';
            }
        } catch (Exception $e) {
            $error = 'Login failed. Please try again.';
        }
    } else {
        $error = 'Please enter both username and password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login | IGNYTE Consulting</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Inter:wght@600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --navy: #002366;
            --brand-blue: #0047BB;
            --flame-orange: #EE5A24;
            --slate: #4A5568;
            --light-grey: #F4F7FA;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'DM Sans', sans-serif;
            background: var(--light-grey);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .login-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 8px 40px rgba(0,35,102,0.1);
            padding: 48px 40px;
            width: 100%;
            max-width: 420px;
        }
        .login-header { text-align: center; margin-bottom: 36px; }
        .login-header img { height: 52px; margin-bottom: 20px; }
        .login-header h1 {
            font-family: 'Inter', sans-serif;
            font-size: 1.6rem;
            color: var(--navy);
            font-weight: 800;
            margin-bottom: 6px;
        }
        .login-header p { color: var(--slate); font-size: 0.92rem; }
        .form-group { margin-bottom: 20px; }
        .form-group label {
            display: block;
            font-weight: 600;
            font-size: 0.88rem;
            color: var(--navy);
            margin-bottom: 6px;
        }
        .form-group input {
            width: 100%;
            padding: 12px 16px;
            border: 1.5px solid rgba(0,0,0,0.12);
            border-radius: 10px;
            font-size: 0.95rem;
            font-family: 'DM Sans', sans-serif;
            transition: border-color 0.2s;
            outline: none;
        }
        .form-group input:focus { border-color: var(--brand-blue); }
        .error-msg {
            background: rgba(220,38,38,0.06);
            border: 1px solid rgba(220,38,38,0.2);
            color: #dc2626;
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 0.88rem;
            margin-bottom: 20px;
            text-align: center;
        }
        .btn-login {
            width: 100%;
            padding: 14px;
            background: var(--flame-orange);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 1rem;
            font-weight: 700;
            font-family: 'DM Sans', sans-serif;
            cursor: pointer;
            transition: all 0.2s;
            box-shadow: 0 4px 16px rgba(238,90,36,0.3);
        }
        .btn-login:hover {
            background: var(--navy);
            box-shadow: 0 4px 16px rgba(0,35,102,0.3);
            transform: translateY(-1px);
        }
        .back-link {
            display: block;
            text-align: center;
            margin-top: 24px;
            color: var(--brand-blue);
            font-size: 0.88rem;
            font-weight: 600;
            text-decoration: none;
        }
        .back-link:hover { color: var(--navy); }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <img src="../logo.png" alt="IGNYTE Consulting">
            <h1>Admin Portal</h1>
            <p>Sign in to manage your blog posts</p>
        </div>
        <?php if ($error): ?>
            <div class="error-msg"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <form method="POST" action="">
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" required autocomplete="username" 
                       value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required autocomplete="current-password">
            </div>
            <button type="submit" class="btn-login">Sign In</button>
        </form>
        <a href="../index.html" class="back-link">&larr; Back to Website</a>
    </div>
</body>
</html>
