<?php
/**
 * IGNYTE CRM - Cleanup contacts without email addresses
 * 
 * This script removes all CRM clients that have no email address.
 * Run once, then DELETE this file from the server.
 */

require_once __DIR__ . '/config.php';

try {
    $pdo = getDB();
} catch (Exception $e) {
    die('Database connection failed. Please check your config.php credentials.');
}

// First, show what will be deleted
$preview = $pdo->query("SELECT id, full_name, company_name, email, phone FROM crm_clients WHERE email IS NULL OR TRIM(email) = ''");
$toDelete = $preview->fetchAll();
$count = count($toDelete);

// Also delete related projects and tools (foreign key cascade should handle this, but let's be explicit)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_delete'])) {
    $pdo->beginTransaction();
    try {
        // Get IDs to delete
        $ids = $pdo->query("SELECT id FROM crm_clients WHERE email IS NULL OR TRIM(email) = ''")->fetchAll(PDO::FETCH_COLUMN);
        
        if (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            
            // Delete related projects
            $stmt = $pdo->prepare("DELETE FROM crm_projects WHERE client_id IN ($placeholders)");
            $stmt->execute($ids);
            $deletedProjects = $stmt->rowCount();
            
            // Delete related tools/licenses
            $stmt = $pdo->prepare("DELETE FROM crm_tools WHERE client_id IN ($placeholders)");
            $stmt->execute($ids);
            $deletedTools = $stmt->rowCount();
            
            // Delete the clients
            $stmt = $pdo->prepare("DELETE FROM crm_clients WHERE id IN ($placeholders)");
            $stmt->execute($ids);
            $deletedClients = $stmt->rowCount();
        } else {
            $deletedClients = 0;
            $deletedProjects = 0;
            $deletedTools = 0;
        }
        
        $pdo->commit();
        $done = true;
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CRM Cleanup - Remove Contacts Without Email</title>
    <style>
        body { font-family: 'Inter', sans-serif; max-width: 900px; margin: 40px auto; padding: 0 20px; background: #f5f5f5; }
        h1 { color: #1a1a2e; }
        .card { background: white; border-radius: 8px; padding: 24px; margin: 20px 0; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        table { width: 100%; border-collapse: collapse; margin: 16px 0; }
        th, td { padding: 10px 12px; text-align: left; border-bottom: 1px solid #eee; font-size: 14px; }
        th { background: #f8f9fa; font-weight: 600; color: #555; }
        .btn { display: inline-block; padding: 12px 24px; border: none; border-radius: 6px; font-size: 15px; font-weight: 600; cursor: pointer; text-decoration: none; }
        .btn-danger { background: #dc3545; color: white; }
        .btn-danger:hover { background: #c82333; }
        .btn-back { background: #6c757d; color: white; margin-left: 10px; }
        .success { background: #d4edda; color: #155724; padding: 16px; border-radius: 8px; margin: 20px 0; }
        .error { background: #f8d7da; color: #721c24; padding: 16px; border-radius: 8px; margin: 20px 0; }
        .warning { background: #fff3cd; color: #856404; padding: 16px; border-radius: 8px; margin: 20px 0; }
        .count { font-size: 48px; font-weight: 700; color: #dc3545; }
    </style>
</head>
<body>
    <h1>🧹 CRM Cleanup</h1>
    <p>Remove all contacts that have no email address.</p>

    <?php if (isset($done) && $done): ?>
        <div class="success">
            <h2>✅ Cleanup Complete</h2>
            <p><strong><?= $deletedClients ?></strong> client(s) removed</p>
            <p><strong><?= $deletedProjects ?></strong> related project(s) removed</p>
            <p><strong><?= $deletedTools ?></strong> related tool/license record(s) removed</p>
        </div>
        <a href="crm.php" class="btn btn-back">← Back to CRM</a>
        <div class="warning" style="margin-top: 20px;">
            <strong>⚠️ Security:</strong> Delete this file (<code>cleanup-no-email.php</code>) from the server now via hPanel → File Manager.
        </div>

    <?php elseif (isset($error)): ?>
        <div class="error">
            <h2>Error</h2>
            <p><?= htmlspecialchars($error) ?></p>
        </div>

    <?php else: ?>
        <div class="card">
            <div class="count"><?= $count ?></div>
            <p>contact(s) found <strong>without an email address</strong></p>
        </div>

        <?php if ($count > 0): ?>
            <div class="card">
                <h3>These contacts will be deleted:</h3>
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Name</th>
                            <th>Company</th>
                            <th>Email</th>
                            <th>Phone</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($toDelete as $i => $row): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td><?= htmlspecialchars($row['full_name'] ?: '-') ?></td>
                            <td><?= htmlspecialchars($row['company_name'] ?: '-') ?></td>
                            <td><?= htmlspecialchars($row['email'] ?: '(empty)') ?></td>
                            <td><?= htmlspecialchars($row['phone'] ?: '-') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <form method="POST" onsubmit="return confirm('Are you sure? This cannot be undone.');">
                <button type="submit" name="confirm_delete" value="1" class="btn btn-danger">
                    Delete <?= $count ?> Contact(s) Without Email
                </button>
                <a href="crm.php" class="btn btn-back">Cancel</a>
            </form>
        <?php else: ?>
            <div class="success">
                <p>All contacts have email addresses. Nothing to clean up!</p>
            </div>
            <a href="crm.php" class="btn btn-back">← Back to CRM</a>
        <?php endif; ?>
    <?php endif; ?>
</body>
</html>
