<?php
/**
 * IGNYTE Consulting - Quote System Database Setup
 * Creates tables for quotes, contracts, and service pricing.
 * Run once then DELETE this file.
 */
require_once 'config.php';

try {
    $pdo = getDB();
} catch (Exception $e) {
    die('Database connection failed. Please check your config.php credentials.');
}

$results = [];

// 1. Quote Services (pricing table)
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS quote_services (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        category VARCHAR(50) NOT NULL DEFAULT 'managed',
        description TEXT,
        price_per_user DECIMAL(10,2) DEFAULT 0,
        price_flat DECIMAL(10,2) DEFAULT 0,
        billing_type ENUM('per_user','flat','one_time') DEFAULT 'flat',
        sort_order INT DEFAULT 0,
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $results[] = ['quote_services table', 'Created/verified'];
} catch (Exception $e) {
    $results[] = ['quote_services table', 'Error: ' . $e->getMessage()];
}

// 2. Quotes table
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS quotes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        reference_number VARCHAR(50) NOT NULL,
        company_name VARCHAR(255) NOT NULL,
        contact_name VARCHAR(255),
        contact_email VARCHAR(255),
        contact_phone VARCHAR(50),
        industry VARCHAR(100),
        employee_count INT DEFAULT 0,
        current_setup VARCHAR(50),
        challenges JSON,
        cloud_status VARCHAR(50),
        support_level VARCHAR(50),
        selected_services JSON,
        monthly_total DECIMAL(10,2) DEFAULT 0,
        annual_total DECIMAL(10,2) DEFAULT 0,
        one_time_total DECIMAL(10,2) DEFAULT 0,
        notes TEXT,
        status ENUM('pending','reviewed','signed','expired') DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_status (status),
        INDEX idx_reference (reference_number)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $results[] = ['quotes table', 'Created/verified'];
} catch (Exception $e) {
    $results[] = ['quotes table', 'Error: ' . $e->getMessage()];
}

// 3. Contracts table
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS contracts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        quote_id INT NOT NULL,
        signer_name VARCHAR(255) NOT NULL,
        signer_email VARCHAR(255),
        signer_title VARCHAR(255),
        signature_text VARCHAR(255),
        agreed_terms TINYINT(1) DEFAULT 0,
        ip_address VARCHAR(45),
        signed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $results[] = ['contracts table', 'Created/verified'];
} catch (Exception $e) {
    $results[] = ['contracts table', 'Error: ' . $e->getMessage()];
}

// 4. Seed default services if empty
try {
    $count = $pdo->query("SELECT COUNT(*) FROM quote_services")->fetchColumn();
    if ($count == 0) {
        $defaults = [
            ['Managed IT Support', 'managed', 'Complete IT management including helpdesk, monitoring, and maintenance', 75, 0, 'per_user', 1],
            ['Microsoft 365 Management', 'cloud', 'Full M365 administration, licensing, security, and support', 25, 0, 'per_user', 1],
            ['Cybersecurity Suite', 'security', 'Endpoint protection, threat monitoring, security policies, and training', 35, 0, 'per_user', 2],
            ['Cloud Backup & Recovery', 'backup', 'Automated backups, disaster recovery planning, and testing', 15, 0, 'per_user', 1],
            ['Network Management', 'network', 'Firewall, switches, WiFi, VPN management and monitoring', 0, 500, 'flat', 1],
            ['Server Management', 'infrastructure', 'On-premise or cloud server administration and patching', 0, 750, 'flat', 1],
            ['Website Hosting & Management', 'web', 'Hosting, SSL, updates, backups, and performance optimization', 0, 200, 'flat', 1],
            ['Data Management & Analytics', 'data', 'Database administration, reporting, and data governance', 0, 600, 'flat', 1],
            ['Application Support', 'apps', 'Line-of-business application support and integration', 20, 0, 'per_user', 1],
            ['WiFi Site Survey & Optimization', 'network', 'Professional WiFi assessment, heatmapping, and optimization', 0, 1500, 'one_time', 2],
            ['IT Strategy & Consulting', 'consulting', 'Virtual CIO services, roadmap planning, and technology advisory', 0, 1000, 'flat', 1],
            ['Compliance & Audit Support', 'security', 'PHIPA, SOC2, or industry compliance preparation and documentation', 0, 2000, 'one_time', 3],
        ];
        
        $stmt = $pdo->prepare("INSERT INTO quote_services (name, category, description, price_per_user, price_flat, billing_type, sort_order) VALUES (?,?,?,?,?,?,?)");
        foreach ($defaults as $d) {
            $stmt->execute($d);
        }
        $results[] = ['Default services', 'Seeded ' . count($defaults) . ' services'];
    } else {
        $results[] = ['Default services', 'Skipped (services already exist)'];
    }
} catch (Exception $e) {
    $results[] = ['Default services', 'Error: ' . $e->getMessage()];
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Quote System Setup | IGNYTE</title>
    <style>
        body { font-family: 'DM Sans', sans-serif; max-width: 700px; margin: 40px auto; padding: 20px; background: #f7f8fa; }
        h1 { color: #0a1628; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 12px rgba(0,0,0,0.06); }
        th, td { padding: 14px 20px; text-align: left; border-bottom: 1px solid rgba(0,0,0,0.05); }
        th { background: #0a1628; color: white; font-size: 0.85rem; text-transform: uppercase; }
        .success { color: #22c55e; font-weight: 600; }
        .error { color: #dc2626; font-weight: 600; }
        .note { background: rgba(238,90,36,0.1); padding: 16px 20px; border-radius: 10px; margin-top: 20px; font-size: 0.9rem; color: #0a1628; }
    </style>
</head>
<body>
    <h1>Quote System Setup</h1>
    <table>
        <thead><tr><th>Component</th><th>Status</th></tr></thead>
        <tbody>
            <?php foreach ($results as $r): ?>
            <tr>
                <td><?= htmlspecialchars($r[0]) ?></td>
                <td class="<?= strpos($r[1], 'Error') === 0 ? 'error' : 'success' ?>"><?= htmlspecialchars($r[1]) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <div class="note">
        <strong>Setup complete!</strong> You can now:
        <ul style="margin-top:8px;">
            <li>View the public quote page at <a href="/quote.php">/quote.php</a></li>
            <li>Manage quotes & pricing at <a href="/admin/quotes.php">/admin/quotes.php</a></li>
            <li><strong>Delete this file</strong> from your server for security</li>
        </ul>
    </div>
</body>
</html>
