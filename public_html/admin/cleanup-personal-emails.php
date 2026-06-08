<?php
/**
 * IGNYTE CRM - Cleanup contacts with personal email domains
 * 
 * Removes all CRM contacts that use personal email providers (Gmail, Yahoo, Hotmail, etc.)
 * Keeps only contacts with business/corporate email addresses.
 * Run once, then DELETE this file from the server.
 */

require_once __DIR__ . '/config.php';

try {
    $pdo = getDB();
} catch (Exception $e) {
    die('Database connection failed. Please check your config.php credentials.');
}

// Personal email domains to filter out
$personalDomains = [
    'gmail.com', 'googlemail.com',
    'yahoo.com', 'yahoo.ca', 'yahoo.co.uk', 'yahoo.co.in', 'ymail.com', 'rocketmail.com',
    'hotmail.com', 'hotmail.ca', 'hotmail.co.uk',
    'outlook.com', 'outlook.ca',
    'live.com', 'live.ca', 'live.co.uk',
    'msn.com',
    'aol.com',
    'icloud.com', 'me.com', 'mac.com',
    'protonmail.com', 'proton.me', 'pm.me',
    'zoho.com',
    'mail.com',
    'gmx.com', 'gmx.net',
    'yandex.com', 'yandex.ru',
    'tutanota.com', 'tuta.io',
    'fastmail.com',
    'hushmail.com',
    'inbox.com',
    'rogers.com', 'sympatico.ca', 'bell.net', 'shaw.ca', 'telus.net', 'cogeco.ca',
];

// Build SQL LIKE conditions
$conditions = [];
$params = [];
foreach ($personalDomains as $domain) {
    $conditions[] = "LOWER(email) LIKE ?";
    $params[] = '%@' . $domain;
}
$whereClause = '(' . implode(' OR ', $conditions) . ')';

// Only target contacts (is_client = 0 or NULL), not actual client records
$sql = "SELECT id, full_name, company_name, email, phone FROM crm_clients WHERE $whereClause AND (is_client = 0 OR is_client IS NULL) ORDER BY email ASC";
$preview = $pdo->prepare($sql);
$preview->execute($params);
$toDelete = $preview->fetchAll();
$count = count($toDelete);

