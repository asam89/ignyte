<?php
/**
 * IGNYTE Consulting - CRM Database Setup
 * 
 * Run this ONCE to create the CRM tables.
 * Visit: https://www.ignyteconsulting.com/admin/crm-setup.php
 * After running, DELETE this file from the server for security.
 */

require_once __DIR__ . '/config.php';

try {
    $pdo = getDB();

    // Create CRM clients table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS crm_clients (
            id INT AUTO_INCREMENT PRIMARY KEY,
            full_name VARCHAR(150) NOT NULL,
            email VARCHAR(255) NOT NULL,
            phone VARCHAR(50) DEFAULT NULL,
            company_name VARCHAR(200) DEFAULT NULL,
            industry VARCHAR(100) DEFAULT NULL,
            address TEXT DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            status ENUM('active','inactive','prospect') DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Add mailchimp_synced column if not exists
    try {
        $pdo->exec("ALTER TABLE crm_clients ADD COLUMN mailchimp_synced DATETIME DEFAULT NULL");
    } catch (PDOException $e) {
        // Column already exists, ignore
    }

    // Create CRM projects table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS crm_projects (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_name VARCHAR(255) NOT NULL,
            client_id INT DEFAULT NULL,
            description TEXT DEFAULT NULL,
            status ENUM('planned','active','on_hold','completed','cancelled') DEFAULT 'planned',
            priority ENUM('low','medium','high') DEFAULT 'medium',
            start_date DATE DEFAULT NULL,
            end_date DATE DEFAULT NULL,
            budget VARCHAR(50) DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (client_id) REFERENCES crm_clients(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Create CRM tools/licenses table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS crm_tools (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tool_name VARCHAR(200) NOT NULL,
            client_id INT DEFAULT NULL,
            vendor VARCHAR(200) DEFAULT NULL,
            license_key VARCHAR(500) DEFAULT NULL,
            cost VARCHAR(50) DEFAULT NULL,
            billing_cycle ENUM('monthly','quarterly','annual','one_time','free') DEFAULT 'monthly',
            start_date DATE DEFAULT NULL,
            expiry_date DATE DEFAULT NULL,
            status ENUM('active','inactive','expired') DEFAULT 'active',
            notes TEXT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (client_id) REFERENCES crm_clients(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    echo "<!DOCTYPE html><html><head><title>CRM Setup Complete</title>
    <style>body{font-family:'DM Sans',sans-serif;display:flex;justify-content:center;align-items:center;min-height:100vh;background:#f4f7fa;margin:0;}
    .box{background:white;padding:48px;border-radius:16px;box-shadow:0 4px 24px rgba(0,0,0,0.08);max-width:500px;text-align:center;}
    h2{color:#002366;margin-bottom:16px;} p{color:#4a5568;margin-bottom:12px;}
    .tables{background:#f4f7fa;padding:16px;border-radius:8px;text-align:left;margin:16px 0;}
    .tables li{padding:4px 0;font-family:monospace;font-size:0.9rem;}
    .warn{color:#dc2626;font-weight:700;margin-top:20px;}
    a{color:#0047BB;font-weight:700;text-decoration:none;}</style></head>
    <body><div class='box'>
    <h2>CRM Setup Complete!</h2>
    <p>The following tables were created:</p>
    <ul class='tables'>
        <li>crm_clients &mdash; Client contact &amp; company info</li>
        <li>crm_projects &mdash; Project tracking with status/dates</li>
        <li>crm_tools &mdash; Tool/license expiry tracking</li>
    </ul>
    <p>You can now:</p>
    <p><a href='crm.php'>Manage Clients &rarr;</a></p>
    <p><a href='projects.php'>Manage Projects &rarr;</a></p>
    <p><a href='tools.php'>Manage Tools/Licenses &rarr;</a></p>
    <p class='warn'>DELETE this crm-setup.php file after running!</p>
    </div></body></html>";

} catch (Exception $e) {
    echo "<!DOCTYPE html><html><head><title>CRM Setup Error</title>
    <style>body{font-family:'DM Sans',sans-serif;display:flex;justify-content:center;align-items:center;min-height:100vh;background:#f4f7fa;margin:0;}
    .box{background:white;padding:48px;border-radius:16px;box-shadow:0 4px 24px rgba(0,0,0,0.08);max-width:500px;text-align:center;}
    h2{color:#dc2626;margin-bottom:16px;} p{color:#4a5568;}</style></head>
    <body><div class='box'>
    <h2>CRM Setup Error</h2>
    <p>" . htmlspecialchars($e->getMessage()) . "</p>
    <p style='margin-top:16px;font-size:0.85rem;color:#888;'>Check that config.php has the correct DB credentials.</p>
    </div></body></html>";
}
