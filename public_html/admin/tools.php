<?php
session_start();
require_once __DIR__ . '/config.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$pdo = getDB();
$adminName = htmlspecialchars($_SESSION['admin_name']);

// Handle add/edit tool
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tool_action'])) {
    $action = $_POST['tool_action'];
    $toolName = trim($_POST['tool_name'] ?? '');
    $clientId = (int)($_POST['client_id'] ?? 0);
    $vendor = trim($_POST['vendor'] ?? '');
    $licenseKey = trim($_POST['license_key'] ?? '');
    $cost = trim($_POST['cost'] ?? '');
    $billingCycle = $_POST['billing_cycle'] ?? 'monthly';
    $startDate = $_POST['start_date'] ?: null;
    $expiryDate = $_POST['expiry_date'] ?: null;
    $status = $_POST['tool_status'] ?? 'active';
    $notes = trim($_POST['notes'] ?? '');

    if ($toolName) {
        if ($action === 'add') {
            $stmt = $pdo->prepare('INSERT INTO crm_tools (tool_name, client_id, vendor, license_key, cost, billing_cycle, start_date, expiry_date, status, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$toolName, $clientId ?: null, $vendor, $licenseKey, $cost, $billingCycle, $startDate, $expiryDate, $status, $notes]);
            header('Location: tools.php?added=1');
            exit;
        } elseif ($action === 'update' && isset($_POST['tool_id'])) {
            $stmt = $pdo->prepare('UPDATE crm_tools SET tool_name=?, client_id=?, vendor=?, license_key=?, cost=?, billing_cycle=?, start_date=?, expiry_date=?, status=?, notes=? WHERE id=?');
            $stmt->execute([$toolName, $clientId ?: null, $vendor, $licenseKey, $cost, $billingCycle, $startDate, $expiryDate, $status, $notes, $_POST['tool_id']]);
            header('Location: tools.php?updated=1');
            exit;
        }
    }
}

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_tool'])) {
    $pdo->prepare('DELETE FROM crm_tools WHERE id = ?')->execute([$_POST['delete_tool']]);
    header('Location: tools.php?deleted=1');
    exit;
}

// Fetch tools with client names
$tools = $pdo->query('SELECT t.*, c.full_name as client_name, c.company_name as client_company FROM crm_tools t LEFT JOIN crm_clients c ON t.client_id = c.id ORDER BY t.expiry_date ASC')->fetchAll();

// Fetch clients for dropdown
$clients = $pdo->query('SELECT id, full_name, company_name FROM crm_clients WHERE status = "active" ORDER BY full_name ASC')->fetchAll();

// If editing
$editTool = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM crm_tools WHERE id = ?');
    $stmt->execute([$_GET['edit']]);
    $editTool = $stmt->fetch();
}

// Calculate expiry warnings
$today = new DateTime();
$expiringSoon = array_filter($tools, function($t) use ($today) {
    if (!$t['expiry_date'] || $t['status'] !== 'active') return false;
    $expiry = new DateTime($t['expiry_date']);
    $diff = $today->diff($expiry);
    return !$diff->invert && $diff->days <= 30;
});
$expired = array_filter($tools, function($t) use ($today) {
    if (!$t['expiry_date'] || $t['status'] !== 'active') return false;
    $expiry = new DateTime($t['expiry_date']);
    return $expiry < $today;
});

