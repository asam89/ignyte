<?php
session_start();
require_once __DIR__ . '/config.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$pdo = getDB();
$adminName = htmlspecialchars($_SESSION['admin_name']);

// Handle add/edit client
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['client_action'])) {
    $action = $_POST['client_action'];
    $companyName = trim($_POST['company_name'] ?? '');
    $clientCode = strtoupper(trim($_POST['client_code'] ?? ''));
    $contactName = trim($_POST['contact_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $industry = trim($_POST['industry'] ?? '');
    $environment = trim($_POST['environment'] ?? '');
    $contractStart = $_POST['contract_start'] ?: null;
    $contractEnd = $_POST['contract_end'] ?: null;
    $contractTerms = trim($_POST['contract_terms'] ?? '');
    $keyServices = trim($_POST['key_services'] ?? '');
    $status = $_POST['client_status'] ?? 'active';

    if ($companyName) {
        if ($action === 'add') {
            $stmt = $pdo->prepare('INSERT INTO crm_clients (full_name, email, phone, company_name, industry, notes, status, is_client, client_code, environment, contract_start, contract_end, contract_terms, key_services) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$contactName ?: $companyName, $email, $phone, $companyName, $industry, '', $status, $clientCode, $environment, $contractStart, $contractEnd, $contractTerms, $keyServices]);
            header('Location: clients.php?added=1');
            exit;
        } elseif ($action === 'update' && isset($_POST['client_id'])) {
            $stmt = $pdo->prepare('UPDATE crm_clients SET full_name=?, email=?, phone=?, company_name=?, industry=?, status=?, client_code=?, environment=?, contract_start=?, contract_end=?, contract_terms=?, key_services=? WHERE id=?');
            $stmt->execute([$contactName ?: $companyName, $email, $phone, $companyName, $industry, $status, $clientCode, $environment, $contractStart, $contractEnd, $contractTerms, $keyServices, $_POST['client_id']]);
            header('Location: clients.php?updated=1');
            exit;
        }
    }
}

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_client'])) {
    $pdo->prepare('DELETE FROM crm_clients WHERE id = ?')->execute([$_POST['delete_client']]);
    header('Location: clients.php?deleted=1');
    exit;
}

// Handle promote contact to client
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['promote_to_client'])) {
    $pdo->prepare('UPDATE crm_clients SET is_client = 1 WHERE id = ?')->execute([$_POST['promote_to_client']]);
    header('Location: clients.php?promoted=1');
    exit;
}

// Handle add device
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_device'])) {
    $stmt = $pdo->prepare('INSERT INTO client_devices (client_id, device_name, device_type, hostname, os, serial_number, status, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $_POST['device_client_id'],
        trim($_POST['device_name'] ?? ''),
        trim($_POST['device_type'] ?? 'desktop'),
        trim($_POST['device_hostname'] ?? ''),
        trim($_POST['device_os'] ?? ''),
        trim($_POST['device_serial'] ?? ''),
        $_POST['device_status'] ?? 'active',
        trim($_POST['device_notes'] ?? '')
    ]);
    header('Location: clients.php?device_added=1&expand=' . $_POST['device_client_id']);
    exit;
}

// Handle delete device
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_device'])) {
    $cid = $_POST['device_owner_id'] ?? '';
    $pdo->prepare('DELETE FROM client_devices WHERE id = ?')->execute([$_POST['delete_device']]);
    header('Location: clients.php?device_deleted=1' . ($cid ? '&expand=' . $cid : ''));
    exit;
}

// Handle add platform
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_platform'])) {
    $stmt = $pdo->prepare('INSERT INTO client_platforms (client_id, platform_name, platform_type, account_id, license_count, status, notes) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $_POST['platform_client_id'],
        trim($_POST['platform_name'] ?? ''),
        trim($_POST['platform_type'] ?? 'saas'),
        trim($_POST['platform_account'] ?? ''),
        (int)($_POST['platform_licenses'] ?? 0),
        $_POST['platform_status'] ?? 'active',
        trim($_POST['platform_notes'] ?? '')
    ]);
    header('Location: clients.php?platform_added=1&expand=' . $_POST['platform_client_id']);
    exit;
}

