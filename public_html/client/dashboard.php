<?php
session_start();
require_once __DIR__ . '/../admin/config.php';

if (!isset($_SESSION['client_id'])) {
    header('Location: login.php');
    exit;
}

$pdo = getDB();
$clientId = $_SESSION['client_id'];

// Fetch client details
$stmt = $pdo->prepare('SELECT * FROM clients WHERE id = ?');
$stmt->execute([$clientId]);
$client = $stmt->fetch();

// Fetch client projects
$projStmt = $pdo->prepare('SELECT * FROM client_projects WHERE client_id = ? ORDER BY updated_at DESC');
$projStmt->execute([$clientId]);
$projects = $projStmt->fetchAll();

// Fetch client documents
$docStmt = $pdo->prepare('SELECT * FROM client_documents WHERE client_id = ? ORDER BY uploaded_at DESC LIMIT 10');
$docStmt->execute([$clientId]);
$documents = $docStmt->fetchAll();

// Fetch client invoices
$invStmt = $pdo->prepare('SELECT * FROM client_invoices WHERE client_id = ? ORDER BY created_at DESC LIMIT 10');
$invStmt->execute([$clientId]);
$invoices = $invStmt->fetchAll();

// Stats
$activeProjects = 0;
$totalInvoiced = 0;
$pendingInvoices = 0;
foreach ($projects as $p) { if ($p['status'] === 'active') $activeProjects++; }
foreach ($invoices as $i) {
    $totalInvoiced += $i['amount'];
    if ($i['status'] === 'pending' || $i['status'] === 'sent') $pendingInvoices++;
}
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
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'DM Sans', sans-serif; background: var(--light-grey); color: var(--navy); }

        /* Top bar */
        .top-bar {
            background: var(--navy);
            color: white;
            padding: 14px 0;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .top-bar-inner {
            max-width: 1180px;
            margin: 0 auto;
            padding: 0 28px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .top-bar-left { display: flex; align-items: center; gap: 16px; }
        .top-bar-left img { height: 36px; filter: brightness(0) invert(1); }
        .top-bar-left span { font-family: 'Inter', sans-serif; font-weight: 700; font-size: 0.95rem; opacity: 0.7; }
        .top-bar-right { display: flex; align-items: center; gap: 20px; }
        .top-bar-right .user-info { font-size: 0.88rem; }
        .top-bar-right .user-name { font-weight: 700; }
        .top-bar-right .user-company { opacity: 0.6; font-size: 0.8rem; }
        .btn-logout {
            background: rgba(255,255,255,0.1);
            color: white;
            border: none;
            padding: 8px 18px;
            border-radius: 6px;
            font-size: 0.82rem;
            font-weight: 700;
            cursor: pointer;
            font-family: 'DM Sans', sans-serif;
            text-decoration: none;
            transition: background 0.2s;
        }
        .btn-logout:hover { background: rgba(255,255,255,0.2); }

        /* Layout */
        .dashboard-wrap {
            max-width: 1180px;
            margin: 0 auto;
            padding: 32px 28px;
        }

        .welcome-banner {
            background: white;
            border-radius: 16px;
            padding: 32px;
            margin-bottom: 28px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.04);
        }
        .welcome-banner h1 {
            font-family: 'Inter', sans-serif;
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 6px;
        }
        .welcome-banner p { color: var(--slate); font-size: 0.95rem; }

        /* Stats row */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 28px;
        }
        .stat-box {
            background: white;
            border-radius: 14px;
            padding: 24px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.04);
        }
        .stat-box .stat-icon {
            width: 44px; height: 44px;
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.3rem;
            margin-bottom: 14px;
        }
        .stat-box .stat-icon.blue { background: rgba(0,71,187,0.1); }
        .stat-box .stat-icon.orange { background: rgba(238,90,36,0.1); }
        .stat-box .stat-icon.green { background: rgba(34,197,94,0.1); }
        .stat-box .stat-icon.purple { background: rgba(124,58,237,0.1); }
        .stat-box .stat-value {
            font-family: 'Inter', sans-serif;
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 4px;
        }
        .stat-box .stat-label { color: var(--slate); font-size: 0.82rem; text-transform: uppercase; letter-spacing: 0.06em; }

        /* Sections grid */
        .sections-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            margin-bottom: 28px;
        }

        .section-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.04);
            overflow: hidden;
        }
        .section-card-header {
            padding: 20px 24px;
            border-bottom: 1px solid rgba(0,0,0,0.06);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .section-card-header h2 {
            font-family: 'Inter', sans-serif;
            font-size: 1.15rem;
            font-weight: 700;
        }
        .section-card-body { padding: 20px 24px; }

        /* Table */
        .data-table { width: 100%; border-collapse: collapse; }
        .data-table th {
            text-align: left;
            font-size: 0.76rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--slate);
            padding: 8px 0;
            border-bottom: 1px solid rgba(0,0,0,0.06);
        }
        .data-table td {
            padding: 12px 0;
            font-size: 0.9rem;
            border-bottom: 1px solid rgba(0,0,0,0.04);
            vertical-align: middle;
        }
        .data-table tr:last-child td { border-bottom: none; }

        /* Badges */
        .badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 50px;
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .badge-active { background: rgba(34,197,94,0.1); color: #16a34a; }
        .badge-completed { background: rgba(0,71,187,0.1); color: var(--brand-blue); }
        .badge-on-hold { background: rgba(238,90,36,0.1); color: var(--flame-orange); }
        .badge-paid { background: rgba(34,197,94,0.1); color: #16a34a; }
        .badge-pending { background: rgba(238,90,36,0.1); color: var(--flame-orange); }
        .badge-sent { background: rgba(0,71,187,0.1); color: var(--brand-blue); }

        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 32px 16px;
            color: var(--slate);
        }
        .empty-state .empty-icon { font-size: 2.4rem; margin-bottom: 12px; opacity: 0.4; }
        .empty-state p { font-size: 0.9rem; }

        /* Quick actions */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            margin-bottom: 28px;
        }
        .action-card {
            background: white;
            border-radius: 14px;
            padding: 24px;
            text-align: center;
            box-shadow: 0 2px 12px rgba(0,0,0,0.04);
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            color: var(--navy);
            border: 1.5px solid transparent;
        }
        .action-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 24px rgba(0,0,0,0.08);
            border-color: var(--brand-blue);
        }
        .action-card .action-icon { font-size: 2rem; margin-bottom: 12px; }
        .action-card h3 { font-family: 'Inter', sans-serif; font-size: 1rem; font-weight: 700; margin-bottom: 6px; }
        .action-card p { color: var(--slate); font-size: 0.82rem; }

        /* Responsive */
        @media (max-width: 768px) {
            .stats-row { grid-template-columns: repeat(2, 1fr); }
            .sections-grid { grid-template-columns: 1fr; }
            .quick-actions { grid-template-columns: 1fr; }
            .top-bar-right .user-info { display: none; }
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
        <p>Here's an overview of your projects, documents, and invoices with IGNYTE Consulting.</p>
    </div>

    <!-- Stats -->
    <div class="stats-row">
        <div class="stat-box">
            <div class="stat-icon blue">&#128203;</div>
            <div class="stat-value"><?php echo count($projects); ?></div>
            <div class="stat-label">Total Projects</div>
        </div>
        <div class="stat-box">
            <div class="stat-icon green">&#9889;</div>
            <div class="stat-value"><?php echo $activeProjects; ?></div>
            <div class="stat-label">Active Projects</div>
        </div>
        <div class="stat-box">
            <div class="stat-icon orange">&#128196;</div>
            <div class="stat-value"><?php echo count($documents); ?></div>
            <div class="stat-label">Documents</div>
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
            <p>Create a ticket for our team via our service desk</p>
        </a>
        <a href="mailto:contact@ignyteconsulting.com?subject=Project%20Inquiry" class="action-card">
            <div class="action-icon">&#128231;</div>
            <h3>Contact Your Account Manager</h3>
            <p>Reach out directly to discuss your projects</p>
        </a>
        <a href="mailto:contact@ignyteconsulting.com?subject=Schedule%20Meeting" class="action-card">
            <div class="action-icon">&#128197;</div>
            <h3>Schedule a Meeting</h3>
            <p>Book time with our consulting team</p>
        </a>
    </div>

    <!-- Projects & Documents -->
    <div class="sections-grid">
        <!-- Projects -->
        <div class="section-card">
            <div class="section-card-header">
                <h2>Your Projects</h2>
            </div>
            <div class="section-card-body">
                <?php if (empty($projects)): ?>
                    <div class="empty-state">
                        <div class="empty-icon">&#128203;</div>
                        <p>No projects yet. Your projects will appear here once they're set up by our team.</p>
                    </div>
                <?php else: ?>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Project</th>
                                <th>Status</th>
                                <th>Updated</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($projects as $proj): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($proj['project_name']); ?></strong></td>
                                <td><span class="badge badge-<?php echo $proj['status']; ?>"><?php echo ucfirst($proj['status']); ?></span></td>
                                <td style="color:var(--slate);font-size:0.82rem;"><?php echo date('M j, Y', strtotime($proj['updated_at'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- Documents -->
        <div class="section-card">
            <div class="section-card-header">
                <h2>Shared Documents</h2>
            </div>
            <div class="section-card-body">
                <?php if (empty($documents)): ?>
                    <div class="empty-state">
                        <div class="empty-icon">&#128196;</div>
                        <p>No documents shared yet. Files and deliverables will appear here.</p>
                    </div>
                <?php else: ?>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Document</th>
                                <th>Type</th>
                                <th>Date</th>
                            </tr>
                        </thead>
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
    </div>

    <!-- Invoices -->
    <div class="section-card" style="margin-bottom: 28px;">
        <div class="section-card-header">
            <h2>Invoices &amp; Billing</h2>
        </div>
        <div class="section-card-body">
            <?php if (empty($invoices)): ?>
                <div class="empty-state">
                    <div class="empty-icon">&#128176;</div>
                    <p>No invoices yet. Your billing history will appear here.</p>
                </div>
            <?php else: ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Invoice #</th>
                            <th>Description</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Date</th>
                        </tr>
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

</body>
</html>
