<?php
/**
 * IGNYTE Consulting - Database Setup
 * 
 * Run this ONCE to create the required tables.
 * Visit: https://www.ignyteconsulting.com/admin/setup.php
 * After running, DELETE this file from the server for security.
 */

require_once __DIR__ . '/config.php';

try {
    $pdo = getDB();

    // Create admin_users table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS admin_users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            display_name VARCHAR(100) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            last_login TIMESTAMP NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Create blog_posts table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS blog_posts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            slug VARCHAR(255) NOT NULL UNIQUE,
            content TEXT NOT NULL,
            excerpt VARCHAR(500) DEFAULT NULL,
            category VARCHAR(50) DEFAULT 'General',
            status ENUM('draft','published') DEFAULT 'published',
            author_id INT NOT NULL,
            featured_image VARCHAR(500) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (author_id) REFERENCES admin_users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Create default admin user (change password after first login!)
    $defaultPassword = password_hash('ignyte2026!', PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("
        INSERT IGNORE INTO admin_users (username, password_hash, display_name) 
        VALUES ('admin', ?, 'IGNYTE Admin')
    ");
    $stmt->execute([$defaultPassword]);

    echo "<!DOCTYPE html><html><head><title>Setup Complete</title>
    <style>body{font-family:'DM Sans',sans-serif;display:flex;justify-content:center;align-items:center;min-height:100vh;background:#f4f7fa;margin:0;}
    .box{background:white;padding:48px;border-radius:16px;box-shadow:0 4px 24px rgba(0,0,0,0.08);max-width:500px;text-align:center;}
    h2{color:#002366;margin-bottom:16px;} p{color:#4a5568;margin-bottom:12px;}
    .cred{background:#f4f7fa;padding:16px;border-radius:8px;text-align:left;font-family:monospace;margin:16px 0;}
    .warn{color:#dc2626;font-weight:700;margin-top:20px;}</style></head>
    <body><div class='box'>
    <h2>Setup Complete!</h2>
    <p>Database tables created successfully.</p>
    <div class='cred'>
        <strong>Default Admin Login:</strong><br>
        Username: <code>admin</code><br>
        Password: <code>ignyte2026!</code>
    </div>
    <p class='warn'>IMPORTANT: Change your password after first login and DELETE this setup.php file!</p>
    <p><a href='login.php' style='color:#0047BB;font-weight:700;'>Go to Admin Login &rarr;</a></p>
    </div></body></html>";

} catch (Exception $e) {
    echo "Setup failed: " . htmlspecialchars($e->getMessage());
}
