<?php
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mailchimp.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$pdo = getDB();
$adminName = htmlspecialchars($_SESSION['admin_name']);
$mailchimp = new MailchimpAPI();
$mcConfigured = $mailchimp->isConfigured();

// Handle add/edit CRM client
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crm_action'])) {
    $action = $_POST['crm_action'];
    $fullName = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $company = trim($_POST['company_name'] ?? '');
    $industry = trim($_POST['industry'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $clientStatus = $_POST['client_status'] ?? 'active';

    if ($fullName && $email) {
        if ($action === 'add') {
            $stmt = $pdo->prepare('INSERT INTO crm_clients (full_name, email, phone, company_name, industry, address, notes, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$fullName, $email, $phone, $company, $industry, $address, $notes, $clientStatus]);
            $newId = $pdo->lastInsertId();
            // Auto-sync to Mailchimp
            if ($mcConfigured) {
                $syncResult = $mailchimp->syncClient(['full_name' => $fullName, 'email' => $email, 'phone' => $phone, 'company_name' => $company, 'status' => $clientStatus]);
                if ($syncResult['success']) {
                    $pdo->prepare('UPDATE crm_clients SET mailchimp_synced = NOW() WHERE id = ?')->execute([$newId]);
                }
            }
            header('Location: crm.php?added=1');
            exit;
        } elseif ($action === 'update' && isset($_POST['client_id'])) {
            $stmt = $pdo->prepare('UPDATE crm_clients SET full_name=?, email=?, phone=?, company_name=?, industry=?, address=?, notes=?, status=? WHERE id=?');
            $stmt->execute([$fullName, $email, $phone, $company, $industry, $address, $notes, $clientStatus, $_POST['client_id']]);
            // Auto-sync to Mailchimp
            if ($mcConfigured) {
                $syncResult = $mailchimp->syncClient(['full_name' => $fullName, 'email' => $email, 'phone' => $phone, 'company_name' => $company, 'status' => $clientStatus]);
                if ($syncResult['success']) {
                    $pdo->prepare('UPDATE crm_clients SET mailchimp_synced = NOW() WHERE id = ?')->execute([$_POST['client_id']]);
                }
            }
            header('Location: crm.php?updated=1');
            exit;
        }
    }
}

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_client'])) {
    // Remove from Mailchimp too
    if ($mcConfigured) {
        $delClient = $pdo->prepare('SELECT email FROM crm_clients WHERE id = ?');
        $delClient->execute([$_POST['delete_client']]);
        $delEmail = $delClient->fetchColumn();
        if ($delEmail) $mailchimp->removeClient($delEmail);
    }
    $pdo->prepare('DELETE FROM crm_clients WHERE id = ?')->execute([$_POST['delete_client']]);
    header('Location: crm.php?deleted=1');
    exit;
}

// Handle bulk Mailchimp sync
$syncMessage = '';
$syncError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mailchimp_bulk_sync'])) {
    if ($mcConfigured) {
        $result = $mailchimp->syncAllClients($pdo);
        if ($result['success']) {
            $syncMessage = "Mailchimp sync complete: {$result['synced']} synced, {$result['failed']} failed, {$result['skipped']} skipped.";
            if (!empty($result['errors'])) {
                $syncError = implode('; ', array_slice($result['errors'], 0, 5));
            }
        } else {
            $syncError = $result['error'] ?? 'Sync failed';
        }
        // Re-fetch clients to show updated sync times
        $allClients = $pdo->query('SELECT * FROM crm_clients ORDER BY full_name ASC')->fetchAll();
        $activeClients = array_filter($allClients, function($c) { return $c['status'] === 'active'; });
    } else {
        $syncError = 'Mailchimp is not configured. Edit config.php on Hostinger with your API Key and Audience ID.';
    }
}

// Handle single client sync
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sync_client_id'])) {
    if ($mcConfigured) {
        $stmt = $pdo->prepare('SELECT * FROM crm_clients WHERE id = ?');
        $stmt->execute([$_POST['sync_client_id']]);
        $syncClient = $stmt->fetch();
        if ($syncClient) {
            $result = $mailchimp->syncClient($syncClient);
            if ($result['success']) {
                $pdo->prepare('UPDATE crm_clients SET mailchimp_synced = NOW() WHERE id = ?')->execute([$syncClient['id']]);
                $syncMessage = htmlspecialchars($syncClient['full_name']) . ' synced to Mailchimp.';
            } else {
                $syncError = 'Failed to sync ' . htmlspecialchars($syncClient['full_name']) . ': ' . ($result['detail'] ?? $result['error'] ?? 'Unknown error');
            }
        }
    }
}

