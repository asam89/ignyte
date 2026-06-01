<?php
session_start();
require_once __DIR__ . '/config.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$pdo = getDB();
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current = $_POST['current_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    $stmt = $pdo->prepare('SELECT password_hash FROM admin_users WHERE id = ?');
    $stmt->execute([$_SESSION['admin_id']]);
    $user = $stmt->fetch();

    if (!password_verify($current, $user['password_hash'])) {
        $error = 'Current password is incorrect.';
    } elseif (strlen($new) < 8) {
        $error = 'New password must be at least 8 characters.';
    } elseif ($new !== $confirm) {
        $error = 'New passwords do not match.';
    } else {
        $hash = password_hash($new, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('UPDATE admin_users SET password_hash = ? WHERE id = ?');
        $stmt->execute([$hash, $_SESSION['admin_id']]);
        $success = 'Password changed successfully!';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password | IGNYTE Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Syne:wght@700;800&display=swap" rel="stylesheet">
    <style>
        :root { --navy: #002366; --brand-blue: #0047BB; --flame-orange: #EE5A24; --slate: #4A5568; --light-grey: #F4F7FA; }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'DM Sans', sans-serif; background: var(--light-grey); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
        .card { background: white; border-radius: 20px; box-shadow: 0 8px 40px rgba(0,35,102,0.1); padding: 48px 40px; width: 100%; max-width: 460px; }
        .card h2 { font-family: 'Syne', sans-serif; font-size: 1.5rem; color: var(--navy); margin-bottom: 24px; }
        .form-group { margin-bottom: 18px; }
        .form-group label { display: block; font-weight: 600; font-size: 0.85rem; color: var(--navy); margin-bottom: 6px; }
        .form-group input { width: 100%; padding: 11px 14px; border: 1.5px solid rgba(0,0,0,0.1); border-radius: 8px; font-size: 0.92rem; font-family: 'DM Sans', sans-serif; outline: none; }
        .form-group input:focus { border-color: var(--brand-blue); }
        .btn { width: 100%; padding: 13px; background: var(--flame-orange); color: white; border: none; border-radius: 8px; font-size: 0.95rem; font-weight: 700; font-family: 'DM Sans', sans-serif; cursor: pointer; transition: all 0.2s; }
        .btn:hover { background: var(--navy); }
        .error { background: rgba(220,38,38,0.06); border: 1px solid rgba(220,38,38,0.2); color: #dc2626; padding: 10px 14px; border-radius: 8px; font-size: 0.88rem; margin-bottom: 18px; }
        .success { background: rgba(34,197,94,0.08); border: 1px solid rgba(34,197,94,0.2); color: #16a34a; padding: 10px 14px; border-radius: 8px; font-size: 0.88rem; margin-bottom: 18px; }
        .back { display: block; text-align: center; margin-top: 20px; color: var(--brand-blue); font-weight: 600; font-size: 0.88rem; text-decoration: none; }
        .back:hover { color: var(--navy); }
    </style>
</head>
<body>
    <div class="card">
        <h2>Change Password</h2>
        <?php if ($error): ?><div class="error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
        <?php if ($success): ?><div class="success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
        <form method="POST">
            <div class="form-group">
                <label>Current Password</label>
                <input type="password" name="current_password" required>
            </div>
            <div class="form-group">
                <label>New Password</label>
                <input type="password" name="new_password" required minlength="8">
            </div>
            <div class="form-group">
                <label>Confirm New Password</label>
                <input type="password" name="confirm_password" required minlength="8">
            </div>
            <button type="submit" class="btn">Update Password</button>
        </form>
        <a href="dashboard.php" class="back">&larr; Back to Dashboard</a>
    </div>
</body>
</html>