// Filter
$filterView = $_GET['view'] ?? 'all';
$displayTools = $tools;
if ($filterView === 'expiring') {
    $displayTools = array_filter($tools, function($t) use ($today) {
        if (!$t['expiry_date'] || $t['status'] !== 'active') return false;
        $expiry = new DateTime($t['expiry_date']);
        $diff = $today->diff($expiry);
        return !$diff->invert && $diff->days <= 90;
    });
} elseif ($filterView === 'expired') {
    $displayTools = $expired;
} elseif ($filterView === 'active') {
    $displayTools = array_filter($tools, function($t) { return $t['status'] === 'active'; });
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tools &amp; Licenses | IGNYTE Consulting</title>
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
        .stat-box { background: white; padding: 20px 24px; border-radius: 14px; box-shadow: 0 2px 12px rgba(0,0,0,0.04); }
        .stat-box .num { font-family: 'Inter', sans-serif; font-size: 1.8rem; font-weight: 800; color: var(--navy); }
        .stat-box .label { font-size: 0.82rem; color: var(--slate); margin-top: 4px; }
        .stat-box.warning { border-left: 4px solid #ca8a04; }
        .stat-box.danger { border-left: 4px solid #dc2626; }

        .alert { padding: 12px 18px; border-radius: 10px; margin-bottom: 24px; font-size: 0.9rem; font-weight: 600; }
        .alert-success { background: rgba(34,197,94,0.1); color: #16a34a; border: 1px solid rgba(34,197,94,0.2); }
        .alert-warning { background: rgba(234,179,8,0.1); color: #ca8a04; border: 1px solid rgba(234,179,8,0.2); }

        .card { background: white; border-radius: 16px; box-shadow: 0 2px 16px rgba(0,0,0,0.05); padding: 32px; margin-bottom: 32px; }
        .card h3 { font-family: 'Inter', sans-serif; font-size: 1.3rem; margin-bottom: 24px; color: var(--navy); }
        .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 12px; }
        .card-header h3 { margin-bottom: 0; }

        .form-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; }
        .form-group { margin-bottom: 16px; }
        .form-group.full { grid-column: 1 / -1; }
        .form-group label { display: block; font-weight: 600; font-size: 0.85rem; color: var(--navy); margin-bottom: 6px; }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%; padding: 10px 14px; border: 1.5px solid rgba(0,0,0,0.1); border-radius: 8px;
            font-size: 0.92rem; font-family: 'DM Sans', sans-serif; outline: none; transition: border-color 0.2s;
        }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus { border-color: var(--brand-blue); }
        .form-group textarea { min-height: 80px; resize: vertical; line-height: 1.7; }

        .form-actions { display: flex; gap: 12px; margin-top: 8px; }
        .btn { padding: 10px 24px; border: none; border-radius: 8px; font-size: 0.9rem; font-weight: 700; font-family: 'DM Sans', sans-serif; cursor: pointer; transition: all 0.2s; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
        .btn-save { background: var(--flame-orange); color: white; box-shadow: 0 2px 12px rgba(238,90,36,0.3); }
        .btn-save:hover { background: var(--navy); }
        .btn-cancel { background: transparent; color: var(--slate); }
        .btn-cancel:hover { color: var(--navy); }

        .filter-bar { display: flex; gap: 8px; flex-wrap: wrap; }
        .filter-btn { padding: 6px 16px; border-radius: 50px; font-size: 0.82rem; font-weight: 600; border: 1.5px solid rgba(0,0,0,0.1); background: white; color: var(--slate); cursor: pointer; text-decoration: none; transition: all 0.2s; }
        .filter-btn:hover { border-color: var(--brand-blue); color: var(--brand-blue); }
        .filter-btn.active { background: var(--navy); color: white; border-color: var(--navy); }

        .tools-table { width: 100%; border-collapse: collapse; }
        .tools-table th { text-align: left; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.06em; color: var(--slate); padding: 12px 14px; border-bottom: 2px solid var(--light-grey); }
        .tools-table td { padding: 14px; border-bottom: 1px solid rgba(0,0,0,0.04); font-size: 0.88rem; vertical-align: middle; }
        .tools-table tr:hover td { background: rgba(0,71,187,0.02); }
        .tool-name { font-weight: 600; color: var(--navy); }
        .tool-vendor { font-size: 0.8rem; color: var(--slate); }

        .badge { display: inline-block; padding: 3px 10px; border-radius: 50px; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; }
        .badge-active { background: rgba(34,197,94,0.1); color: #16a34a; }
        .badge-inactive { background: rgba(0,0,0,0.06); color: var(--slate); }
        .badge-expired { background: rgba(220,38,38,0.1); color: #dc2626; }

        .expiry-ok { color: #16a34a; }
        .expiry-warn { color: #ca8a04; font-weight: 700; }
        .expiry-danger { color: #dc2626; font-weight: 700; }

        .action-btns { display: flex; gap: 6px; }
        .action-btns a, .action-btns button { padding: 5px 12px; border-radius: 6px; font-size: 0.8rem; font-weight: 600; text-decoration: none; cursor: pointer; border: none; font-family: 'DM Sans', sans-serif; transition: all 0.2s; }
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
            .tools-table th:nth-child(3), .tools-table td:nth-child(3),
            .tools-table th:nth-child(5), .tools-table td:nth-child(5) { display: none; }
        }
    </style>
</head>
<body>

<div class="topbar">
    <div class="topbar-left">
        <img src="../logo.png" alt="IGNYTE">
        <h2>Tools &amp; Licenses</h2>
    </div>
    <div class="topbar-right">
        <span>Welcome, <?php echo $adminName; ?></span>
        <a href="dashboard.php">Blog</a>
        <a href="clients.php">Clients</a>
        <a href="crm.php">Contacts</a>
        <a href="projects.php">Projects</a>
        <a href="quotes.php">Quotes</a>
        <a href="newsletters.php">Newsletters</a>
        <a href="tools.php" class="active-nav">Tools/Licenses</a>
        <a href="atlassian.php">Atlassian</a>
        <a href="../index.html">View Site</a>
        <a href="logout.php" class="logout-btn">Logout</a>
    </div>
</div>

<div class="dashboard">

    <?php if (isset($_GET['added'])): ?>
        <div class="alert alert-success">Tool/license added successfully!</div>
    <?php elseif (isset($_GET['updated'])): ?>
        <div class="alert alert-success">Tool/license updated successfully!</div>
    <?php elseif (isset($_GET['deleted'])): ?>
        <div class="alert alert-success">Tool/license deleted.</div>
    <?php endif; ?>

    <?php if (count($expiringSoon) > 0): ?>
        <div class="alert alert-warning"><?php echo count($expiringSoon); ?> tool(s) expiring within 30 days!</div>
    <?php endif; ?>

    <!-- Stats -->
    <?php
    $totalTools = count($tools);
    $activeTools = count(array_filter($tools, function($t) { return $t['status'] === 'active'; }));
    ?>
    <div class="stats-row">
        <div class="stat-box"><div class="num"><?php echo $totalTools; ?></div><div class="label">Total Tools</div></div>
        <div class="stat-box"><div class="num"><?php echo $activeTools; ?></div><div class="label">Active</div></div>
        <div class="stat-box warning"><div class="num"><?php echo count($expiringSoon); ?></div><div class="label">Expiring Soon (30d)</div></div>
        <div class="stat-box danger"><div class="num"><?php echo count($expired); ?></div><div class="label">Expired</div></div>
    </div>

    <!-- Add / Edit Tool -->
    <div class="card">
        <h3><?php echo $editTool ? 'Edit Tool/License' : 'Add New Tool/License'; ?></h3>
        <form method="POST">
            <input type="hidden" name="tool_action" value="<?php echo $editTool ? 'update' : 'add'; ?>">
            <?php if ($editTool): ?>
                <input type="hidden" name="tool_id" value="<?php echo $editTool['id']; ?>">
            <?php endif; ?>

            <div class="form-grid">
                <div class="form-group">
                    <label>Tool/Software Name *</label>
                    <input type="text" name="tool_name" required value="<?php echo htmlspecialchars($editTool['tool_name'] ?? ''); ?>" placeholder="Microsoft 365">
                </div>
                <div class="form-group">
                    <label>Vendor</label>
                    <input type="text" name="vendor" value="<?php echo htmlspecialchars($editTool['vendor'] ?? ''); ?>" placeholder="Microsoft">
                </div>
                <div class="form-group">
                    <label>Client</label>
                    <select name="client_id">
                        <option value="0">-- Internal / No Client --</option>
                        <?php foreach ($clients as $c): ?>
                            <option value="<?php echo $c['id']; ?>" <?php echo (($editTool['client_id'] ?? 0) == $c['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($c['full_name'] . ($c['company_name'] ? ' (' . $c['company_name'] . ')' : '')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>License Key</label>
                    <input type="text" name="license_key" value="<?php echo htmlspecialchars($editTool['license_key'] ?? ''); ?>" placeholder="XXXX-XXXX-XXXX">
                </div>
                <div class="form-group">
                    <label>Cost</label>
                    <input type="text" name="cost" value="<?php echo htmlspecialchars($editTool['cost'] ?? ''); ?>" placeholder="$29.99/mo">
                </div>
                <div class="form-group">
                    <label>Billing Cycle</label>
                    <select name="billing_cycle">
                        <?php foreach (['monthly', 'quarterly', 'annual', 'one_time', 'free'] as $bc): ?>
                            <option value="<?php echo $bc; ?>" <?php echo (($editTool['billing_cycle'] ?? 'monthly') === $bc) ? 'selected' : ''; ?>><?php echo ucfirst(str_replace('_', ' ', $bc)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Start Date</label>
                    <input type="date" name="start_date" value="<?php echo $editTool['start_date'] ?? ''; ?>">
                </div>
                <div class="form-group">
                    <label>Expiry / Renewal Date</label>
                    <input type="date" name="expiry_date" value="<?php echo $editTool['expiry_date'] ?? ''; ?>">
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select name="tool_status">
                        <option value="active" <?php echo (($editTool['status'] ?? 'active') === 'active') ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo (($editTool['status'] ?? '') === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                        <option value="expired" <?php echo (($editTool['status'] ?? '') === 'expired') ? 'selected' : ''; ?>>Expired</option>
                    </select>
                </div>
                <div class="form-group full">
                    <label>Notes</label>
                    <textarea name="notes" placeholder="License details, renewal notes..."><?php echo htmlspecialchars($editTool['notes'] ?? ''); ?></textarea>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-save"><?php echo $editTool ? 'Update' : 'Add Tool/License'; ?></button>
                <?php if ($editTool): ?>
                    <a href="tools.php" class="btn btn-cancel">Cancel</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Tools List -->
    <div class="card">
        <div class="card-header">
            <h3>All Tools &amp; Licenses (<?php echo count($displayTools); ?>)</h3>
            <div class="filter-bar">
                <a href="tools.php?view=all" class="filter-btn <?php echo $filterView === 'all' ? 'active' : ''; ?>">All</a>
                <a href="tools.php?view=active" class="filter-btn <?php echo $filterView === 'active' ? 'active' : ''; ?>">Active</a>
                <a href="tools.php?view=expiring" class="filter-btn <?php echo $filterView === 'expiring' ? 'active' : ''; ?>">Expiring (90d)</a>
                <a href="tools.php?view=expired" class="filter-btn <?php echo $filterView === 'expired' ? 'active' : ''; ?>">Expired</a>
            </div>
        </div>

        <?php if (empty($displayTools)): ?>
            <div class="empty-state">
                <span>&#128295;</span>
                <p>No tools/licenses found</p>
                <p style="font-size:0.9rem;">Add your first tool using the form above.</p>
            </div>
        <?php else: ?>
            <table class="tools-table">
                <thead>
                    <tr>
                        <th>Tool</th>
                        <th>Client</th>
                        <th>Cost</th>
                        <th>Status</th>
                        <th>Expiry</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($displayTools as $t):
                        $expiryClass = 'expiry-ok';
                        $expiryLabel = '-';
                        if ($t['expiry_date']) {
                            $expiry = new DateTime($t['expiry_date']);
                            $diff = $today->diff($expiry);
                            $expiryLabel = date('M j, Y', strtotime($t['expiry_date']));
                            if ($diff->invert) {
                                $expiryClass = 'expiry-danger';
                                $expiryLabel .= ' (EXPIRED)';
                            } elseif ($diff->days <= 30) {
                                $expiryClass = 'expiry-danger';
                                $expiryLabel .= ' (' . $diff->days . 'd)';
                            } elseif ($diff->days <= 90) {
                                $expiryClass = 'expiry-warn';
                                $expiryLabel .= ' (' . $diff->days . 'd)';
                            }
                        }
                    ?>
                    <tr>
                        <td>
                            <div class="tool-name"><?php echo htmlspecialchars($t['tool_name']); ?></div>
                            <?php if ($t['vendor']): ?>
                                <div class="tool-vendor"><?php echo htmlspecialchars($t['vendor']); ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($t['client_name']): ?>
                                <?php echo htmlspecialchars($t['client_name']); ?>
                                <?php if ($t['client_company']): ?>
                                    <div class="tool-vendor"><?php echo htmlspecialchars($t['client_company']); ?></div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span style="color:var(--slate);">Internal</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($t['cost'] ?: '-'); ?></td>
                        <td><span class="badge badge-<?php echo $t['status']; ?>"><?php echo ucfirst($t['status']); ?></span></td>
                        <td><span class="<?php echo $expiryClass; ?>"><?php echo $expiryLabel; ?></span></td>
                        <td>
                            <div class="action-btns">
                                <a href="tools.php?edit=<?php echo $t['id']; ?>" class="btn-edit">Edit</a>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this tool?');">
                                    <input type="hidden" name="delete_tool" value="<?php echo $t['id']; ?>">
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
