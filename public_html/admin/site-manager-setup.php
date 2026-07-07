<?php
/**
 * Site Manager Integration Setup
 * Adds site_manager_site_id column to crm_clients table.
 * Run once, then delete this file.
 */
session_start();
require_once __DIR__ . '/config.php';

$pdo = getDB();
$results = [];

// Add site_manager_site_id to crm_clients
try {
    $pdo->exec("ALTER TABLE crm_clients ADD COLUMN site_manager_site_id VARCHAR(255) DEFAULT NULL");
    $results[] = "✅ Added site_manager_site_id column to crm_clients";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false) {
        $results[] = "ℹ️ site_manager_site_id column already exists";
    } else {
        $results[] = "❌ Error: " . $e->getMessage();
    }
}

// Add site_manager_email to crm_clients (maps to the user email in Site Manager)
try {
    $pdo->exec("ALTER TABLE crm_clients ADD COLUMN site_manager_email VARCHAR(255) DEFAULT NULL");
    $results[] = "✅ Added site_manager_email column to crm_clients";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false) {
        $results[] = "ℹ️ site_manager_email column already exists";
    } else {
        $results[] = "❌ Error: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Site Manager Setup | IGNYTE</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 600px; margin: 60px auto; padding: 0 20px; }
        h1 { font-size: 1.5rem; margin-bottom: 20px; }
        .result { padding: 10px 16px; margin: 8px 0; border-radius: 8px; background: #f9fafb; border: 1px solid #e5e7eb; }
        .note { margin-top: 24px; padding: 16px; background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; font-size: 0.9rem; }
    </style>
</head>
<body>
    <h1>Site Manager Integration Setup</h1>
    <?php foreach ($results as $r): ?>
        <div class="result"><?php echo $r; ?></div>
    <?php endforeach; ?>
    <div class="note">
        <strong>Next steps:</strong><br>
        1. In the admin Clients page, set the <code>site_manager_site_id</code> for each client that has a website managed through Site Manager.<br>
        2. Add <code>SITE_MANAGER_URL</code> and <code>SITE_MANAGER_API_SECRET</code> to <code>config.php</code> on Hostinger.<br>
        3. <strong>Delete this file</strong> from the server.
    </div>
</body>
</html>