// Handle delete platform
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_platform'])) {
    $cid = $_POST['platform_owner_id'] ?? '';
    $pdo->prepare('DELETE FROM client_platforms WHERE id = ?')->execute([$_POST['delete_platform']]);
    header('Location: clients.php?platform_deleted=1' . ($cid ? '&expand=' . $cid : ''));
    exit;
}

// Fetch all clients (is_client = 1)
$allClients = $pdo->query('SELECT * FROM crm_clients WHERE is_client = 1 ORDER BY company_name ASC')->fetchAll();

// Fetch devices and platforms per client
$devices = [];
$platforms = [];
try {
    $devRows = $pdo->query('SELECT * FROM client_devices ORDER BY device_name ASC')->fetchAll();
    foreach ($devRows as $d) { $devices[$d['client_id']][] = $d; }
} catch (PDOException $e) { /* table may not exist yet */ }
try {
    $platRows = $pdo->query('SELECT * FROM client_platforms ORDER BY platform_name ASC')->fetchAll();
    foreach ($platRows as $p) { $platforms[$p['client_id']][] = $p; }
} catch (PDOException $e) { /* table may not exist yet */ }

// Fetch projects per client
$projectsByClient = [];
try {
    $projRows = $pdo->query('SELECT * FROM crm_projects ORDER BY start_date DESC')->fetchAll();
    foreach ($projRows as $p) { if ($p['client_id']) $projectsByClient[$p['client_id']][] = $p; }
} catch (PDOException $e) {}

// Fetch tools per client
$toolsByClient = [];
try {
    $toolRows = $pdo->query('SELECT * FROM crm_tools ORDER BY expiry_date ASC')->fetchAll();
    foreach ($toolRows as $t) { if ($t['client_id']) $toolsByClient[$t['client_id']][] = $t; }
} catch (PDOException $e) {}

// Stats
$totalClients = count($allClients);
$activeClients = count(array_filter($allClients, function($c) { return $c['status'] === 'active'; }));
$totalDevices = array_sum(array_map('count', $devices));
$totalPlatforms = array_sum(array_map('count', $platforms));

// Editing?
$editClient = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM crm_clients WHERE id = ? AND is_client = 1');
    $stmt->execute([$_GET['edit']]);
    $editClient = $stmt->fetch();
}

