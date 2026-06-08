<?php
/**
 * IGNYTE Consulting - CRM v2 Database Update
 * 
 * Run this ONCE to add client management columns and tables.
 * Visit: https://www.ignyteconsulting.com/admin/crm-setup-v2.php
 * After running, DELETE this file from the server for security.
 */

require_once __DIR__ . '/config.php';

$results = [];
$errors = [];

try {
    $pdo = getDB();

    // 1. Add is_client flag to crm_clients
    try {
        $pdo->exec("ALTER TABLE crm_clients ADD COLUMN is_client TINYINT(1) DEFAULT 0");
        $results[] = "Added is_client column to crm_clients";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) {
            $results[] = "is_client column already exists (skipped)";
        } else { $errors[] = "is_client: " . $e->getMessage(); }
    }

    // 2. Add client_code column
    try {
        $pdo->exec("ALTER TABLE crm_clients ADD COLUMN client_code VARCHAR(10) DEFAULT NULL");
        $results[] = "Added client_code column to crm_clients";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) {
            $results[] = "client_code column already exists (skipped)";
        } else { $errors[] = "client_code: " . $e->getMessage(); }
    }

    // 3. Add environment column
    try {
        $pdo->exec("ALTER TABLE crm_clients ADD COLUMN environment VARCHAR(200) DEFAULT NULL");
        $results[] = "Added environment column to crm_clients";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) {
            $results[] = "environment column already exists (skipped)";
        } else { $errors[] = "environment: " . $e->getMessage(); }
    }

    // 4. Add contract fields
    try {
        $pdo->exec("ALTER TABLE crm_clients ADD COLUMN contract_start DATE DEFAULT NULL");
        $results[] = "Added contract_start column";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) {
            $results[] = "contract_start already exists (skipped)";
        } else { $errors[] = "contract_start: " . $e->getMessage(); }
    }

    try {
        $pdo->exec("ALTER TABLE crm_clients ADD COLUMN contract_end DATE DEFAULT NULL");
        $results[] = "Added contract_end column";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) {
            $results[] = "contract_end already exists (skipped)";
        } else { $errors[] = "contract_end: " . $e->getMessage(); }
    }

    try {
        $pdo->exec("ALTER TABLE crm_clients ADD COLUMN contract_terms TEXT DEFAULT NULL");
        $results[] = "Added contract_terms column";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) {
            $results[] = "contract_terms already exists (skipped)";
        } else { $errors[] = "contract_terms: " . $e->getMessage(); }
    }

    try {
        $pdo->exec("ALTER TABLE crm_clients ADD COLUMN key_services TEXT DEFAULT NULL");
        $results[] = "Added key_services column";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) {
            $results[] = "key_services already exists (skipped)";
        } else { $errors[] = "key_services: " . $e->getMessage(); }
    }

    // 5. Add linked_client_id column (links a contact to a client)
    try {
        $pdo->exec("ALTER TABLE crm_clients ADD COLUMN linked_client_id INT DEFAULT NULL");
        $results[] = "Added linked_client_id column to crm_clients";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) {
            $results[] = "linked_client_id column already exists (skipped)";
        } else { $errors[] = "linked_client_id: " . $e->getMessage(); }
    }

    // 6. Create client_devices table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS client_devices (
            id INT AUTO_INCREMENT PRIMARY KEY,
            client_id INT NOT NULL,
            device_name VARCHAR(200) NOT NULL,
            device_type ENUM('desktop','laptop','server','mobile','tablet','printer','network','other') DEFAULT 'laptop',
            hostname VARCHAR(100) DEFAULT NULL,
            os VARCHAR(100) DEFAULT NULL,
            serial_number VARCHAR(100) DEFAULT NULL,
            status ENUM('active','inactive','retired') DEFAULT 'active',
            notes TEXT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (client_id) REFERENCES crm_clients(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $results[] = "Created client_devices table";

    // 6. Create client_platforms table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS client_platforms (
            id INT AUTO_INCREMENT PRIMARY KEY,
            client_id INT NOT NULL,
            platform_name VARCHAR(200) NOT NULL,
            platform_type ENUM('saas','iaas','paas','on-premise','hybrid','other') DEFAULT 'saas',
            account_id VARCHAR(200) DEFAULT NULL,
            license_count INT DEFAULT 0,
            status ENUM('active','inactive') DEFAULT 'active',
            notes TEXT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (client_id) REFERENCES crm_clients(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $results[] = "Created client_platforms table";

    // 7. Mark existing active clients with company_name as is_client = 1
    // (the 36 imported IGNYTE clients all have company_name and status=active)
    $updated = $pdo->exec("UPDATE crm_clients SET is_client = 1 WHERE status = 'active' AND company_name IS NOT NULL AND company_name != '' AND is_client = 0");
    $results[] = "Marked $updated existing active client(s) as is_client = 1";

    // 8. Parse client codes from notes field (the import script stored "Code: XX" in notes)
    $clients = $pdo->query("SELECT id, notes FROM crm_clients WHERE is_client = 1 AND (client_code IS NULL OR client_code = '')")->fetchAll();
    $codesParsed = 0;
    $envParsed = 0;
    $servicesParsed = 0;
    foreach ($clients as $cl) {
        $notes = $cl['notes'] ?? '';
        $code = '';
        $env = '';
        $services = '';
        if (preg_match('/Code:\s*(\w+)/i', $notes, $m)) $code = strtoupper(trim($m[1]));
        if (preg_match('/Environment:\s*(.+?)(?:\||$)/i', $notes, $m)) $env = trim($m[1]);
        if (preg_match('/Key Services:\s*(.+?)$/im', $notes, $m)) $services = trim($m[1]);

        if ($code || ($env && $env !== '-')) {
            $updates = [];
            $params = [];
            if ($code) { $updates[] = 'client_code = ?'; $params[] = $code; $codesParsed++; }
            if ($env && $env !== '-') { $updates[] = 'environment = ?'; $params[] = $env; $envParsed++; }
            if ($services && $services !== '-') { $updates[] = 'key_services = ?'; $params[] = $services; $servicesParsed++; }
            if (!empty($updates)) {
                $params[] = $cl['id'];
                $pdo->prepare('UPDATE crm_clients SET ' . implode(', ', $updates) . ' WHERE id = ?')->execute($params);
            }
        }
    }
    if ($codesParsed) $results[] = "Parsed $codesParsed client code(s) from notes";
    if ($envParsed) $results[] = "Parsed $envParsed environment(s) from notes";
    if ($servicesParsed) $results[] = "Parsed $servicesParsed key service(s) from notes";

    $hasErrors = !empty($errors);

} catch (Exception $e) {
    $errors[] = "Fatal: " . $e->getMessage();
    $hasErrors = true;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>CRM v2 Setup <?php echo $hasErrors ? '(Errors)' : 'Complete'; ?></title>
    <style>
        body { font-family: 'DM Sans', sans-serif; display: flex; justify-content: center; align-items: center; min-height: 100vh; background: #f4f7fa; margin: 0; }
        .box { background: white; padding: 48px; border-radius: 16px; box-shadow: 0 4px 24px rgba(0,0,0,0.08); max-width: 600px; width: 90%; }
        h2 { color: <?php echo $hasErrors ? '#dc2626' : '#002366'; ?>; margin-bottom: 16px; }
        p { color: #4a5568; margin-bottom: 12px; }
        .items { background: #f4f7fa; padding: 16px; border-radius: 8px; margin: 16px 0; }
        .items li { padding: 4px 0; font-size: 0.9rem; color: #4a5568; }
        .error { color: #dc2626; }
        .warn { color: #dc2626; font-weight: 700; margin-top: 20px; }
        a { color: #0047BB; font-weight: 700; text-decoration: none; }
    </style>
</head>
<body>
<div class="box">
    <h2>CRM v2 Setup <?php echo $hasErrors ? 'Completed with Errors' : 'Complete!'; ?></h2>

    <p>Changes applied:</p>
    <ul class="items">
        <?php foreach ($results as $r): ?>
            <li><?php echo htmlspecialchars($r); ?></li>
        <?php endforeach; ?>
    </ul>

    <?php if (!empty($errors)): ?>
        <p class="error"><strong>Errors:</strong></p>
        <ul class="items">
            <?php foreach ($errors as $e): ?>
                <li class="error"><?php echo htmlspecialchars($e); ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <p>You can now:</p>
    <p><a href="clients.php">Manage Clients &rarr;</a></p>
    <p><a href="crm.php">Manage Contacts &rarr;</a></p>
    <p class="warn">DELETE this crm-setup-v2.php file after running!</p>
</div>
</body>
</html>
