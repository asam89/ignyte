<?php
session_start();
require_once __DIR__ . '/../admin/config.php';

if (isset($_SESSION['client_id'])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email && $password) {
        try {
            $pdo = getDB();
            $stmt = $pdo->prepare('SELECT id, email, password_hash, full_name, company_name, status FROM clients WHERE email = ?');
            $stmt->execute([$email]);
            $client = $stmt->fetch();

            if ($client && password_verify($password, $client['password_hash'])) {
                if ($client['status'] !== 'active') {
                    $error = 'Your account is pending approval. Please contact us.';
                } else {
                    $_SESSION['client_id'] = $client['id'];
                    $_SESSION['client_name'] = $client['full_name'];
                    $_SESSION['client_email'] = $client['email'];
                    $_SESSION['client_company'] = $client['company_name'];

                    $pdo->prepare('UPDATE clients SET last_login = NOW() WHERE id = ?')->execute([$client['id']]);

                    header('Location: dashboard.php');
                    exit;
                }
            } else {
                $error = 'Invalid email or password.';
            }
        } catch (Exception $e) {
            $error = 'Login failed. Please try again.';
        }
    } else {
        $error = 'Please enter both email and password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Client Login | IGNYTE Consulting</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Syne:wght@700;800&display=swap" rel="stylesheet">
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
            max-width: 440px;
        }
        .login-header { text-align: center; margin-bottom: 36px; }
        .login-header img { height: 52px; margin-bottom: 20px; }
        .login-header h1 {
            font-family: 'Syne', sans-serif;
            font-size: 1.6rem;
            color: var(--navy);
            font-weight: 700;
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
            background: var(--brand-blue);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 1rem;
            font-weight: 700;
            font-family: 'DM Sans', sans-serif;
            cursor: pointer;
            transition: all 0.2s;
            box-shadow: 0 4px 16px rgba(0,71,187,0.3);
        }
        .btn-login:hover {
            background: var(--navy);
            box-shadow: 0 4px 16px rgba(0,35,102,0.3);
            transform: translateY(-1px);
        }
        .register-link {
            display: block;
            text-align: center;
            margin-top: 24px;
            color: var(--slate);
            font-size: 0.88rem;
        }
        .register-link a {
            color: var(--brand-blue);
            font-weight: 600;
            text-decoration: none;
        }
        .register-link a:hover { color: var(--navy); }
        .back-link {
            display: block;
            text-align: center;
            margin-top: 16px;
            color: var(--brand-blue);
            font-size: 0.88rem;
            font-weight: 600;
            text-decoration: none;
        }
        .back-link:hover { color: var(--navy); }
        .divider {
            display: flex;
            align-items: center;
            margin: 24px 0;
            color: #ccc;
            font-size: 0.82rem;
        }
        .divider::before, .divider::after {
            content: '';
            flex: 1;
            border-bottom: 1px solid #e5e7eb;
        }
        .divider span { padding: 0 12px; }
        .support-link {
            display: block;
            text-align: center;
            padding: 12px;
            background: var(--light-grey);
            border-radius: 10px;
            color: var(--navy);
            font-size: 0.88rem;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.2s;
        }
        .support-link:hover { background: var(--navy); color: white; }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <a href="../index.html"><img src="../logo.png" alt="IGNYTE Consulting"></a>
            <h1>Client Portal</h1>
            <p>Access your projects, documents, and invoices</p>
        </div>

        <?php if ($error): ?>
            <div class="error-msg"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label>Email Address</label>
                <input type="email" name="email" required placeholder="you@company.com" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" required placeholder="Enter your password">
            </div>
            <button type="submit" class="btn-login">Sign In</button>
        </form>

        <div class="register-link">
            Don't have an account? <a href="register.php">Request Access</a>
        </div>

        <div class="divider"><span>or</span></div>

        <a href="https://igy.atlassian.net/servicedesk/customer/portal/2" target="_blank" rel="noopener" class="support-link">Submit a Support Request &#8599;</a>

        <a href="../index.html" class="back-link">&larr; Back to Home</a>
    </div>
</body>
</html>
