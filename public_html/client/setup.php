<?php
/**
 * IGNYTE Consulting - Client Portal Database Setup
 * 
 * Run this ONCE to create the client portal tables.
 * Visit: https://www.ignyteconsulting.com/client/setup.php
 * After running, DELETE this file from the server for security.
 */

require_once __DIR__ . '/../admin/config.php';

try {
    $pdo = getDB();

    // Create clients table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS clients (
            id INT AUTO_INCREMENT PRIMARY KEY,
            full_name VARCHAR(100) NOT NULL,
            email VARCHAR(255) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            company_name VARCHAR(150) DEFAULT NULL,
            phone VARCHAR(30) DEFAULT NULL,
            status ENUM('pending','active','suspended') DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            last_login TIMESTAMP NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Create client_projects table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS client_projects (
            id INT AUTO_INCREMENT PRIMARY KEY,
            client_id INT NOT NULL,
            project_name VARCHAR(255) NOT NULL,
            description TEXT DEFAULT NULL,
            status ENUM('active','completed','on-hold') DEFAULT 'active',
            start_date DATE DEFAULT NULL,
            end_date DATE DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Create client_documents table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS client_documents (
            id INT AUTO_INCREMENT PRIMARY KEY,
            client_id INT NOT NULL,
            project_id INT DEFAULT NULL,
            document_name VARCHAR(255) NOT NULL,
            document_type VARCHAR(50) DEFAULT 'File',
            file_url VARCHAR(500) DEFAULT NULL,
            description TEXT DEFAULT NULL,
            uploaded_by VARCHAR(100) DEFAULT 'IGNYTE Team',
            uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
            FOREIGN KEY (project_id) REFERENCES client_projects(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Create client_invoices table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS client_invoices (
            id INT AUTO_INCREMENT PRIMARY KEY,
            client_id INT NOT NULL,
            invoice_number VARCHAR(50) NOT NULL,
            description VARCHAR(255) DEFAULT NULL,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            status ENUM('pending','sent','paid','overdue') DEFAULT 'pending',
            due_date DATE DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    echo "<!DOCTYPE html><html><head><title>Client Portal Setup</title>
    <style>body{font-family:'DM Sans',sans-serif;display:flex;justify-content:center;align-items:center;min-height:100vh;background:#f4f7fa;margin:0;}
    .box{background:white;padding:48px;border-radius:16px;box-shadow:0 4px 24px rgba(0,0,0,0.08);max-width:520px;text-align:center;}
    h2{color:#002366;margin-bottom:16px;} p{color:#4a5568;margin-bottom:12px;}
    .tables{background:#f4f7fa;padding:16px;border-radius:8px;text-align:left;font-size:0.9rem;margin:16px 0;}
    .tables li{padding:4px 0;color:#4a5568;}
    .warn{color:#dc2626;font-weight:700;margin-top:20px;}</style></head>
    <body><div class='box'>
    <h2>Client Portal Setup Complete!</h2>
    <p>The following tables were created:</p>
    <div class='tables'><ul>
        <li><strong>clients</strong> &mdash; Client user accounts</li>
        <li><strong>client_projects</strong> &mdash; Project tracking</li>
        <li><strong>client_documents</strong> &mdash; Shared documents &amp; deliverables</li>
        <li><strong>client_invoices</strong> &mdash; Invoicing &amp; billing</li>
    </ul></div>
    <p>Clients can now register at <code>/client/register.php</code> and you can approve them via the database.</p>
    <p class='warn'>IMPORTANT: Delete this setup.php file after running!</p>
    <p><a href='login.php' style='color:#0047BB;font-weight:700;'>Go to Client Login &rarr;</a></p>
    </div></body></html>";

} catch (Exception $e) {
    echo "Setup failed: " . htmlspecialchars($e->getMessage());
}