// Handle CSV import
$importMessage = '';
$importError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_csv'])) {
    if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
        $file = fopen($_FILES['csv_file']['tmp_name'], 'r');
        $header = fgetcsv($file);
        if (!$header) {
            $importError = 'CSV file is empty or invalid.';
        } else {
            // Normalize headers (lowercase, trim)
            $header = array_map(function($h) { return strtolower(trim($h)); }, $header);

            // Map common CSV column names to our DB fields
            $colMap = [
                'full_name' => ['full_name', 'full name', 'name', 'contact name', 'client name'],
                'email' => ['email', 'email address', 'e-mail', 'mail'],
                'phone' => ['phone', 'phone number', 'telephone', 'mobile', 'cell'],
                'company_name' => ['company', 'company_name', 'company name', 'organization', 'org'],
                'industry' => ['industry', 'sector', 'field'],
                'address' => ['address', 'location', 'city', 'full address'],
                'notes' => ['notes', 'note', 'comments', 'description'],
                'first_name' => ['first name', 'first_name', 'firstname', 'given name'],
                'last_name' => ['last name', 'last_name', 'lastname', 'surname', 'family name'],
            ];

            $indexes = [];
            foreach ($colMap as $field => $aliases) {
                foreach ($aliases as $alias) {
                    $idx = array_search($alias, $header);
                    if ($idx !== false) {
                        $indexes[$field] = $idx;
                        break;
                    }
                }
            }

            if (!isset($indexes['email']) && !isset($indexes['full_name'])) {
                $importError = 'CSV must have at least an "Email" or "Name" column. Found columns: ' . implode(', ', $header);
            } else {
                $imported = 0;
                $skipped = 0;
                $defaultStatus = $_POST['import_status'] ?? 'active';

                $stmt = $pdo->prepare('INSERT INTO crm_clients (full_name, email, phone, company_name, industry, address, notes, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');

                while (($row = fgetcsv($file)) !== false) {
                    if (empty(array_filter($row))) continue; // skip empty rows

                    // Build full name from first+last if no full_name column
                    $fullName = '';
                    if (isset($indexes['full_name'])) {
                        $fullName = trim($row[$indexes['full_name']] ?? '');
                    }
                    if (!$fullName && (isset($indexes['first_name']) || isset($indexes['last_name']))) {
                        $first = trim($row[$indexes['first_name'] ?? -1] ?? '');
                        $last = trim($row[$indexes['last_name'] ?? -1] ?? '');
                        $fullName = trim("$first $last");
                    }

                    $email = trim($row[$indexes['email'] ?? -1] ?? '');

                    if (!$fullName && !$email) {
                        $skipped++;
                        continue;
                    }
                    if (!$fullName) $fullName = explode('@', $email)[0];

                    // Skip duplicates by email
                    if ($email) {
                        $check = $pdo->prepare('SELECT id FROM crm_clients WHERE email = ?');
                        $check->execute([$email]);
                        if ($check->fetch()) {
                            $skipped++;
                            continue;
                        }
                    }

                    $phone = trim($row[$indexes['phone'] ?? -1] ?? '');
                    $company = trim($row[$indexes['company_name'] ?? -1] ?? '');
                    $industry = trim($row[$indexes['industry'] ?? -1] ?? '');
                    $address = trim($row[$indexes['address'] ?? -1] ?? '');
                    $notes = trim($row[$indexes['notes'] ?? -1] ?? '');

                    $stmt->execute([$fullName, $email, $phone, $company, $industry, $address, $notes, $defaultStatus]);
                    $imported++;
                }

                $importMessage = "Imported $imported client(s).";
                if ($skipped > 0) $importMessage .= " Skipped $skipped (duplicates or missing data).";
            }
        }
        fclose($file);
    } else {
        $importError = 'Please select a CSV file to import.';
    }
    // Re-fetch clients after import
    $allClients = $pdo->query('SELECT * FROM crm_clients ORDER BY full_name ASC')->fetchAll();
    $activeClients = array_filter($allClients, function($c) { return $c['status'] === 'active'; });
}

