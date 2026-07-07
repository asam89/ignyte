<?php
/**
 * IGNYTE Admin - Emergency Password Reset
 * Upload to /admin/reset-password.php, use it once, then DELETE it.
 */
require_once __DIR__ . '/config.php';

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (strlen($newPassword) < 4) {
        $error = 'Password must be at least 4 characters.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } else {
        try {
            $pdo = getDB();
            $hash = password_hash($newPassword, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare('UPDATE admin_users SET password_hash = ? WHERE id = 1');
            $stmt->execute([$hash]);
            $message = "Password updated successfully! You can now login with username 'admin' and your new password. DELETE this file from the server now.";
        } catch (Exception $e) {
            $error = 'Database error: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Reset Admin Password</title>
    <style>
        body { font-family: -apple-system, sans-serif; background: #f4f7fa; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; }
        .box { background: white; padding: 40px; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.1); max-width: 400px; width: 100%; }
        h2 { color: #002366; margin-bottom: 20px; }
        label { display: block; font-weight: 600; margin-bottom: 6px; font-size: 0.9rem; }
        input { width: 100%; padding: 10px; border: 1.5px solid #ddd; border-radius: 8px; font-size: 1rem; margin-bottom: 16px; box-sizing: border-box; }
        button { width: 100%; padding: 12px; background: #EE5A24; color: white; border: none; border-radius: 8px; font-size: 1rem; font-weight: 700; cursor: pointer; }
        button:hover { background: #002366; }
        .success { background: #d4edda; color: #155724; padding: 12px; border-radius: 8px; margin-bottom: 16px; }
        .error { background: #f8d7da; color: #721c24; padding: 12px; border-radius: 8px; margin-bottom: 16px; }
        .warning { background: #fff3cd; color: #856404; padding: 10px; border-radius: 8px; margin-top: 16px; font-size: 0.85rem; }
    </style>
</head>
<body>
    <div class="box">
        <h2>Reset Admin Password</h2>
        <?php if ($message): ?><div class="success"><?php echo $message; ?></div><?php endif; ?>
        <?php if ($error): ?><div class="error"><?php echo $error; ?></div><?php endif; ?>
        <form method="POST">
            <label>New Password</label>
            <input type="password" name="new_password" required autofocus>
            <label>Confirm Password</label>
            <input type="password" name="confirm_password" required>
            <button type="submit">Reset Password</button>
        </form>
        <div class="warning">
            <strong>Security:</strong> Delete this file immediately after resetting your password!
        </div>
    </div>
</body>
</html>
