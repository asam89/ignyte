<?php
session_start();
require_once __DIR__ . '/../admin/config.php';

if (!isset($_SESSION['client_id'])) {
    header('Location: login.php');
    exit;
}

$pdo = getDB();
$clientId = $_SESSION['client_id'];

// Fetch client login account
$stmt = $pdo->prepare('SELECT * FROM clients WHERE id = ?');
$stmt->execute([$clientId]);
$client = $stmt->fetch();

// Find matching CRM client record by email (links portal account to CRM data)
$crmClient = null;
$crmClientId = null;
if ($client && $client['email']) {
    $crmStmt = $pdo->prepare('SELECT * FROM crm_clients WHERE LOWER(email) = LOWER(?) AND is_client = 1 LIMIT 1');
    $crmStmt->execute([$client['email']]);
    $crmClient = $crmStmt->fetch();
    if ($crmClient) $crmClientId = $crmClient['id'];
}

// Fetch client projects (from client_projects table linked to portal account)
$projStmt = $pdo->prepare('SELECT * FROM client_projects WHERE client_id = ? ORDER BY updated_at DESC');
$projStmt->execute([$clientId]);
$projects = $projStmt->fetchAll();

// Also fetch CRM projects linked to the CRM client record
$crmProjects = [];
if ($crmClientId) {
    try {
        $cpStmt = $pdo->prepare('SELECT * FROM crm_projects WHERE client_id = ? ORDER BY start_date DESC');
        $cpStmt->execute([$crmClientId]);
        $crmProjects = $cpStmt->fetchAll();
    } catch (PDOException $e) {}
}

// Fetch documents
$docStmt = $pdo->prepare('SELECT * FROM client_documents WHERE client_id = ? ORDER BY uploaded_at DESC LIMIT 10');
$docStmt->execute([$clientId]);
$documents = $docStmt->fetchAll();

// Fetch invoices
$invStmt = $pdo->prepare('SELECT * FROM client_invoices WHERE client_id = ? ORDER BY created_at DESC LIMIT 10');
$invStmt->execute([$clientId]);
$invoices = $invStmt->fetchAll();

// Fetch devices under management
$devices = [];
if ($crmClientId) {
    try {
        $devStmt = $pdo->prepare('SELECT * FROM client_devices WHERE client_id = ? ORDER BY device_name ASC');
        $devStmt->execute([$crmClientId]);
        $devices = $devStmt->fetchAll();
    } catch (PDOException $e) {}
}

// Fetch platforms under management
$platforms = [];
if ($crmClientId) {
    try {
        $platStmt = $pdo->prepare('SELECT * FROM client_platforms WHERE client_id = ? ORDER BY platform_name ASC');
        $platStmt->execute([$crmClientId]);
        $platforms = $platStmt->fetchAll();
    } catch (PDOException $e) {}
}

// Fetch tools/licenses
$tools = [];
if ($crmClientId) {
    try {
        $toolStmt = $pdo->prepare('SELECT * FROM crm_tools WHERE client_id = ? ORDER BY expiry_date ASC');
        $toolStmt->execute([$crmClientId]);
        $tools = $toolStmt->fetchAll();
    } catch (PDOException $e) {}
}

// Site Manager embed token
$siteManagerEmbedUrl = '';
if (defined('SITE_MANAGER_URL') && SITE_MANAGER_URL && defined('SITE_MANAGER_API_SECRET') && SITE_MANAGER_API_SECRET) {
    // Look up site ID for this client from CRM or use a configured mapping
    $siteManagerSiteId = '';
    if ($crmClient) {
        // Check if there's a site_manager_site_id field on the CRM client
        $siteManagerSiteId = $crmClient['site_manager_site_id'] ?? '';
    }

    if ($siteManagerSiteId && $client['email']) {
        $tokenPayload = json_encode([
            'email' => $client['email'],
            'siteId' => $siteManagerSiteId,
        ]);

        $ch = curl_init(SITE_MANAGER_URL . '/api/embed/token');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $tokenPayload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . SITE_MANAGER_API_SECRET,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $tokenResponse = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && $tokenResponse) {
            $tokenData = json_decode($tokenResponse, true);
            if (isset($tokenData['token'])) {
                $siteManagerEmbedUrl = SITE_MANAGER_URL . '/embed/' . urlencode($siteManagerSiteId) . '?token=' . urlencode($tokenData['token']);
            }
        }
    }
}