$expandId = $_GET['expand'] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clients | IGNYTE Consulting</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Inter:wght@600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --navy: #002366;
            --brand-blue: #0047BB;
            --flame-orange: #EE5A24;
            --slate: #4A5568;
            --light-grey: #F4F7FA;
            --green: #16a34a;
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

        /* Search bar */
        .search-bar {
            display: flex; gap: 12px; align-items: center; margin-bottom: 24px; flex-wrap: wrap;
        }
        .search-bar input {
            flex: 1; min-width: 240px; padding: 12px 18px;
            border: 1.5px solid rgba(0,0,0,0.1); border-radius: 10px;
            font-size: 0.95rem; font-family: 'DM Sans', sans-serif;
            outline: none; transition: border-color 0.2s;
        }
        .search-bar input:focus { border-color: var(--brand-blue); }
        .search-bar .result-count { font-size: 0.85rem; color: var(--slate); font-weight: 600; }

        /* Client card */
        .client-card {
            background: white; border: 1.5px solid rgba(0,0,0,0.06);
            border-radius: 14px; margin-bottom: 12px;
            overflow: hidden; transition: all 0.2s;
        }
        .client-card:hover { border-color: var(--brand-blue); box-shadow: 0 4px 16px rgba(0,71,187,0.06); }
        .client-header {
            padding: 16px 24px; display: flex; align-items: center;
            justify-content: space-between; cursor: pointer;
            user-select: none; gap: 16px;
        }
        .client-header:hover { background: rgba(0,71,187,0.02); }
        .client-summary { display: flex; align-items: center; gap: 16px; flex: 1; min-width: 0; }
        .client-avatar {
            width: 44px; height: 44px; border-radius: 10px;
            background: var(--brand-blue); color: white;
            display: flex; align-items: center; justify-content: center;
            font-family: 'Inter', sans-serif; font-weight: 800; font-size: 0.95rem;
            flex-shrink: 0;
        }
        .client-info { min-width: 0; }
        .client-info h4 { font-family: 'Inter', sans-serif; font-size: 1rem; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .client-info .client-meta { display: flex; gap: 16px; margin-top: 3px; font-size: 0.82rem; color: var(--slate); flex-wrap: wrap; }
        .client-info .client-meta span { white-space: nowrap; }
        .client-right { display: flex; align-items: center; gap: 12px; flex-shrink: 0; }
        .badge {
            display: inline-block; padding: 3px 10px; border-radius: 50px;
            font-size: 0.72rem; font-weight: 700; text-transform: uppercase;
        }
        .badge-active { background: rgba(34,197,94,0.1); color: #16a34a; }
        .badge-inactive { background: rgba(220,38,38,0.08); color: #dc2626; }
        .badge-prospect { background: rgba(0,71,187,0.1); color: var(--brand-blue); }
        .expand-icon {
            width: 28px; height: 28px; border-radius: 6px;
            background: var(--light-grey); display: flex; align-items: center;
            justify-content: center; font-size: 0.8rem; transition: transform 0.2s;
        }
        .client-card.expanded .expand-icon { transform: rotate(180deg); }

        /* Expanded details */
        .client-details {
            display: none; padding: 0 24px 24px;
            border-top: 1px solid rgba(0,0,0,0.06);
        }
        .client-card.expanded .client-details { display: block; }

        .detail-tabs { display: flex; gap: 4px; margin: 16px 0; border-bottom: 2px solid var(--light-grey); }
        .detail-tab {
            padding: 8px 18px; font-size: 0.85rem; font-weight: 600;
            color: var(--slate); cursor: pointer; border-bottom: 2px solid transparent;
            margin-bottom: -2px; transition: all 0.2s; background: none; border-top: none; border-left: none; border-right: none;
            font-family: 'DM Sans', sans-serif;
        }
        .detail-tab:hover { color: var(--navy); }
        .detail-tab.active { color: var(--brand-blue); border-bottom-color: var(--brand-blue); }

        .tab-content { display: none; padding: 16px 0; }
        .tab-content.active { display: block; }

        .detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .detail-item { font-size: 0.88rem; }
        .detail-item .detail-label { font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--slate); margin-bottom: 3px; font-weight: 600; }
        .detail-item .detail-value { font-weight: 600; color: var(--navy); }

        /* Sub-tables */
        .sub-table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        .sub-table th {
            text-align: left; font-size: 0.76rem; text-transform: uppercase;
            letter-spacing: 0.06em; color: var(--slate); padding: 8px 10px;
            border-bottom: 2px solid var(--light-grey); font-weight: 600;
        }
        .sub-table td {
            padding: 10px; border-bottom: 1px solid rgba(0,0,0,0.04);
            font-size: 0.85rem; vertical-align: middle;
        }
        .sub-table tr:hover td { background: rgba(0,71,187,0.02); }

        /* Forms */
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .form-group { margin-bottom: 16px; }
        .form-group.full { grid-column: 1 / -1; }
        .form-group label {
            display: block; font-weight: 600; font-size: 0.85rem;
            color: var(--navy); margin-bottom: 6px;
        }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%; padding: 10px 14px;
            border: 1.5px solid rgba(0,0,0,0.1); border-radius: 8px;
            font-size: 0.92rem; font-family: 'DM Sans', sans-serif;
            outline: none; transition: border-color 0.2s;
        }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus { border-color: var(--brand-blue); }
        .form-group textarea { min-height: 80px; resize: vertical; line-height: 1.6; }

        .btn {
            padding: 10px 24px; border: none; border-radius: 8px;
            font-size: 0.9rem; font-weight: 700; font-family: 'DM Sans', sans-serif;
            cursor: pointer; transition: all 0.2s; text-decoration: none;
            display: inline-flex; align-items: center; gap: 6px;
        }
        .btn-save { background: var(--flame-orange); color: white; }
        .btn-save:hover { background: var(--navy); }
        .btn-cancel { background: transparent; color: var(--slate); padding: 10px 18px; }
        .btn-small { padding: 5px 12px; font-size: 0.8rem; }
        .btn-blue { background: var(--brand-blue); color: white; }
        .btn-blue:hover { background: var(--navy); }

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

        .inline-form { display: flex; gap: 8px; flex-wrap: wrap; align-items: end; margin-top: 12px; padding: 14px; background: var(--light-grey); border-radius: 10px; }
        .inline-form .form-group { margin-bottom: 0; }
        .inline-form input, .inline-form select { padding: 8px 10px; font-size: 0.85rem; }
        .inline-form label { font-size: 0.78rem; }

        .empty-state { text-align: center; padding: 40px 20px; color: var(--slate); }
        .empty-state span { font-size: 3rem; display: block; margin-bottom: 16px; }

        @media (max-width: 768px) {
            .stats-row { grid-template-columns: 1fr 1fr; }
            .form-grid { grid-template-columns: 1fr; }
            .detail-grid { grid-template-columns: 1fr; }
            .topbar { flex-direction: column; gap: 10px; text-align: center; }
            .client-info .client-meta { flex-direction: column; gap: 4px; }
        }
    </style>
</head>
<body>

<div class="topbar">
    <div class="topbar-left">
        <img src="../logo.png" alt="IGNYTE">
        <h2>Clients</h2>
    </div>
    <div class="topbar-right">
        <span>Welcome, <?php echo $adminName; ?></span>
        <a href="dashboard.php">Blog</a>
        <a href="clients.php" class="active-nav">Clients</a>
        <a href="crm.php">Contacts</a>
        <a href="projects.php">Projects</a>
        <a href="tools.php">Tools/Licenses</a>
        <a href="../index.html">View Site</a>
        <a href="logout.php" class="logout-btn">Logout</a>
    </div>
</div>

<div class="dashboard">

    <?php if (isset($_GET['added']) || isset($_GET['promoted'])): ?>
        <div class="alert alert-success">Client <?php echo isset($_GET['promoted']) ? 'promoted' : 'added'; ?> successfully!</div>
    <?php elseif (isset($_GET['updated'])): ?>
        <div class="alert alert-success">Client updated successfully!</div>
    <?php elseif (isset($_GET['deleted'])): ?>
        <div class="alert alert-success">Client deleted.</div>
    <?php elseif (isset($_GET['device_added']) || isset($_GET['device_deleted'])): ?>
        <div class="alert alert-success">Device <?php echo isset($_GET['device_added']) ? 'added' : 'removed'; ?>.</div>
    <?php elseif (isset($_GET['platform_added']) || isset($_GET['platform_deleted'])): ?>
        <div class="alert alert-success">Platform <?php echo isset($_GET['platform_added']) ? 'added' : 'removed'; ?>.</div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="stats-row">
        <div class="stat-box"><div class="num"><?php echo $totalClients; ?></div><div class="label">Total Clients</div></div>
        <div class="stat-box"><div class="num"><?php echo $activeClients; ?></div><div class="label">Active</div></div>
        <div class="stat-box"><div class="num"><?php echo $totalDevices; ?></div><div class="label">Devices Managed</div></div>
        <div class="stat-box"><div class="num"><?php echo $totalPlatforms; ?></div><div class="label">Platforms Managed</div></div>
    </div>

    <!-- Add / Edit Client -->
    <div class="card" id="client-form">
        <h3><?php echo $editClient ? 'Edit Client' : 'Add New Client'; ?></h3>
        <form method="POST">
            <input type="hidden" name="client_action" value="<?php echo $editClient ? 'update' : 'add'; ?>">
            <?php if ($editClient): ?>
                <input type="hidden" name="client_id" value="<?php echo $editClient['id']; ?>">
            <?php endif; ?>

            <div class="form-grid">
                <div class="form-group">
                    <label>Company Name *</label>
                    <input type="text" name="company_name" required value="<?php echo htmlspecialchars($editClient['company_name'] ?? ''); ?>" placeholder="Acme Corp">
                </div>
                <div class="form-group">
                    <label>Client Code</label>
                    <input type="text" name="client_code" value="<?php echo htmlspecialchars($editClient['client_code'] ?? ''); ?>" placeholder="ACM" maxlength="10" style="text-transform:uppercase;">
                </div>
                <div class="form-group">
                    <label>Primary Contact</label>
                    <input type="text" name="contact_name" value="<?php echo htmlspecialchars($editClient['full_name'] ?? ''); ?>" placeholder="John Smith">
                </div>
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" value="<?php echo htmlspecialchars($editClient['email'] ?? ''); ?>" placeholder="john@acme.com">
                </div>
                <div class="form-group">
                    <label>Phone</label>
                    <input type="text" name="phone" value="<?php echo htmlspecialchars($editClient['phone'] ?? ''); ?>" placeholder="+1 (555) 000-0000">
                </div>
                <div class="form-group">
                    <label>Industry</label>
                    <input type="text" name="industry" value="<?php echo htmlspecialchars($editClient['industry'] ?? ''); ?>" placeholder="Healthcare, Legal, Tech...">
                </div>
                <div class="form-group">
                    <label>Environment</label>
                    <input type="text" name="environment" value="<?php echo htmlspecialchars($editClient['environment'] ?? ''); ?>" placeholder="Windows, Mac, Linux, Hybrid...">
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select name="client_status">
                        <option value="active" <?php echo (($editClient['status'] ?? 'active') === 'active') ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo (($editClient['status'] ?? '') === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Contract Start</label>
                    <input type="date" name="contract_start" value="<?php echo htmlspecialchars($editClient['contract_start'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label>Contract End</label>
                    <input type="date" name="contract_end" value="<?php echo htmlspecialchars($editClient['contract_end'] ?? ''); ?>">
                </div>
                <div class="form-group full">
                    <label>Contract Terms</label>
                    <textarea name="contract_terms" placeholder="Monthly retainer, SLA terms, support hours, etc."><?php echo htmlspecialchars($editClient['contract_terms'] ?? ''); ?></textarea>
                </div>
                <div class="form-group full">
                    <label>Key Services</label>
                    <textarea name="key_services" rows="2" placeholder="M365, Intune, Entra, Exchange, SharePoint..."><?php echo htmlspecialchars($editClient['key_services'] ?? ''); ?></textarea>
                </div>
            </div>

            <div style="display:flex;gap:12px;margin-top:8px;">
                <button type="submit" class="btn btn-save"><?php echo $editClient ? 'Update Client' : 'Add Client'; ?></button>
                <?php if ($editClient): ?>
                    <a href="clients.php" class="btn btn-cancel">Cancel</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Client List -->
    <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;flex-wrap:wrap;gap:12px;">
            <h3 style="margin-bottom:0;">All Clients (<?php echo $totalClients; ?>)</h3>
            <button onclick="toggleAllClients()" class="btn btn-small btn-blue" id="toggleAllBtn">Expand All</button>
        </div>

        <!-- Search -->
        <div class="search-bar">
            <input type="text" id="clientSearch" placeholder="Search clients by name, code, contact, email, services..." oninput="filterClients()">
            <span class="result-count" id="resultCount"><?php echo $totalClients; ?> client(s)</span>
        </div>

        <?php if (empty($allClients)): ?>
            <div class="empty-state">
                <span>&#127970;</span>
                <p>No clients yet</p>
                <p style="font-size:0.9rem;">Add your first client using the form above, or promote a contact from the <a href="crm.php" style="color:var(--brand-blue);">Contacts</a> page.</p>
            </div>
        <?php else: ?>
            <div id="clientList">
            <?php foreach ($allClients as $c):
                $cId = $c['id'];
                $code = htmlspecialchars($c['client_code'] ?? '');
                $cDevices = $devices[$cId] ?? [];
                $cPlatforms = $platforms[$cId] ?? [];
                $cProjects = $projectsByClient[$cId] ?? [];
                $cTools = $toolsByClient[$cId] ?? [];
                $isExpanded = ($expandId && (int)$expandId === (int)$cId);
            ?>
                <div class="client-card <?php echo $isExpanded ? 'expanded' : ''; ?>"
                     data-search="<?php echo strtolower(htmlspecialchars($c['company_name'] . ' ' . ($c['client_code'] ?? '') . ' ' . $c['full_name'] . ' ' . $c['email'] . ' ' . ($c['key_services'] ?? '') . ' ' . ($c['environment'] ?? '') . ' ' . $c['industry'])); ?>">
                    <div class="client-header" onclick="toggleClient(this)">
                        <div class="client-summary">
                            <div class="client-avatar"><?php echo $code ?: strtoupper(substr($c['company_name'], 0, 2)); ?></div>
                            <div class="client-info">
                                <h4><?php echo htmlspecialchars($c['company_name']); ?></h4>
                                <div class="client-meta">
                                    <?php if ($code): ?><span><strong><?php echo $code; ?></strong></span><?php endif; ?>
                                    <?php if ($c['full_name'] && $c['full_name'] !== $c['company_name']): ?><span><?php echo htmlspecialchars($c['full_name']); ?></span><?php endif; ?>
                                    <?php if ($c['email']): ?><span><?php echo htmlspecialchars($c['email']); ?></span><?php endif; ?>
                                    <?php if ($c['phone']): ?><span><?php echo htmlspecialchars($c['phone']); ?></span><?php endif; ?>
                                    <span><?php echo count($cDevices); ?> device(s)</span>
                                    <span><?php echo count($cPlatforms); ?> platform(s)</span>
                                </div>
                            </div>
                        </div>
                        <div class="client-right">
                            <span class="badge badge-<?php echo $c['status']; ?>"><?php echo ucfirst($c['status']); ?></span>
                            <div class="expand-icon">&#9660;</div>
                        </div>
                    </div>

                    <div class="client-details">
                        <!-- Tabs -->
                        <div class="detail-tabs">
                            <button class="detail-tab active" onclick="switchTab(this, 'overview-<?php echo $cId; ?>')">Overview</button>
                            <button class="detail-tab" onclick="switchTab(this, 'devices-<?php echo $cId; ?>')">Devices (<?php echo count($cDevices); ?>)</button>
                            <button class="detail-tab" onclick="switchTab(this, 'platforms-<?php echo $cId; ?>')">Platforms (<?php echo count($cPlatforms); ?>)</button>
                            <button class="detail-tab" onclick="switchTab(this, 'projects-<?php echo $cId; ?>')">Projects (<?php echo count($cProjects); ?>)</button>
                        </div>

                        <!-- Overview Tab -->
                        <div class="tab-content active" id="overview-<?php echo $cId; ?>">
                            <div class="detail-grid">
                                <div class="detail-item">
                                    <div class="detail-label">Primary Contact</div>
                                    <div class="detail-value"><?php echo htmlspecialchars($c['full_name'] ?: '—'); ?></div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">Email</div>
                                    <div class="detail-value"><?php echo htmlspecialchars($c['email'] ?: '—'); ?></div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">Phone</div>
                                    <div class="detail-value"><?php echo htmlspecialchars($c['phone'] ?: '—'); ?></div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">Industry</div>
                                    <div class="detail-value"><?php echo htmlspecialchars($c['industry'] ?: '—'); ?></div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">Environment</div>
                                    <div class="detail-value"><?php echo htmlspecialchars($c['environment'] ?? '—'); ?></div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">Client Since</div>
                                    <div class="detail-value"><?php echo $c['created_at'] ? date('M j, Y', strtotime($c['created_at'])) : '—'; ?></div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">Contract Period</div>
                                    <div class="detail-value">
                                        <?php
                                        $cs = $c['contract_start'] ?? null;
                                        $ce = $c['contract_end'] ?? null;
                                        if ($cs && $ce) echo date('M j, Y', strtotime($cs)) . ' – ' . date('M j, Y', strtotime($ce));
                                        elseif ($cs) echo date('M j, Y', strtotime($cs)) . ' – Ongoing';
                                        else echo '—';
                                        ?>
                                    </div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">Key Services</div>
                                    <div class="detail-value"><?php echo htmlspecialchars($c['key_services'] ?? '—'); ?></div>
                                </div>
                            </div>
                            <?php if (!empty($c['contract_terms'])): ?>
                                <div style="margin-top:16px;">
                                    <div class="detail-label" style="font-size:0.78rem;text-transform:uppercase;letter-spacing:0.04em;color:var(--slate);font-weight:600;margin-bottom:6px;">Contract Terms</div>
                                    <div style="background:var(--light-grey);padding:14px;border-radius:8px;font-size:0.88rem;line-height:1.6;white-space:pre-wrap;"><?php echo htmlspecialchars($c['contract_terms']); ?></div>
                                </div>
                            <?php endif; ?>
                            <div class="action-btns" style="margin-top:16px;">
                                <a href="clients.php?edit=<?php echo $cId; ?>" class="btn-edit">Edit Client</a>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this client and all their devices/platforms?');">
                                    <input type="hidden" name="delete_client" value="<?php echo $cId; ?>">
                                    <button type="submit" class="btn-delete">Delete</button>
                                </form>
                            </div>
                        </div>

                        <!-- Devices Tab -->
                        <div class="tab-content" id="devices-<?php echo $cId; ?>">
                            <?php if (empty($cDevices)): ?>
                                <p style="color:var(--slate);font-size:0.9rem;padding:16px 0;">No devices under management yet.</p>
                            <?php else: ?>
                                <table class="sub-table">
                                    <thead>
                                        <tr>
                                            <th>Device</th>
                                            <th>Type</th>
                                            <th>Hostname</th>
                                            <th>OS</th>
                                            <th>Serial #</th>
                                            <th>Status</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($cDevices as $dev): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($dev['device_name']); ?></strong></td>
                                            <td><?php echo htmlspecialchars(ucfirst($dev['device_type'])); ?></td>
                                            <td style="font-family:monospace;font-size:0.82rem;"><?php echo htmlspecialchars($dev['hostname'] ?: '—'); ?></td>
                                            <td><?php echo htmlspecialchars($dev['os'] ?: '—'); ?></td>
                                            <td style="font-size:0.82rem;"><?php echo htmlspecialchars($dev['serial_number'] ?: '—'); ?></td>
                                            <td><span class="badge badge-<?php echo $dev['status']; ?>"><?php echo ucfirst($dev['status']); ?></span></td>
                                            <td>
                                                <form method="POST" style="display:inline;" onsubmit="return confirm('Remove this device?');">
                                                    <input type="hidden" name="delete_device" value="<?php echo $dev['id']; ?>">
                                                    <input type="hidden" name="device_owner_id" value="<?php echo $cId; ?>">
                                                    <button type="submit" class="btn-delete" style="padding:3px 8px;font-size:0.75rem;">Remove</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>

                            <details style="margin-top:12px;">
                                <summary style="font-size:0.85rem;font-weight:600;color:var(--brand-blue);cursor:pointer;">+ Add Device</summary>
                                <form method="POST" class="inline-form">
                                    <input type="hidden" name="add_device" value="1">
                                    <input type="hidden" name="device_client_id" value="<?php echo $cId; ?>">
                                    <div class="form-group"><label>Name *</label><input type="text" name="device_name" required placeholder="John's Laptop" style="min-width:140px;"></div>
                                    <div class="form-group">
                                        <label>Type</label>
                                        <select name="device_type">
                                            <option value="desktop">Desktop</option>
                                            <option value="laptop" selected>Laptop</option>
                                            <option value="server">Server</option>
                                            <option value="mobile">Mobile</option>
                                            <option value="tablet">Tablet</option>
                                            <option value="printer">Printer</option>
                                            <option value="network">Network Device</option>
                                            <option value="other">Other</option>
                                        </select>
                                    </div>
                                    <div class="form-group"><label>Hostname</label><input type="text" name="device_hostname" placeholder="WS-001"></div>
                                    <div class="form-group"><label>OS</label><input type="text" name="device_os" placeholder="Windows 11 Pro"></div>
                                    <div class="form-group"><label>Serial #</label><input type="text" name="device_serial" placeholder="ABC123"></div>
                                    <div class="form-group">
                                        <label>Status</label>
                                        <select name="device_status"><option value="active">Active</option><option value="inactive">Inactive</option></select>
                                    </div>
                                    <button type="submit" class="btn btn-small btn-save">Add Device</button>
                                </form>
                            </details>
                        </div>

                        <!-- Platforms Tab -->
                        <div class="tab-content" id="platforms-<?php echo $cId; ?>">
                            <?php if (empty($cPlatforms)): ?>
                                <p style="color:var(--slate);font-size:0.9rem;padding:16px 0;">No platforms under management yet.</p>
                            <?php else: ?>
                                <table class="sub-table">
                                    <thead>
                                        <tr>
                                            <th>Platform</th>
                                            <th>Type</th>
                                            <th>Account ID</th>
                                            <th>Licenses</th>
                                            <th>Status</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($cPlatforms as $plat): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($plat['platform_name']); ?></strong></td>
                                            <td><?php echo htmlspecialchars(ucfirst($plat['platform_type'])); ?></td>
                                            <td style="font-family:monospace;font-size:0.82rem;"><?php echo htmlspecialchars($plat['account_id'] ?: '—'); ?></td>
                                            <td><?php echo $plat['license_count'] ?: '—'; ?></td>
                                            <td><span class="badge badge-<?php echo $plat['status']; ?>"><?php echo ucfirst($plat['status']); ?></span></td>
                                            <td>
                                                <form method="POST" style="display:inline;" onsubmit="return confirm('Remove this platform?');">
                                                    <input type="hidden" name="delete_platform" value="<?php echo $plat['id']; ?>">
                                                    <input type="hidden" name="platform_owner_id" value="<?php echo $cId; ?>">
                                                    <button type="submit" class="btn-delete" style="padding:3px 8px;font-size:0.75rem;">Remove</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>

                            <details style="margin-top:12px;">
                                <summary style="font-size:0.85rem;font-weight:600;color:var(--brand-blue);cursor:pointer;">+ Add Platform</summary>
                                <form method="POST" class="inline-form">
                                    <input type="hidden" name="add_platform" value="1">
                                    <input type="hidden" name="platform_client_id" value="<?php echo $cId; ?>">
                                    <div class="form-group"><label>Name *</label><input type="text" name="platform_name" required placeholder="Microsoft 365" style="min-width:140px;"></div>
                                    <div class="form-group">
                                        <label>Type</label>
                                        <select name="platform_type">
                                            <option value="saas">SaaS</option>
                                            <option value="iaas">IaaS</option>
                                            <option value="paas">PaaS</option>
                                            <option value="on-premise">On-Premise</option>
                                            <option value="hybrid">Hybrid</option>
                                            <option value="other">Other</option>
                                        </select>
                                    </div>
                                    <div class="form-group"><label>Account ID</label><input type="text" name="platform_account" placeholder="tenant-id-123"></div>
                                    <div class="form-group"><label>Licenses</label><input type="number" name="platform_licenses" placeholder="25" min="0"></div>
                                    <div class="form-group">
                                        <label>Status</label>
                                        <select name="platform_status"><option value="active">Active</option><option value="inactive">Inactive</option></select>
                                    </div>
                                    <button type="submit" class="btn btn-small btn-save">Add Platform</button>
                                </form>
                            </details>
                        </div>

                        <!-- Projects Tab -->
                        <div class="tab-content" id="projects-<?php echo $cId; ?>">
                            <?php if (empty($cProjects)): ?>
                                <p style="color:var(--slate);font-size:0.9rem;padding:16px 0;">No projects assigned. <a href="projects.php" style="color:var(--brand-blue);font-weight:600;">Manage Projects</a></p>
                            <?php else: ?>
                                <table class="sub-table">
                                    <thead>
                                        <tr><th>Project</th><th>Status</th><th>Priority</th><th>Start</th><th>End</th></tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($cProjects as $proj): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($proj['project_name']); ?></strong></td>
                                            <td><span class="badge badge-<?php echo $proj['status']; ?>"><?php echo ucfirst(str_replace('_', ' ', $proj['status'])); ?></span></td>
                                            <td><?php echo ucfirst($proj['priority']); ?></td>
                                            <td style="font-size:0.82rem;"><?php echo $proj['start_date'] ? date('M j, Y', strtotime($proj['start_date'])) : '—'; ?></td>
                                            <td style="font-size:0.82rem;"><?php echo $proj['end_date'] ? date('M j, Y', strtotime($proj['end_date'])) : '—'; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

</div>

<script>
function toggleClient(header) {
    const card = header.closest('.client-card');
    card.classList.toggle('expanded');
}

function toggleAllClients() {
    const cards = document.querySelectorAll('.client-card');
    const btn = document.getElementById('toggleAllBtn');
    const anyExpanded = Array.from(cards).some(c => c.classList.contains('expanded'));

    cards.forEach(c => {
        if (c.style.display !== 'none') {
            if (anyExpanded) c.classList.remove('expanded');
            else c.classList.add('expanded');
        }
    });
    btn.textContent = anyExpanded ? 'Expand All' : 'Collapse All';
}

function filterClients() {
    const query = document.getElementById('clientSearch').value.toLowerCase().trim();
    const cards = document.querySelectorAll('.client-card');
    let visible = 0;

    cards.forEach(card => {
        const data = card.getAttribute('data-search') || '';
        const match = !query || data.includes(query);
        card.style.display = match ? '' : 'none';
        if (match) visible++;
    });

    document.getElementById('resultCount').textContent = visible + ' client(s)';
}

function switchTab(btn, tabId) {
    const details = btn.closest('.client-details');
    details.querySelectorAll('.detail-tab').forEach(t => t.classList.remove('active'));
    details.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById(tabId).classList.add('active');
}
</script>

</body>
</html>