// Handle deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_delete'])) {
    $pdo->beginTransaction();
    try {
        $idSql = "SELECT id FROM crm_clients WHERE $whereClause AND (is_client = 0 OR is_client IS NULL)";
        $idStmt = $pdo->prepare($idSql);
        $idStmt->execute($params);
        $ids = $idStmt->fetchAll(PDO::FETCH_COLUMN);

        $deletedClients = 0;
        $deletedProjects = 0;
        $deletedTools = 0;

        if (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));

            // Delete related projects
            $stmt = $pdo->prepare("DELETE FROM crm_projects WHERE client_id IN ($placeholders)");
            $stmt->execute($ids);
            $deletedProjects = $stmt->rowCount();

            // Delete related tools/licenses
            try {
                $stmt = $pdo->prepare("DELETE FROM crm_tools WHERE client_id IN ($placeholders)");
                $stmt->execute($ids);
                $deletedTools = $stmt->rowCount();
            } catch (PDOException $e) {}

            // Delete the contacts
            $stmt = $pdo->prepare("DELETE FROM crm_clients WHERE id IN ($placeholders)");
            $stmt->execute($ids);
            $deletedClients = $stmt->rowCount();
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
    <title>CRM Cleanup - Remove Personal Emails</title>
    <style>
        body { font-family: 'DM Sans', 'Inter', sans-serif; max-width: 900px; margin: 40px auto; padding: 0 20px; background: #f4f7fa; }
        h1 { color: #002366; }
        .card { background: white; border-radius: 12px; padding: 24px; margin: 20px 0; box-shadow: 0 2px 12px rgba(0,0,0,0.06); }
        table { width: 100%; border-collapse: collapse; margin: 16px 0; }
        th, td { padding: 10px 12px; text-align: left; border-bottom: 1px solid #e8ecf1; font-size: 0.88rem; }
        th { background: #f4f7fa; font-weight: 600; color: #4a5568; }
        .personal { color: #dc2626; font-weight: 600; }
        .btn { display: inline-block; padding: 12px 24px; border: none; border-radius: 10px; font-size: 0.9rem; font-weight: 700; cursor: pointer; text-decoration: none; font-family: 'DM Sans', sans-serif; }
        .btn-danger { background: #dc2626; color: white; }
        .btn-danger:hover { background: #b91c1c; }
        .btn-back { background: #4a5568; color: white; margin-left: 10px; }
        .success { background: rgba(5,150,105,0.08); color: #065f46; padding: 20px; border-radius: 12px; margin: 20px 0; border: 1px solid rgba(5,150,105,0.2); }
        .error { background: rgba(220,38,38,0.08); color: #991b1b; padding: 20px; border-radius: 12px; margin: 20px 0; border: 1px solid rgba(220,38,38,0.2); }
        .warning { background: rgba(234,179,8,0.08); color: #a16207; padding: 16px; border-radius: 12px; margin: 20px 0; border: 1px solid rgba(234,179,8,0.2); }
        .count { font-size: 3rem; font-weight: 800; color: #dc2626; font-family: 'Inter', sans-serif; }
        .domain-list { display: flex; flex-wrap: wrap; gap: 6px; margin: 12px 0; }
        .domain-tag { background: rgba(220,38,38,0.08); color: #dc2626; padding: 4px 10px; border-radius: 6px; font-size: 0.78rem; font-weight: 600; }
    </style>
</head>
<body>
    <h1>&#128231; Remove Personal Emails</h1>
    <p>Remove all contacts with personal email providers. Only business/corporate emails will remain.</p>

    <div class="card">
        <p style="font-weight:600;margin-bottom:8px;">Personal domains being filtered:</p>
        <div class="domain-list">
            <?php foreach (array_slice($personalDomains, 0, 15) as $d): ?>
            <span class="domain-tag">@<?php echo $d; ?></span>
            <?php endforeach; ?>
            <span class="domain-tag">+ <?php echo count($personalDomains) - 15; ?> more</span>
        </div>
        <p style="font-size:0.82rem;color:#4a5568;margin-top:8px;">Note: Only <strong>contacts</strong> are affected. Client records (is_client=1) are never deleted by this script.</p>
    </div>

    <?php if (isset($done) && $done): ?>
        <div class="success">
            <h2>Cleanup Complete</h2>
            <p><strong><?php echo $deletedClients; ?></strong> contact(s) with personal emails removed</p>
            <p><strong><?php echo $deletedProjects; ?></strong> related project(s) removed</p>
            <p><strong><?php echo $deletedTools; ?></strong> related tool/license record(s) removed</p>
        </div>
        <a href="crm.php" class="btn btn-back">&#8592; Back to Contacts</a>
        <div class="warning" style="margin-top: 20px;">
            <strong>&#9888; Security:</strong> Delete this file (<code>cleanup-personal-emails.php</code>) from the server now via hPanel &#8594; File Manager.
        </div>

    <?php elseif (isset($error)): ?>
        <div class="error">
            <h2>Error</h2>
            <p><?php echo htmlspecialchars($error); ?></p>
        </div>

    <?php else: ?>
        <div class="card">
            <div class="count"><?php echo $count; ?></div>
            <p>contact(s) found with <strong>personal email addresses</strong></p>
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
                            <td><?php echo $i + 1; ?></td>
                            <td><?php echo htmlspecialchars($row['full_name'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($row['company_name'] ?? '-'); ?></td>
                            <td class="personal"><?php echo htmlspecialchars($row['email'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($row['phone'] ?? '-'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <form method="POST" onsubmit="return confirm('Delete <?php echo $count; ?> contacts with personal emails? This cannot be undone.');">
                    <input type="hidden" name="confirm_delete" value="1">
                    <button type="submit" class="btn btn-danger">&#128465; Delete <?php echo $count; ?> Personal Email Contact(s)</button>
                    <a href="crm.php" class="btn btn-back">Cancel</a>
                </form>
            </div>
        <?php else: ?>
            <div class="success">
                <h2>All Clean!</h2>
                <p>No contacts with personal email addresses found. Your CRM only has business emails.</p>
            </div>
            <a href="crm.php" class="btn btn-back">&#8592; Back to Contacts</a>
        <?php endif; ?>

    <?php endif; ?>

    <div class="warning" style="margin-top:24px;">
        <strong>&#9888; Reminder:</strong> Delete this file from the server after running! (hPanel &#8594; File Manager &#8594; <code>public_html/admin/cleanup-personal-emails.php</code>)
    </div>
</body>
</html>
