<?php
/**
 * IGNYTE Consulting - Newsletter Setup
 * Creates the newsletter_campaigns table.
 * Visit this page ONCE, then DELETE it from the server.
 */
require_once __DIR__ . '/config.php';

$message = '';
$error = '';

try {
    $pdo = getDB();

    $pdo->exec("CREATE TABLE IF NOT EXISTS newsletter_campaigns (
        id INT AUTO_INCREMENT PRIMARY KEY,
        subject VARCHAR(255) NOT NULL,
        preview_text VARCHAR(255) DEFAULT '',
        from_name VARCHAR(100) DEFAULT 'IGNYTE Consulting',
        reply_to VARCHAR(100) DEFAULT 'info@ignyteconsulting.com',
        template VARCHAR(50) DEFAULT 'modern',
        headline VARCHAR(255) DEFAULT '',
        body_content TEXT,
        cta_text VARCHAR(100) DEFAULT '',
        cta_url VARCHAR(500) DEFAULT '',
        footer_text VARCHAR(255) DEFAULT '',
        recipients_group VARCHAR(50) DEFAULT 'active',
        recipients_count INT DEFAULT 0,
        sent_count INT DEFAULT 0,
        failed_count INT DEFAULT 0,
        status ENUM('draft', 'sent', 'partial') DEFAULT 'draft',
        sent_at TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $message = "newsletter_campaigns table created successfully! You can now use the Newsletters page. DELETE this file from the server.";
} catch (Exception $e) {
    $error = "Error: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html>
<head><title>Newsletter Setup</title>
<style>
body { font-family: -apple-system, sans-serif; background: #f4f7fa; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; }
.box { background: white; padding: 40px; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.1); max-width: 500px; width: 100%; text-align: center; }
h2 { color: #002366; margin-bottom: 20px; }
.success { background: #d4edda; color: #155724; padding: 14px; border-radius: 8px; margin-bottom: 16px; }
.error { background: #f8d7da; color: #721c24; padding: 14px; border-radius: 8px; margin-bottom: 16px; }
.warning { background: #fff3cd; color: #856404; padding: 10px; border-radius: 8px; margin-top: 16px; font-size: 0.85rem; }
a { color: #0047BB; font-weight: 600; }
</style>
</head>
<body>
<div class="box">
    <h2>Newsletter Setup</h2>
    <?php if ($message): ?><div class="success"><?php echo $message; ?></div><?php endif; ?>
    <?php if ($error): ?><div class="error"><?php echo $error; ?></div><?php endif; ?>
    <p><a href="newsletters.php">Go to Newsletters &rarr;</a></p>
    <div class="warning"><strong>Security:</strong> Delete this file from the server after running it!</div>
</div>
</body>
</html>