// Handle CSV export for Mailchimp
if (isset($_GET['export']) && $_GET['export'] === 'mailchimp') {
    $clients = $pdo->query('SELECT full_name, email, phone, company_name, industry, address FROM crm_clients WHERE status = "active" ORDER BY full_name ASC')->fetchAll();
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="ignyte-clients-mailchimp-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Email Address', 'First Name', 'Last Name', 'Phone', 'Company', 'Industry', 'Address']);
    foreach ($clients as $c) {
        $nameParts = explode(' ', $c['full_name'], 2);
        $firstName = $nameParts[0];
        $lastName = $nameParts[1] ?? '';
        fputcsv($out, [$c['email'], $firstName, $lastName, $c['phone'], $c['company_name'], $c['industry'], $c['address']]);
    }
    fclose($out);
    exit;
}

// Fetch all CRM clients
$allClients = $pdo->query('SELECT * FROM crm_clients ORDER BY full_name ASC')->fetchAll();
$activeClients = array_filter($allClients, function($c) { return $c['status'] === 'active'; });

// If editing
$editClient = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM crm_clients WHERE id = ?');
    $stmt->execute([$_GET['edit']]);
    $editClient = $stmt->fetch();
}

// Filter
$filterStatus = $_GET['status'] ?? 'all';
$displayClients = $allClients;
if ($filterStatus !== 'all') {
    $displayClients = array_filter($allClients, function($c) use ($filterStatus) { return $c['status'] === $filterStatus; });
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CRM - Client Management | IGNYTE Consulting</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Inter:wght@600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --navy: #002366;
            --brand-blue: #0047BB;
            --electric: #007BFF;
            --flame-orange: #EE5A24;
            --slate: #4A5568;
            --light-grey: #F4F7FA;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'DM Sans', sans-serif; background: var(--light-grey); color: var(--navy); }

        .topbar {
            background: var(--navy); color: white; padding: 14px 28px;
            display: flex; justify-content: space-between; align-items: center;
            position: sticky; top: 0; z-index: 100;
        }
        .topbar-left { display: flex; align-items: center; gap: 16px; }
        .topbar-left img { height: 36px; filter: brightness(0) invert(1); }
        .topbar-left h2 { font-family: 'Inter', sans-serif; font-size: 1.1rem; font-weight: 800; }
        .topbar-right { display: flex; align-items: center; gap: 12px; font-size: 0.85rem; flex-wrap: wrap; }
        .topbar-right a {
            color: rgba(255,255,255,0.7); text-decoration: none; font-weight: 600;
            padding: 6px 14px; border-radius: 6px; transition: all 0.2s;
        }
        .topbar-right a:hover { background: rgba(255,255,255,0.1); color: white; }
        .topbar-right .active-nav { background: rgba(255,255,255,0.15); color: white; }
        .topbar-right .logout-btn { background: rgba(238,90,36,0.2); color: #ff8c42; }
        .topbar-right .logout-btn:hover { background: var(--flame-orange); color: white; }

        .dashboard { max-width: 1200px; margin: 0 auto; padding: 32px 28px; }

        .stats-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 32px; }
        .stat-box {
            background: white; padding: 20px 24px; border-radius: 14px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.04);
        }
        .stat-box .num { font-family: 'Inter', sans-serif; font-size: 1.8rem; font-weight: 800; color: var(--navy); }
        .stat-box .label { font-size: 0.82rem; color: var(--slate); margin-top: 4px; }

        .alert {
            padding: 12px 18px; border-radius: 10px; margin-bottom: 24px;
            font-size: 0.9rem; font-weight: 600;
        }
        .alert-success { background: rgba(34,197,94,0.1); color: #16a34a; border: 1px solid rgba(34,197,94,0.2); }

        .card {
            background: white; border-radius: 16px;
            box-shadow: 0 2px 16px rgba(0,0,0,0.05);
            padding: 32px; margin-bottom: 32px;
        }
        .card h3 { font-family: 'Inter', sans-serif; font-size: 1.3rem; margin-bottom: 24px; color: var(--navy); }
        .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 12px; }
        .card-header h3 { margin-bottom: 0; }

        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .form-group { margin-bottom: 16px; }
        .form-group.full { grid-column: 1 / -1; }
        .form-group label {
            display: block; font-weight: 600; font-size: 0.85rem;
            color: var(--navy); margin-bottom: 6px;
        }
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%; padding: 10px 14px;
            border: 1.5px solid rgba(0,0,0,0.1); border-radius: 8px;
            font-size: 0.92rem; font-family: 'DM Sans', sans-serif;
            outline: none; transition: border-color 0.2s;
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus { border-color: var(--brand-blue); }
        .form-group textarea { min-height: 100px; resize: vertical; line-height: 1.7; }

        .form-actions { display: flex; gap: 12px; margin-top: 8px; }
        .btn {
            padding: 10px 24px; border: none; border-radius: 8px;
            font-size: 0.9rem; font-weight: 700; font-family: 'DM Sans', sans-serif;
            cursor: pointer; transition: all 0.2s; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;
        }
        .btn-save { background: var(--flame-orange); color: white; box-shadow: 0 2px 12px rgba(238,90,36,0.3); }
        .btn-save:hover { background: var(--navy); }
        .btn-cancel { background: transparent; color: var(--slate); }
        .btn-cancel:hover { color: var(--navy); }
        .btn-export { background: var(--brand-blue); color: white; }
        .btn-export:hover { background: var(--navy); }
        .btn-small { padding: 6px 14px; font-size: 0.82rem; }

        .filter-bar { display: flex; gap: 8px; flex-wrap: wrap; }
        .filter-btn {
            padding: 6px 16px; border-radius: 50px; font-size: 0.82rem;
            font-weight: 600; border: 1.5px solid rgba(0,0,0,0.1);
            background: white; color: var(--slate); cursor: pointer;
            text-decoration: none; transition: all 0.2s;
        }
        .filter-btn:hover { border-color: var(--brand-blue); color: var(--brand-blue); }
        .filter-btn.active { background: var(--navy); color: white; border-color: var(--navy); }

        .clients-table { width: 100%; border-collapse: collapse; }
        .clients-table th {
            text-align: left; font-size: 0.78rem; text-transform: uppercase;
            letter-spacing: 0.06em; color: var(--slate); padding: 12px 14px;
            border-bottom: 2px solid var(--light-grey);
        }
        .clients-table td {
            padding: 14px; border-bottom: 1px solid rgba(0,0,0,0.04);
            font-size: 0.88rem; vertical-align: middle;
        }
        .clients-table tr:hover td { background: rgba(0,71,187,0.02); }
        .client-name { font-weight: 600; color: var(--navy); }
        .client-company { font-size: 0.8rem; color: var(--slate); }
        .badge {
            display: inline-block; padding: 3px 10px; border-radius: 50px;
            font-size: 0.72rem; font-weight: 700; text-transform: uppercase;
        }
        .badge-active { background: rgba(34,197,94,0.1); color: #16a34a; }
        .badge-inactive { background: rgba(220,38,38,0.08); color: #dc2626; }
        .badge-prospect { background: rgba(0,71,187,0.1); color: var(--brand-blue); }

        .action-btns { display: flex; gap: 6px; }
        .action-btns a, .action-btns button {
            padding: 5px 12px; border-radius: 6px; font-size: 0.8rem;
            font-weight: 600; text-decoration: none; cursor: pointer;
            border: none; font-family: 'DM Sans', sans-serif; transition: all 0.2s;
        }
        .btn-edit { background: rgba(0,71,187,0.1); color: var(--brand-blue); }
        .btn-edit:hover { background: var(--brand-blue); color: white; }
        .btn-delete { background: rgba(220,38,38,0.08); color: #dc2626; }
        .btn-delete:hover { background: #dc2626; color: white; }

        .empty-state { text-align: center; padding: 60px 20px; color: var(--slate); }
        .empty-state p { font-size: 1.1rem; margin-bottom: 8px; }
        .empty-state span { font-size: 3rem; display: block; margin-bottom: 16px; }

        @media (max-width: 768px) {
            .stats-row { grid-template-columns: 1fr 1fr; }
            .form-grid { grid-template-columns: 1fr; }
            .topbar { flex-direction: column; gap: 10px; text-align: center; }
            .clients-table th:nth-child(3), .clients-table td:nth-child(3),
            .clients-table th:nth-child(5), .clients-table td:nth-child(5) { display: none; }
        }
    </style>
</head>
<body>

<div class="topbar">
    <div class="topbar-left">
        <img src="../logo.png" alt="IGNYTE">
        <h2>CRM - Clients</h2>
    </div>
    <div class="topbar-right">
        <span>Welcome, <?php echo $adminName; ?></span>
        <a href="dashboard.php">Blog</a>
        <a href="crm.php" class="active-nav">Clients</a>
        <a href="projects.php">Projects</a>
        <a href="tools.php">Tools/Licenses</a>
        <a href="../index.html">View Site</a>
        <a href="logout.php" class="logout-btn">Logout</a>
    </div>
</div>

<div class="dashboard">

    <?php if (isset($_GET['added'])): ?>
        <div class="alert alert-success">Client added successfully!</div>
    <?php elseif (isset($_GET['updated'])): ?>
        <div class="alert alert-success">Client updated successfully!</div>
    <?php elseif (isset($_GET['deleted'])): ?>
        <div class="alert alert-success">Client deleted.</div>
    <?php endif; ?>
    <?php if ($importMessage): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($importMessage); ?></div>
    <?php endif; ?>
    <?php if ($importError): ?>
        <div class="alert" style="background:rgba(220,38,38,0.08);color:#dc2626;border:1px solid rgba(220,38,38,0.2);"><?php echo htmlspecialchars($importError); ?></div>
    <?php endif; ?>
    <?php if ($syncMessage): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($syncMessage); ?></div>
    <?php endif; ?>
    <?php if ($syncError): ?>
        <div class="alert" style="background:rgba(220,38,38,0.08);color:#dc2626;border:1px solid rgba(220,38,38,0.2);"><?php echo htmlspecialchars($syncError); ?></div>
    <?php endif; ?>

    <!-- Stats -->
    <?php
    $totalCRM = count($allClients);
    $activeCRM = count($activeClients);
    $inactiveCRM = count(array_filter($allClients, function($c) { return $c['status'] === 'inactive'; }));
    $prospectCRM = count(array_filter($allClients, function($c) { return $c['status'] === 'prospect'; }));
    $syncedCount = count(array_filter($allClients, function($c) { return !empty($c['mailchimp_synced']); }));
    ?>
    <div class="stats-row">
        <div class="stat-box"><div class="num"><?php echo $totalCRM; ?></div><div class="label">Total Clients</div></div>
        <div class="stat-box"><div class="num"><?php echo $activeCRM; ?></div><div class="label">Active</div></div>
        <div class="stat-box"><div class="num"><?php echo $prospectCRM; ?></div><div class="label">Prospects</div></div>
        <div class="stat-box">
            <div class="num" style="<?php echo $mcConfigured ? 'color:#16a34a' : 'color:var(--slate)'; ?>"><?php echo $syncedCount; ?>/<?php echo $totalCRM; ?></div>
            <div class="label">Synced to Mailchimp</div>
        </div>
    </div>

    <!-- Mailchimp Integration -->
    <div class="card" style="border-left:4px solid <?php echo $mcConfigured ? '#16a34a' : '#f59e0b'; ?>;">
        <div class="card-header">
            <h3>Mailchimp Integration</h3>
            <?php if ($mcConfigured): ?>
                <span class="badge badge-active">Connected</span>
            <?php else: ?>
                <span class="badge" style="background:rgba(245,158,11,0.1);color:#f59e0b;">Not Configured</span>
            <?php endif; ?>
        </div>
        <?php if ($mcConfigured): ?>
            <p style="font-size:0.88rem;color:var(--slate);margin-bottom:16px;">
                Clients are auto-synced to Mailchimp when you add or edit them. Use "Sync All" to push everyone, or sync individual clients from the table below.
            </p>
            <div style="display:flex;gap:12px;flex-wrap:wrap;">
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="mailchimp_bulk_sync" value="1">
                    <button type="submit" class="btn btn-save" onclick="this.disabled=true;this.innerText='Syncing...';this.form.submit();">Sync All Clients to Mailchimp</button>
                </form>
                <a href="crm.php?export=mailchimp" class="btn btn-export">Export CSV for Mailchimp</a>
            </div>
        <?php else: ?>
            <p style="font-size:0.88rem;color:var(--slate);margin-bottom:12px;">
                To enable direct Mailchimp sync, add your API Key and Audience ID to <code>config.php</code> on Hostinger:
            </p>
            <pre style="background:var(--light-grey);padding:14px;border-radius:8px;font-size:0.82rem;overflow-x:auto;">define('MAILCHIMP_API_KEY', 'your-api-key-here');
define('MAILCHIMP_AUDIENCE_ID', 'your-audience-id');</pre>
            <p style="font-size:0.82rem;color:var(--slate);margin-top:12px;">
                Get your API key: Mailchimp &rarr; Account &rarr; Extras &rarr; API Keys<br>
                Get your Audience ID: Mailchimp &rarr; Audience &rarr; Settings &rarr; Audience name and defaults
            </p>
            <a href="crm.php?export=mailchimp" class="btn btn-export" style="margin-top:12px;">Export CSV for Mailchimp (manual)</a>
        <?php endif; ?>
    </div>

    <!-- Add / Edit Client -->
    <div class="card">
        <h3><?php echo $editClient ? 'Edit Client' : 'Add New Client'; ?></h3>
        <form method="POST">
            <input type="hidden" name="crm_action" value="<?php echo $editClient ? 'update' : 'add'; ?>">
            <?php if ($editClient): ?>
                <input type="hidden" name="client_id" value="<?php echo $editClient['id']; ?>">
            <?php endif; ?>

            <div class="form-grid">
                <div class="form-group">
                    <label>Full Name *</label>
                    <input type="text" name="full_name" required value="<?php echo htmlspecialchars($editClient['full_name'] ?? ''); ?>" placeholder="John Smith">
                </div>
                <div class="form-group">
                    <label>Email *</label>
                    <input type="email" name="email" required value="<?php echo htmlspecialchars($editClient['email'] ?? ''); ?>" placeholder="john@company.com">
                </div>
                <div class="form-group">
                    <label>Phone</label>
                    <input type="text" name="phone" value="<?php echo htmlspecialchars($editClient['phone'] ?? ''); ?>" placeholder="+1 (555) 000-0000">
                </div>
                <div class="form-group">
                    <label>Company</label>
                    <input type="text" name="company_name" value="<?php echo htmlspecialchars($editClient['company_name'] ?? ''); ?>" placeholder="Acme Corp">
                </div>
                <div class="form-group">
                    <label>Industry</label>
                    <input type="text" name="industry" value="<?php echo htmlspecialchars($editClient['industry'] ?? ''); ?>" placeholder="Technology, Healthcare, Finance...">
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select name="client_status">
                        <option value="active" <?php echo (($editClient['status'] ?? 'active') === 'active') ? 'selected' : ''; ?>>Active</option>
                        <option value="prospect" <?php echo (($editClient['status'] ?? '') === 'prospect') ? 'selected' : ''; ?>>Prospect</option>
                        <option value="inactive" <?php echo (($editClient['status'] ?? '') === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>
                <div class="form-group full">
                    <label>Address</label>
                    <input type="text" name="address" value="<?php echo htmlspecialchars($editClient['address'] ?? ''); ?>" placeholder="123 Main St, City, State">
                </div>
                <div class="form-group full">
                    <label>Notes</label>
                    <textarea name="notes" placeholder="Internal notes about this client..."><?php echo htmlspecialchars($editClient['notes'] ?? ''); ?></textarea>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-save"><?php echo $editClient ? 'Update Client' : 'Add Client'; ?></button>
                <?php if ($editClient): ?>
                    <a href="crm.php" class="btn btn-cancel">Cancel</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Import Clients -->
    <div class="card">
        <h3>Import Clients from CSV</h3>
        <p style="font-size:0.88rem;color:var(--slate);margin-bottom:16px;">
            Upload a CSV file to bulk-import clients. Works with exports from <strong>Gmail Contacts</strong>, <strong>Mailchimp</strong>, <strong>Outlook</strong>, or any spreadsheet.
            The importer auto-detects columns like Email, Name, First Name, Last Name, Phone, Company, etc. Duplicate emails are skipped.
        </p>
        <details style="margin-bottom:12px;">
            <summary style="font-size:0.85rem;font-weight:600;color:var(--brand-blue);cursor:pointer;">How to export contacts from Gmail</summary>
            <ol style="font-size:0.85rem;color:var(--slate);padding-left:20px;margin-top:8px;line-height:1.8;">
                <li>Go to <a href="https://contacts.google.com" target="_blank" style="color:var(--brand-blue);">contacts.google.com</a></li>
                <li>Select the contacts you want to export (or select all)</li>
                <li>Click the <strong>Export</strong> icon (or Menu → Export)</li>
                <li>Choose <strong>"Google CSV"</strong> format</li>
                <li>Click <strong>Export</strong> and save the file</li>
                <li>Upload that file here</li>
            </ol>
        </details>
        <form method="POST" enctype="multipart/form-data" style="display:flex;gap:12px;align-items:end;flex-wrap:wrap;">
            <input type="hidden" name="import_csv" value="1">
            <div class="form-group" style="margin-bottom:0;flex:1;min-width:200px;">
                <label>CSV File</label>
                <input type="file" name="csv_file" accept=".csv,.txt" required style="padding:8px;">
            </div>
            <div class="form-group" style="margin-bottom:0;min-width:140px;">
                <label>Default Status</label>
                <select name="import_status">
                    <option value="active">Active</option>
                    <option value="prospect" selected>Prospect</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
            <button type="submit" class="btn btn-save" style="margin-bottom:0;">Import CSV</button>
        </form>
    </div>

    <!-- Client List -->
    <div class="card">
        <div class="card-header">
            <h3>All Clients (<?php echo count($displayClients); ?>)</h3>
            <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
                <div class="filter-bar">
                    <a href="crm.php?status=all" class="filter-btn <?php echo $filterStatus === 'all' ? 'active' : ''; ?>">All</a>
                    <a href="crm.php?status=active" class="filter-btn <?php echo $filterStatus === 'active' ? 'active' : ''; ?>">Active</a>
                    <a href="crm.php?status=prospect" class="filter-btn <?php echo $filterStatus === 'prospect' ? 'active' : ''; ?>">Prospects</a>
                    <a href="crm.php?status=inactive" class="filter-btn <?php echo $filterStatus === 'inactive' ? 'active' : ''; ?>">Inactive</a>
                </div>
                <a href="crm.php?export=mailchimp" class="btn btn-export btn-small">Export for Mailchimp</a>
            </div>
        </div>

        <?php if (empty($displayClients)): ?>
            <div class="empty-state">
                <span>&#128101;</span>
                <p>No clients found</p>
                <p style="font-size:0.9rem;">Add your first client using the form above.</p>
            </div>
        <?php else: ?>
            <table class="clients-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Company</th>
                        <th>Status</th>
                        <?php if ($mcConfigured): ?><th>Mailchimp</th><?php endif; ?>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($displayClients as $c): ?>
                    <tr>
                        <td>
                            <div class="client-name"><?php echo htmlspecialchars($c['full_name']); ?></div>
                            <?php if ($c['industry']): ?>
                                <div class="client-company"><?php echo htmlspecialchars($c['industry']); ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($c['email']); ?></td>
                        <td><?php echo htmlspecialchars($c['phone'] ?: '-'); ?></td>
                        <td><?php echo htmlspecialchars($c['company_name'] ?: '-'); ?></td>
                        <td><span class="badge badge-<?php echo $c['status']; ?>"><?php echo ucfirst($c['status']); ?></span></td>
                        <?php if ($mcConfigured): ?>
                        <td>
                            <?php if (!empty($c['mailchimp_synced'])): ?>
                                <span style="color:#16a34a;font-size:0.8rem;font-weight:600;" title="Synced <?php echo $c['mailchimp_synced']; ?>">Synced</span>
                            <?php else: ?>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="sync_client_id" value="<?php echo $c['id']; ?>">
                                    <button type="submit" style="padding:3px 10px;border-radius:6px;font-size:0.75rem;font-weight:600;border:1px solid rgba(0,71,187,0.2);background:rgba(0,71,187,0.06);color:var(--brand-blue);cursor:pointer;">Sync</button>
                                </form>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                        <td>
                            <div class="action-btns">
                                <a href="crm.php?edit=<?php echo $c['id']; ?>" class="btn-edit">Edit</a>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this client?');">
                                    <input type="hidden" name="delete_client" value="<?php echo $c['id']; ?>">
                                    <button type="submit" class="btn-delete">Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

</div>

</body>
</html>
