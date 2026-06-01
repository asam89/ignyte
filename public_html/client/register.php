<?php
session_start();
require_once __DIR__ . '/../admin/config.php';

if (isset($_SESSION['client_id'])) {
    header('Location: dashboard.php');
    exit;
}

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $company = trim($_POST['company_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (!$full_name || !$email || !$password) {
        $error = 'Please fill in all required fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        try {
            $pdo = getDB();

            $check = $pdo->prepare('SELECT id FROM clients WHERE email = ?');
            $check->execute([$email]);
            if ($check->fetch()) {
                $error = 'An account with this email already exists.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare('INSERT INTO clients (full_name, email, company_name, phone, password_hash, status) VALUES (?, ?, ?, ?, ?, ?)');
                $stmt->execute([$full_name, $email, $company, $phone, $hash, 'pending']);
                $success = 'Account request submitted! You will receive access once approved by our team.';
            }
        } catch (Exception $e) {
            $error = 'Registration failed. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request Access | IGNYTE Consulting</title>
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
        .register-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 8px 40px rgba(0,35,102,0.1);
            padding: 48px 40px;
            width: 100%;
            max-width: 480px;
        }
        .register-header { text-align: center; margin-bottom: 36px; }
        .register-header img { height: 52px; margin-bottom: 20px; }
        .register-header h1 {
            font-family: 'Inter', sans-serif;
            font-size: 1.5rem;
            color: var(--navy);
            font-weight: 700;
            margin-bottom: 6px;
        }
        .register-header p { color: var(--slate); font-size: 0.92rem; }
        .form-group { margin-bottom: 18px; }
        .form-group label {
            display: block;
            font-weight: 600;
            font-size: 0.86rem;
            color: var(--navy);
            margin-bottom: 5px;
        }
        .form-group input {
            width: 100%;
            padding: 11px 16px;
            border: 1.5px solid rgba(0,0,0,0.12);
            border-radius: 10px;
            font-size: 0.93rem;
            font-family: 'DM Sans', sans-serif;
            transition: border-color 0.2s;
            outline: none;
        }
        .form-group input:focus { border-color: var(--brand-blue); }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
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
        .success-msg {
            background: rgba(34,197,94,0.06);
            border: 1px solid rgba(34,197,94,0.2);
            color: #16a34a;
            padding: 14px;
            border-radius: 8px;
            font-size: 0.9rem;
            margin-bottom: 20px;
            text-align: center;
        }
        .btn-register {
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
        .btn-register:hover {
            background: var(--navy);
            transform: translateY(-1px);
        }
        .login-link {
            display: block;
            text-align: center;
            margin-top: 20px;
            color: var(--slate);
            font-size: 0.88rem;
        }
        .login-link a {
            color: var(--brand-blue);
            font-weight: 600;
            text-decoration: none;
        }
        .login-link a:hover { color: var(--navy); }
        .required { color: #dc2626; }
        @media (max-width: 500px) {
            .form-row { grid-template-columns: 1fr; }
            .register-container { padding: 36px 24px; }
        }
    </style>
</head>
<body>
    <div class="register-container">
        <div class="register-header">
            <a href="../index.html"><img src="../logo.png" alt="IGNYTE Consulting"></a>
            <h1>Request Client Access</h1>
            <p>Fill out the form below and our team will set up your account</p>
        </div>

        <?php if ($success): ?>
            <div class="success-msg"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="error-msg"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if (!$success): ?>
        <form method="POST">
            <div class="form-group">
                <label>Full Name <span class="required">*</span></label>
                <input type="text" name="full_name" required placeholder="John Smith" value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label>Email Address <span class="required">*</span></label>
                <input type="email" name="email" required placeholder="john@company.com" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Company Name</label>
                    <input type="text" name="company_name" placeholder="Company Inc." value="<?php echo htmlspecialchars($_POST['company_name'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label>Phone Number</label>
                    <input type="tel" name="phone" placeholder="+1 (555) 000-0000" value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Password <span class="required">*</span></label>
                    <input type="password" name="password" required placeholder="Min 8 characters" minlength="8">
                </div>
                <div class="form-group">
                    <label>Confirm Password <span class="required">*</span></label>
                    <input type="password" name="confirm_password" required placeholder="Repeat password">
                </div>
            </div>
            <button type="submit" class="btn-register">Request Access</button>
        </form>
        <?php endif; ?>

        <div class="login-link">
            Already have an account? <a href="login.php">Sign In</a>
        </div>
    </div>
</body>
</html>
