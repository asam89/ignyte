<?php
/**
 * IGNYTE Consulting - One-time client import script
 * 
 * Imports the active client list into the CRM database.
 * Visit: https://www.ignyteconsulting.com/admin/import-clients.php
 * DELETE this file after running.
 */

require_once __DIR__ . '/config.php';

try {
    $pdo = getDB();

    $clients = [
        ['name' => 'Athletic Advantage', 'code' => 'AA', 'contact' => '', 'phone' => '647-277-4790', 'email' => '', 'env' => '', 'services' => ''],
        ['name' => 'Advent Legal', 'code' => 'ADL', 'contact' => '', 'phone' => '', 'email' => '', 'env' => '', 'services' => ''],
        ['name' => 'Addison Research', 'code' => 'ADR', 'contact' => '', 'phone' => '', 'email' => '', 'env' => '', 'services' => ''],
        ['name' => 'Airo Research', 'code' => 'AIR', 'contact' => '', 'phone' => '', 'email' => 'imran.khan@airoresearch.com', 'env' => '', 'services' => ''],
        ['name' => 'Affinity Law', 'code' => 'AL', 'contact' => '', 'phone' => '', 'email' => '', 'env' => '', 'services' => ''],
        ['name' => 'AmeerLaw', 'code' => 'AML', 'contact' => '', 'phone' => '', 'email' => '', 'env' => '', 'services' => ''],
        ['name' => 'ArchstoneLaw', 'code' => 'ARC', 'contact' => '', 'phone' => '', 'email' => '', 'env' => '', 'services' => ''],
        ['name' => 'Care Campus Clinic', 'code' => 'CARE', 'contact' => 'Saifuddin Syed', 'phone' => '647-262-5861', 'email' => 'care@campus.clinic', 'env' => '', 'services' => ''],
        ['name' => 'CASSA', 'code' => 'CAS', 'contact' => '', 'phone' => '', 'email' => '', 'env' => '', 'services' => ''],
        ['name' => 'Canadian Cricket Club', 'code' => 'CCC', 'contact' => '', 'phone' => '', 'email' => '', 'env' => '', 'services' => ''],
        ['name' => 'Connect Research', 'code' => 'CR', 'contact' => '', 'phone' => '', 'email' => '', 'env' => '', 'services' => ''],
        ['name' => 'Durham Circumcision Clinic', 'code' => 'DCC', 'contact' => '', 'phone' => '', 'email' => '', 'env' => '', 'services' => ''],
        ['name' => 'Dexa.me', 'code' => 'DEX', 'contact' => '', 'phone' => '', 'email' => '', 'env' => '', 'services' => ''],
        ['name' => 'ENet-ITSolutions', 'code' => 'ENS', 'contact' => '', 'phone' => '', 'email' => '', 'env' => '', 'services' => ''],
        ['name' => 'GC Boyle', 'code' => 'GCB', 'contact' => '', 'phone' => '', 'email' => '', 'env' => '', 'services' => ''],
        ['name' => 'Human Concern International', 'code' => 'HCI', 'contact' => '', 'phone' => '', 'email' => '', 'env' => '', 'services' => ''],
        ['name' => 'Humaniti', 'code' => 'HUM', 'contact' => '', 'phone' => '', 'email' => '', 'env' => '', 'services' => ''],
        ['name' => 'Isaac Operations', 'code' => 'ISC', 'contact' => 'Daniel Power', 'phone' => '', 'email' => '', 'env' => 'Windows', 'services' => 'M365, Intune, Entra, Exchange, SharePoint, Dropbox, Monday.com, Dynamics 365'],
        ['name' => 'InfoSec', 'code' => 'ISG', 'contact' => '', 'phone' => '', 'email' => '', 'env' => '', 'services' => ''],
        ['name' => 'JusticeForSoli', 'code' => 'JFS', 'contact' => '', 'phone' => '', 'email' => '', 'env' => '', 'services' => ''],
        ['name' => 'KN Law', 'code' => 'KNL', 'contact' => '', 'phone' => '', 'email' => '', 'env' => '', 'services' => ''],
        ['name' => 'Lawrence Medical Radiology Clinic', 'code' => 'LMRC', 'contact' => '', 'phone' => '', 'email' => '', 'env' => '', 'services' => ''],
        ['name' => 'Newcastle Urgent Care Clinic', 'code' => 'NCC', 'contact' => '', 'phone' => '', 'email' => '', 'env' => '', 'services' => ''],
        ['name' => 'National Council of Canadian Muslims', 'code' => 'NCCM', 'contact' => '', 'phone' => '', 'email' => '', 'env' => '', 'services' => ''],
        ['name' => 'OneStop Medical', 'code' => 'OSM', 'contact' => '', 'phone' => '', 'email' => '', 'env' => '', 'services' => ''],
        ['name' => 'PrintPros', 'code' => 'PP', 'contact' => '', 'phone' => '', 'email' => '', 'env' => '', 'services' => ''],
        ['name' => 'QC Law Firm', 'code' => 'QC', 'contact' => '', 'phone' => '', 'email' => '', 'env' => '', 'services' => ''],
        ['name' => 'QuranSpeak', 'code' => 'QS', 'contact' => '', 'phone' => '', 'email' => '', 'env' => '', 'services' => ''],
        ['name' => 'Rosedale Medical', 'code' => 'RDM', 'contact' => '', 'phone' => '', 'email' => '', 'env' => '', 'services' => ''],
        ['name' => 'Ruma Law', 'code' => 'RL', 'contact' => '', 'phone' => '', 'email' => '', 'env' => '', 'services' => ''],
        ['name' => 'Rossland Radiology Clinic', 'code' => 'RRC', 'contact' => '', 'phone' => '', 'email' => '', 'env' => '', 'services' => ''],
        ['name' => 'SidhuLegal', 'code' => 'SL', 'contact' => '', 'phone' => '', 'email' => '', 'env' => '', 'services' => ''],
        ['name' => 'StrikerPower', 'code' => 'SP', 'contact' => '', 'phone' => '', 'email' => '', 'env' => '', 'services' => ''],
        ['name' => 'TownCare Medical', 'code' => 'TCM', 'contact' => '', 'phone' => '', 'email' => '', 'env' => '', 'services' => ''],
        ['name' => 'TX Bedding', 'code' => 'TXB', 'contact' => '', 'phone' => '', 'email' => '', 'env' => '', 'services' => ''],
        ['name' => 'United Glass', 'code' => 'UG', 'contact' => '', 'phone' => '', 'email' => '', 'env' => '', 'services' => ''],
    ];

    $stmt = $pdo->prepare('INSERT INTO crm_clients (full_name, email, phone, company_name, industry, address, notes, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $checkStmt = $pdo->prepare('SELECT id FROM crm_clients WHERE company_name = ?');

    $imported = 0;
    $skipped = 0;
    $results = [];

    foreach ($clients as $c) {
        // Skip if company already exists
        $checkStmt->execute([$c['name']]);
        if ($checkStmt->fetch()) {
            $skipped++;
            $results[] = ['name' => $c['name'], 'status' => 'skipped (already exists)'];
            continue;
        }

        // Build notes from available info
        $notesParts = [];
        $notesParts[] = "Client Code: {$c['code']}";
        if ($c['contact']) $notesParts[] = "Primary Contact: {$c['contact']}";
        if ($c['env']) $notesParts[] = "Environment: {$c['env']}";
        if ($c['services']) $notesParts[] = "Key Services: {$c['services']}";
        $notes = implode("\n", $notesParts);

        // Use primary contact name as full_name if available, otherwise company name
        $fullName = $c['contact'] ?: $c['name'];

        $stmt->execute([
            $fullName,
            $c['email'],
            $c['phone'],
            $c['name'],  // company_name
            '',          // industry
            '',          // address
            $notes,
            'active'
        ]);
        $imported++;
        $results[] = ['name' => $c['name'], 'status' => 'imported'];
    }

    // Output results
    echo "<!DOCTYPE html><html><head><title>Client Import Results</title>
    <style>
        body{font-family:'DM Sans',sans-serif;display:flex;justify-content:center;align-items:flex-start;min-height:100vh;background:#f4f7fa;margin:0;padding:32px;}
        .box{background:white;padding:48px;border-radius:16px;box-shadow:0 4px 24px rgba(0,0,0,0.08);max-width:700px;width:100%;}
        h2{color:#002366;margin-bottom:8px;} .summary{color:#4a5568;margin-bottom:20px;font-size:1.05rem;}
        table{width:100%;border-collapse:collapse;margin:16px 0;} th,td{text-align:left;padding:8px 12px;border-bottom:1px solid #e8ecf1;font-size:0.9rem;}
        th{background:#f4f7fa;color:#002366;font-weight:600;} .imported{color:#059669;font-weight:600;} .skipped{color:#d97706;font-weight:600;}
        .warn{color:#dc2626;font-weight:700;margin-top:20px;text-align:center;} a{color:#0047BB;font-weight:700;text-decoration:none;}
    </style></head><body><div class='box'>
    <h2>Client Import Complete</h2>
    <p class='summary'>Imported <strong>{$imported}</strong> client(s). Skipped <strong>{$skipped}</strong> (already in database).</p>
    <table><thead><tr><th>#</th><th>Client</th><th>Result</th></tr></thead><tbody>";

    foreach ($results as $i => $r) {
        $statusClass = $r['status'] === 'imported' ? 'imported' : 'skipped';
        echo "<tr><td>" . ($i + 1) . "</td><td>" . htmlspecialchars($r['name']) . "</td><td class='{$statusClass}'>" . htmlspecialchars($r['status']) . "</td></tr>";
    }

    echo "</tbody></table>
    <p style='text-align:center;margin-top:20px;'><a href='crm.php'>View CRM &rarr;</a></p>
    <p class='warn'>DELETE this import-clients.php file after running!</p>
    </div></body></html>";

} catch (Exception $e) {
    echo "<!DOCTYPE html><html><head><title>Import Error</title>
    <style>body{font-family:'DM Sans',sans-serif;display:flex;justify-content:center;align-items:center;min-height:100vh;background:#f4f7fa;margin:0;}
    .box{background:white;padding:48px;border-radius:16px;box-shadow:0 4px 24px rgba(0,0,0,0.08);max-width:500px;text-align:center;}
    h2{color:#dc2626;margin-bottom:16px;} p{color:#4a5568;}</style></head>
    <body><div class='box'>
    <h2>Import Error</h2>
    <p>" . htmlspecialchars($e->getMessage()) . "</p>
    </div></body></html>";
}