// Stats
$activeProjects = 0;
$totalInvoiced = 0;
$pendingInvoices = 0;
foreach ($projects as $p) { if ($p['status'] === 'active') $activeProjects++; }
foreach ($crmProjects as $p) { if ($p['status'] === 'active') $activeProjects++; }
foreach ($invoices as $i) {
    $totalInvoiced += $i['amount'];
    if ($i['status'] === 'pending' || $i['status'] === 'sent') $pendingInvoices++;
}
$activeDevices = count(array_filter($devices, function($d) { return $d['status'] === 'active'; }));
$activePlatforms = count(array_filter($platforms, function($p) { return $p['status'] === 'active'; }));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Client Dashboard | IGNYTE Consulting</title>
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
            --white: #FFFFFF;
            --green: #16a34a;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'DM Sans', sans-serif; background: var(--light-grey); color: var(--navy); }

        /* Top bar */
        .top-bar {
            background: var(--navy); color: white; padding: 14px 0;
            position: sticky; top: 0; z-index: 100;
        }
        .top-bar-inner {
            max-width: 1180px; margin: 0 auto; padding: 0 28px;
            display: flex; justify-content: space-between; align-items: center;
        }
        .top-bar-left { display: flex; align-items: center; gap: 16px; }
        .top-bar-left img { height: 36px; filter: brightness(0) invert(1); }
        .top-bar-left span { font-family: 'Inter', sans-serif; font-weight: 700; font-size: 0.95rem; opacity: 0.7; }
        .top-bar-right { display: flex; align-items: center; gap: 20px; }
        .top-bar-right .user-info { font-size: 0.88rem; }
        .top-bar-right .user-name { font-weight: 700; }
        .top-bar-right .user-company { opacity: 0.6; font-size: 0.8rem; }
        .btn-logout {
            background: rgba(255,255,255,0.1); color: white; border: none;
            padding: 8px 18px; border-radius: 6px; font-size: 0.82rem;
            font-weight: 700; cursor: pointer; font-family: 'DM Sans', sans-serif;
            text-decoration: none; transition: background 0.2s;
        }
        .btn-logout:hover { background: rgba(255,255,255,0.2); }

        .dashboard-wrap { max-width: 1180px; margin: 0 auto; padding: 32px 28px; }

        .welcome-banner {
            background: white; border-radius: 16px; padding: 32px;
            margin-bottom: 28px; box-shadow: 0 2px 12px rgba(0,0,0,0.04);
        }
        .welcome-banner h1 { font-family: 'Inter', sans-serif; font-size: 1.8rem; font-weight: 700; margin-bottom: 6px; }
        .welcome-banner p { color: var(--slate); font-size: 0.95rem; }

        /* Nav tabs */
        .dash-tabs {
            display: flex; gap: 4px; margin-bottom: 28px;
            background: white; border-radius: 12px; padding: 6px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.04); flex-wrap: wrap;
        }
        .dash-tab {
            padding: 10px 20px; border-radius: 8px; font-size: 0.88rem;
            font-weight: 600; color: var(--slate); cursor: pointer;
            border: none; background: none; font-family: 'DM Sans', sans-serif;
            transition: all 0.2s;
        }
        .dash-tab:hover { color: var(--navy); background: var(--light-grey); }
        .dash-tab.active { color: white; background: var(--brand-blue); }

        /* Stats row */
        .stats-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 28px; }
        .stat-box {
            background: white; border-radius: 14px; padding: 24px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.04);
        }
        .stat-box .stat-icon {
            width: 44px; height: 44px; border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.3rem; margin-bottom: 14px;
        }
        .stat-box .stat-icon.blue { background: rgba(0,71,187,0.1); }
        .stat-box .stat-icon.orange { background: rgba(238,90,36,0.1); }
        .stat-box .stat-icon.green { background: rgba(34,197,94,0.1); }
        .stat-box .stat-icon.purple { background: rgba(124,58,237,0.1); }
        .stat-box .stat-value { font-family: 'Inter', sans-serif; font-size: 1.8rem; font-weight: 700; margin-bottom: 4px; }
        .stat-box .stat-label { color: var(--slate); font-size: 0.82rem; text-transform: uppercase; letter-spacing: 0.06em; }

        /* Section cards */
        .tab-panel { display: none; }
        .tab-panel.active { display: block; }

        .section-card {
            background: white; border-radius: 16px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.04);
            overflow: hidden; margin-bottom: 24px;
        }
        .section-card-header {
            padding: 20px 24px; border-bottom: 1px solid rgba(0,0,0,0.06);
            display: flex; justify-content: space-between; align-items: center;
        }
        .section-card-header h2 { font-family: 'Inter', sans-serif; font-size: 1.15rem; font-weight: 700; }
        .section-card-body { padding: 20px 24px; }

        /* Data table */
        .data-table { width: 100%; border-collapse: collapse; }
        .data-table th {
            text-align: left; font-size: 0.76rem; text-transform: uppercase;
            letter-spacing: 0.06em; color: var(--slate); padding: 8px 0;
            border-bottom: 1px solid rgba(0,0,0,0.06);
        }
        .data-table td {
            padding: 12px 0; font-size: 0.9rem;
            border-bottom: 1px solid rgba(0,0,0,0.04); vertical-align: middle;
        }
        .data-table tr:last-child td { border-bottom: none; }

        .badge {
            display: inline-block; padding: 3px 10px; border-radius: 50px;
            font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em;
        }
        .badge-active { background: rgba(34,197,94,0.1); color: #16a34a; }
        .badge-completed { background: rgba(0,71,187,0.1); color: var(--brand-blue); }
        .badge-on-hold, .badge-on_hold { background: rgba(238,90,36,0.1); color: var(--flame-orange); }
        .badge-planned { background: rgba(124,58,237,0.1); color: #7c3aed; }
        .badge-paid { background: rgba(34,197,94,0.1); color: #16a34a; }
        .badge-pending { background: rgba(238,90,36,0.1); color: var(--flame-orange); }
        .badge-sent { background: rgba(0,71,187,0.1); color: var(--brand-blue); }
        .badge-inactive { background: rgba(220,38,38,0.08); color: #dc2626; }
        .badge-retired { background: rgba(107,114,128,0.1); color: #6b7280; }

        /* Contract card */
        .contract-card {
            background: white; border-radius: 16px; padding: 28px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.04); margin-bottom: 24px;
            border-left: 4px solid var(--brand-blue);
        }
        .contract-card h2 { font-family: 'Inter', sans-serif; font-size: 1.15rem; font-weight: 700; margin-bottom: 20px; }
        .contract-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; }
        .contract-item .contract-label {
            font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.04em;
            color: var(--slate); margin-bottom: 4px; font-weight: 600;
        }
        .contract-item .contract-value { font-weight: 600; font-size: 0.95rem; }
        .contract-terms {
            margin-top: 20px; padding: 16px; background: var(--light-grey);
            border-radius: 10px; font-size: 0.9rem; line-height: 1.7; white-space: pre-wrap;
        }
        .key-services {
            margin-top: 16px; display: flex; flex-wrap: wrap; gap: 8px;
        }
        .service-tag {
            padding: 4px 12px; background: rgba(0,71,187,0.08); color: var(--brand-blue);
            border-radius: 50px; font-size: 0.8rem; font-weight: 600;
        }

        /* Empty state */
        .empty-state { text-align: center; padding: 32px 16px; color: var(--slate); }
        .empty-state .empty-icon { font-size: 2.4rem; margin-bottom: 12px; opacity: 0.4; }
        .empty-state p { font-size: 0.9rem; }

        /* Quick actions */
        .quick-actions { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 28px; }
        .action-card {
            background: white; border-radius: 14px; padding: 24px; text-align: center;
            box-shadow: 0 2px 12px rgba(0,0,0,0.04); cursor: pointer; transition: all 0.2s;
            text-decoration: none; color: var(--navy); border: 1.5px solid transparent;
        }
        .action-card:hover {
            transform: translateY(-3px); box-shadow: 0 8px 24px rgba(0,0,0,0.08);
            border-color: var(--brand-blue);
        }
        .action-card .action-icon { font-size: 2rem; margin-bottom: 12px; }
        .action-card h3 { font-family: 'Inter', sans-serif; font-size: 1rem; font-weight: 700; margin-bottom: 6px; }
        .action-card p { color: var(--slate); font-size: 0.82rem; }

        @media (max-width: 768px) {
            .stats-row { grid-template-columns: repeat(2, 1fr); }
            .contract-grid { grid-template-columns: 1fr; }
            .quick-actions { grid-template-columns: 1fr; }
            .top-bar-right .user-info { display: none; }
            .dash-tabs { gap: 2px; }
        }
        @media (max-width: 480px) {
            .stats-row { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<!-- Top Bar -->
<div class="top-bar">
    <div class="top-bar-inner">
        <div class="top-bar-left">
            <a href="../index.html"><img src="../logo.png" alt="IGNYTE"></a>
            <span>Client Portal</span>
        </div>
        <div class="top-bar-right">
            <div class="user-info">
                <div class="user-name"><?php echo htmlspecialchars($_SESSION['client_name']); ?></div>
                <div class="user-company"><?php echo htmlspecialchars($_SESSION['client_company'] ?? ''); ?></div>
            </div>
            <a href="logout.php" class="btn-logout">Sign Out</a>
        </div>
    </div>
</div>

<div class="dashboard-wrap">

    <!-- Welcome -->
    <div class="welcome-banner">
        <h1>Welcome back, <?php echo htmlspecialchars(explode(' ', $_SESSION['client_name'])[0]); ?></h1>
        <p>Here's your IT management overview with IGNYTE Consulting.</p>
    </div>

    <!-- Navigation Tabs -->
    <div class="dash-tabs">
        <button class="dash-tab active" onclick="showPanel('overview')">Overview</button>
        <button class="dash-tab" onclick="showPanel('contract')">Contract &amp; Terms</button>
        <button class="dash-tab" onclick="showPanel('devices')">Devices (<?php echo count($devices); ?>)</button>
        <button class="dash-tab" onclick="showPanel('platforms')">Platforms (<?php echo count($platforms); ?>)</button>
        <button class="dash-tab" onclick="showPanel('projects')">Projects</button>
        <button class="dash-tab" onclick="showPanel('billing')">Billing &amp; Docs</button>
        <?php if ($siteManagerEmbedUrl): ?>
            <button class="dash-tab" onclick="showPanel('website')" style="display:flex;align-items:center;gap:6px;">
                <span style="font-size:1.1em;">&#127760;</span> Website Updates
            </button>
        <?php endif; ?>
    </div>

    <!-- ========== OVERVIEW PANEL ========== -->
    <div class="tab-panel active" id="panel-overview">
        <!-- Stats -->
        <div class="stats-row">
            <div class="stat-box">
                <div class="stat-icon blue">&#128187;</div>
                <div class="stat-value"><?php echo $activeDevices; ?></div>
                <div class="stat-label">Devices Managed</div>
            </div>
            <div class="stat-box">
                <div class="stat-icon green">&#9729;</div>
                <div class="stat-value"><?php echo $activePlatforms; ?></div>
                <div class="stat-label">Platforms Managed</div>
            </div>
            <div class="stat-box">
                <div class="stat-icon orange">&#128203;</div>
                <div class="stat-value"><?php echo $activeProjects; ?></div>
                <div class="stat-label">Active Projects</div>
            </div>
            <div class="stat-box">
                <div class="stat-icon purple">&#128176;</div>
                <div class="stat-value"><?php echo $pendingInvoices; ?></div>
                <div class="stat-label">Pending Invoices</div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="quick-actions">
            <a href="https://igy.atlassian.net/servicedesk/customer/portal/2" target="_blank" rel="noopener" class="action-card">
                <div class="action-icon">&#127919;</div>
                <h3>Submit Support Request</h3>
                <p>Create a ticket for our team</p>
            </a>
            <a href="mailto:contact@ignyteconsulting.com?subject=Project%20Inquiry" class="action-card">
                <div class="action-icon">&#128231;</div>
                <h3>Contact Account Manager</h3>
                <p>Reach out directly about your projects</p>
            </a>
            <a href="mailto:contact@ignyteconsulting.com?subject=Schedule%20Meeting" class="action-card">
                <div class="action-icon">&#128197;</div>
                <h3>Schedule a Meeting</h3>
                <p>Book time with our consulting team</p>
            </a>
        </div>

        <?php if ($crmClient && !empty($crmClient['key_services'])): ?>
            <div class="section-card">
                <div class="section-card-header"><h2>Your Services</h2></div>
                <div class="section-card-body">
                    <div class="key-services">
                        <?php foreach (explode(',', $crmClient['key_services']) as $svc): ?>
                            <?php $svc = trim($svc); if ($svc): ?>
                                <span class="service-tag"><?php echo htmlspecialchars($svc); ?></span>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- ========== CONTRACT PANEL ========== -->
    <div class="tab-panel" id="panel-contract">
        <?php if ($crmClient): ?>
            <div class="contract-card">
                <h2>Contract Details</h2>
                <div class="contract-grid">
                    <div class="contract-item">
                        <div class="contract-label">Client Since</div>
                        <div class="contract-value"><?php echo $crmClient['created_at'] ? date('M j, Y', strtotime($crmClient['created_at'])) : '—'; ?></div>
                    </div>
                    <div class="contract-item">
                        <div class="contract-label">Contract Start</div>
                        <div class="contract-value"><?php echo !empty($crmClient['contract_start']) ? date('M j, Y', strtotime($crmClient['contract_start'])) : '—'; ?></div>
                    </div>
                    <div class="contract-item">
                        <div class="contract-label">Contract End</div>
                        <div class="contract-value"><?php echo !empty($crmClient['contract_end']) ? date('M j, Y', strtotime($crmClient['contract_end'])) : 'Ongoing'; ?></div>
                    </div>
                    <div class="contract-item">
                        <div class="contract-label">Environment</div>
                        <div class="contract-value"><?php echo htmlspecialchars($crmClient['environment'] ?? '—'); ?></div>
                    </div>
                    <div class="contract-item">
                        <div class="contract-label">Status</div>
                        <div class="contract-value"><span class="badge badge-<?php echo $crmClient['status']; ?>"><?php echo ucfirst($crmClient['status']); ?></span></div>
                    </div>
                    <div class="contract-item">
                        <div class="contract-label">Client Code</div>
                        <div class="contract-value"><?php echo htmlspecialchars($crmClient['client_code'] ?? '—'); ?></div>
                    </div>
                </div>

                <?php if (!empty($crmClient['contract_terms'])): ?>
                    <div style="margin-top:20px;">
                        <div class="contract-label" style="font-size:0.78rem;text-transform:uppercase;letter-spacing:0.04em;color:var(--slate);font-weight:600;margin-bottom:8px;">Contract Terms</div>
                        <div class="contract-terms"><?php echo htmlspecialchars($crmClient['contract_terms']); ?></div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($crmClient['key_services'])): ?>
                    <div style="margin-top:20px;">
                        <div class="contract-label" style="font-size:0.78rem;text-transform:uppercase;letter-spacing:0.04em;color:var(--slate);font-weight:600;margin-bottom:8px;">Services Under Contract</div>
                        <div class="key-services">
                            <?php foreach (explode(',', $crmClient['key_services']) as $svc): ?>
                                <?php $svc = trim($svc); if ($svc): ?>
                                    <span class="service-tag"><?php echo htmlspecialchars($svc); ?></span>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="section-card">
                <div class="section-card-body">
                    <div class="empty-state">
                        <div class="empty-icon">&#128196;</div>
                        <p>Contract details will appear here once your account is linked by the IGNYTE team.</p>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- ========== DEVICES PANEL ========== -->
    <div class="tab-panel" id="panel-devices">
        <div class="section-card">
            <div class="section-card-header">
                <h2>Devices Under Management</h2>
                <span style="font-size:0.85rem;color:var(--slate);font-weight:600;"><?php echo $activeDevices; ?> active</span>
            </div>
            <div class="section-card-body">
                <?php if (empty($devices)): ?>
                    <div class="empty-state">
                        <div class="empty-icon">&#128187;</div>
                        <p>No devices under management yet. Your managed devices will appear here once configured by our team.</p>
                    </div>
                <?php else: ?>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Device</th>
                                <th>Type</th>
                                <th>Hostname</th>
                                <th>OS</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($devices as $dev): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($dev['device_name']); ?></strong></td>
                                <td style="color:var(--slate);"><?php echo htmlspecialchars(ucfirst($dev['device_type'])); ?></td>
                                <td style="font-family:monospace;font-size:0.85rem;color:var(--slate);"><?php echo htmlspecialchars($dev['hostname'] ?: '—'); ?></td>
                                <td style="color:var(--slate);"><?php echo htmlspecialchars($dev['os'] ?: '—'); ?></td>
                                <td><span class="badge badge-<?php echo $dev['status']; ?>"><?php echo ucfirst($dev['status']); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ========== PLATFORMS PANEL ========== -->
    <div class="tab-panel" id="panel-platforms">
        <div class="section-card">
            <div class="section-card-header">
                <h2>Platforms Under Management</h2>
                <span style="font-size:0.85rem;color:var(--slate);font-weight:600;"><?php echo $activePlatforms; ?> active</span>
            </div>
            <div class="section-card-body">
                <?php if (empty($platforms)): ?>
                    <div class="empty-state">
                        <div class="empty-icon">&#9729;</div>
                        <p>No platforms under management yet. Your managed platforms and services will appear here.</p>
                    </div>
                <?php else: ?>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Platform</th>
                                <th>Type</th>
                                <th>Licenses</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($platforms as $plat): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($plat['platform_name']); ?></strong></td>
                                <td style="color:var(--slate);"><?php echo htmlspecialchars(ucfirst($plat['platform_type'])); ?></td>
                                <td><?php echo $plat['license_count'] ?: '—'; ?></td>
                                <td><span class="badge badge-<?php echo $plat['status']; ?>"><?php echo ucfirst($plat['status']); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($tools)): ?>
        <div class="section-card">
            <div class="section-card-header"><h2>Tools &amp; Licenses</h2></div>
            <div class="section-card-body">
                <table class="data-table">
                    <thead>
                        <tr><th>Tool</th><th>Vendor</th><th>Expires</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($tools as $tool): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($tool['tool_name']); ?></strong></td>
                            <td style="color:var(--slate);"><?php echo htmlspecialchars($tool['vendor'] ?: '—'); ?></td>
                            <td style="font-size:0.85rem;<?php
                                if ($tool['expiry_date'] && strtotime($tool['expiry_date']) < strtotime('+30 days')) echo 'color:#dc2626;font-weight:700;';
                                else echo 'color:var(--slate);';
                            ?>"><?php echo $tool['expiry_date'] ? date('M j, Y', strtotime($tool['expiry_date'])) : '—'; ?></td>
                            <td><span class="badge badge-<?php echo $tool['status']; ?>"><?php echo ucfirst($tool['status']); ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- ========== PROJECTS PANEL ========== -->
    <div class="tab-panel" id="panel-projects">
        <?php $allProjects = array_merge($projects, $crmProjects); ?>
        <div class="section-card">
            <div class="section-card-header"><h2>Your Projects</h2></div>
            <div class="section-card-body">
                <?php if (empty($allProjects)): ?>
                    <div class="empty-state">
                        <div class="empty-icon">&#128203;</div>
                        <p>No projects yet. Your projects will appear here once they're set up by our team.</p>
                    </div>
                <?php else: ?>
                    <table class="data-table">
                        <thead>
                            <tr><th>Project</th><th>Status</th><th>Priority</th><th>Timeline</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($allProjects as $proj): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($proj['project_name']); ?></strong>
                                    <?php if (!empty($proj['description'])): ?>
                                        <div style="font-size:0.82rem;color:var(--slate);margin-top:2px;"><?php echo htmlspecialchars(substr($proj['description'], 0, 80)); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge badge-<?php echo $proj['status']; ?>"><?php echo ucfirst(str_replace('_', ' ', $proj['status'])); ?></span></td>
                                <td style="color:var(--slate);"><?php echo ucfirst($proj['priority'] ?? '—'); ?></td>
                                <td style="color:var(--slate);font-size:0.82rem;">
                                    <?php
                                    $s = $proj['start_date'] ?? null;
                                    $e = $proj['end_date'] ?? null;
                                    if ($s) echo date('M j', strtotime($s));
                                    if ($s && $e) echo ' – ' . date('M j, Y', strtotime($e));
                                    elseif (!$s) echo '—';
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ========== BILLING PANEL ========== -->
    <div class="tab-panel" id="panel-billing">
        <!-- Documents -->
        <div class="section-card">
            <div class="section-card-header"><h2>Shared Documents</h2></div>
            <div class="section-card-body">
                <?php if (empty($documents)): ?>
                    <div class="empty-state">
                        <div class="empty-icon">&#128196;</div>
                        <p>No documents shared yet. Files and deliverables will appear here.</p>
                    </div>
                <?php else: ?>
                    <table class="data-table">
                        <thead><tr><th>Document</th><th>Type</th><th>Date</th></tr></thead>
                        <tbody>
                        <?php foreach ($documents as $doc): ?>
                            <tr>
                                <td>
                                    <?php if ($doc['file_url']): ?>
                                        <a href="<?php echo htmlspecialchars($doc['file_url']); ?>" target="_blank" style="color:var(--brand-blue);font-weight:600;text-decoration:none;"><?php echo htmlspecialchars($doc['document_name']); ?></a>
                                    <?php else: ?>
                                        <?php echo htmlspecialchars($doc['document_name']); ?>
                                    <?php endif; ?>
                                </td>
                                <td style="color:var(--slate);font-size:0.82rem;"><?php echo htmlspecialchars($doc['document_type'] ?? 'File'); ?></td>
                                <td style="color:var(--slate);font-size:0.82rem;"><?php echo date('M j, Y', strtotime($doc['uploaded_at'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- Invoices -->
        <div class="section-card">
            <div class="section-card-header"><h2>Invoices &amp; Billing</h2></div>
            <div class="section-card-body">
                <?php if (empty($invoices)): ?>
                    <div class="empty-state">
                        <div class="empty-icon">&#128176;</div>
                        <p>No invoices yet. Your billing history will appear here.</p>
                    </div>
                <?php else: ?>
                    <table class="data-table">
                        <thead>
                            <tr><th>Invoice #</th><th>Description</th><th>Amount</th><th>Status</th><th>Date</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($invoices as $inv): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($inv['invoice_number']); ?></strong></td>
                                <td style="color:var(--slate);"><?php echo htmlspecialchars($inv['description']); ?></td>
                                <td><strong>$<?php echo number_format($inv['amount'], 2); ?></strong></td>
                                <td><span class="badge badge-<?php echo $inv['status']; ?>"><?php echo ucfirst($inv['status']); ?></span></td>
                                <td style="color:var(--slate);font-size:0.82rem;"><?php echo date('M j, Y', strtotime($inv['created_at'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ========== WEBSITE UPDATES PANEL ========== -->
    <?php if ($siteManagerEmbedUrl): ?>
    <div class="tab-panel" id="panel-website">
        <div class="section-card" style="overflow:hidden;">
            <div class="section-card-header">
                <h2>Website Updates</h2>
                <span style="font-size:0.82rem;color:var(--slate);">Powered by IGNYTE Site Manager</span>
            </div>
            <div style="padding:0;">
                <iframe
                    id="site-manager-embed"
                    src="<?php echo htmlspecialchars($siteManagerEmbedUrl); ?>"
                    style="width:100%;min-height:600px;border:none;"
                    allow="clipboard-write"
                    loading="lazy"
                ></iframe>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div>

<script>
function showPanel(name) {
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.dash-tab').forEach(t => t.classList.remove('active'));
    document.getElementById('panel-' + name).classList.add('active');
    event.target.classList.add('active');
}
</script>

</body>
</html>
