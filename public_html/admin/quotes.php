<?php
/**
 * IGNYTE Consulting - Admin Quotes & Contracts Dashboard
 * View submitted quotes, manage pricing, view signed contracts
 */
session_start();
if (!isset($_SESSION['admin_logged_in'])) { header('Location: login.php'); exit; }
require_once 'config.php';
$pdo = getDB();

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Update quote status
    if (isset($_POST['update_status'])) {
        $stmt = $pdo->prepare("UPDATE quotes SET status = ? WHERE id = ?");
        $stmt->execute([$_POST['new_status'], $_POST['quote_id']]);
        header('Location: quotes.php?tab=' . ($_GET['tab'] ?? 'quotes') . '&updated=1');
        exit;
    }
    
    // Add/update service pricing
    if (isset($_POST['save_service'])) {
        if (!empty($_POST['service_id'])) {
            $stmt = $pdo->prepare("UPDATE quote_services SET name=?, category=?, description=?, price_per_user=?, price_flat=?, billing_type=?, sort_order=?, is_active=? WHERE id=?");
            $stmt->execute([
                $_POST['svc_name'], $_POST['svc_category'], $_POST['svc_description'],
                $_POST['svc_price_per_user'] ?: 0, $_POST['svc_price_flat'] ?: 0,
                $_POST['svc_billing_type'], $_POST['svc_sort_order'] ?: 0, isset($_POST['svc_active']) ? 1 : 0,
                $_POST['service_id']
            ]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO quote_services (name, category, description, price_per_user, price_flat, billing_type, sort_order, is_active) VALUES (?,?,?,?,?,?,?,1)");
            $stmt->execute([
                $_POST['svc_name'], $_POST['svc_category'], $_POST['svc_description'],
                $_POST['svc_price_per_user'] ?: 0, $_POST['svc_price_flat'] ?: 0,
                $_POST['svc_billing_type'], $_POST['svc_sort_order'] ?: 0
            ]);
        }
        header('Location: quotes.php?tab=pricing&saved=1');
        exit;
    }
    
    // Delete service
    if (isset($_POST['delete_service'])) {
        $stmt = $pdo->prepare("DELETE FROM quote_services WHERE id = ?");
        $stmt->execute([$_POST['service_id']]);
        header('Location: quotes.php?tab=pricing&deleted=1');
        exit;
    }
}

// Fetch data
$tab = $_GET['tab'] ?? 'quotes';

$quotes = [];
$contracts = [];
$services = [];
$stats = ['total' => 0, 'pending' => 0, 'signed' => 0, 'monthly_revenue' => 0];

try {
    $quotes = $pdo->query("SELECT q.*, c.signer_name as contract_signer, c.signed_at as contract_date FROM quotes q LEFT JOIN contracts c ON c.quote_id = q.id ORDER BY q.created_at DESC")->fetchAll();
    
    $stats['total'] = count($quotes);
    foreach ($quotes as $q) {
        if ($q['status'] === 'pending') $stats['pending']++;
        if ($q['status'] === 'signed') { $stats['signed']++; $stats['monthly_revenue'] += $q['monthly_total']; }
    }
    
    $contracts = $pdo->query("SELECT c.*, q.reference_number, q.company_name, q.monthly_total, q.annual_total FROM contracts c JOIN quotes q ON q.id = c.quote_id ORDER BY c.signed_at DESC")->fetchAll();
    $services = $pdo->query("SELECT * FROM quote_services ORDER BY category, sort_order, name")->fetchAll();
} catch (Exception $e) {
    // Tables may not exist yet
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quotes & Contracts | IGNYTE Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Inter:wght@600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root { --navy: #0a1628; --flame-orange: #ee5a24; --slate: #5a6a7b; --light-grey: #f7f8fa; --green: #22c55e; }
        body { font-family: 'DM Sans', sans-serif; background: var(--light-grey); color: var(--navy); }
        
        .topbar { background: var(--navy); padding: 14px 24px; display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 100; }
        .topbar a { color: rgba(255,255,255,0.7); text-decoration: none; font-size: 0.85rem; font-weight: 500; padding: 6px 14px; border-radius: 6px; transition: 0.2s; }
        .topbar a:hover, .topbar a.active { color: white; background: rgba(255,255,255,0.1); }
        .topbar-brand { color: white; font-weight: 700; font-size: 1.1rem; }
        
        .main { max-width: 1200px; margin: 0 auto; padding: 32px 24px; }
        
        .page-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 28px; }
        .page-title { font-family: 'Inter', sans-serif; font-size: 1.6rem; }
        
        .stats-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 28px; }
        .stat-card { background: white; border-radius: 14px; padding: 20px 24px; box-shadow: 0 2px 12px rgba(0,0,0,0.04); }
        .stat-label { font-size: 0.78rem; color: var(--slate); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px; }
        .stat-value { font-family: 'Inter', sans-serif; font-size: 1.6rem; font-weight: 800; color: var(--navy); }
        .stat-value.orange { color: var(--flame-orange); }
        .stat-value.green { color: var(--green); }
        
        .tabs { display: flex; gap: 4px; margin-bottom: 24px; background: white; border-radius: 12px; padding: 4px; box-shadow: 0 2px 12px rgba(0,0,0,0.04); display: inline-flex; }
        .tab { padding: 10px 20px; border-radius: 10px; font-weight: 600; font-size: 0.88rem; cursor: pointer; color: var(--slate); text-decoration: none; transition: 0.2s; }
        .tab.active { background: var(--navy); color: white; }
        .tab:hover:not(.active) { color: var(--navy); }
        
        .card { background: white; border-radius: 16px; box-shadow: 0 2px 12px rgba(0,0,0,0.04); overflow: hidden; margin-bottom: 20px; }
        .card-header { padding: 20px 24px; border-bottom: 1px solid rgba(0,0,0,0.05); display: flex; align-items: center; justify-content: space-between; }
        .card-header h3 { font-size: 1rem; font-weight: 700; }
        
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 12px 16px; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.5px; color: var(--slate); border-bottom: 1px solid rgba(0,0,0,0.06); background: var(--light-grey); }
        td { padding: 14px 16px; font-size: 0.88rem; border-bottom: 1px solid rgba(0,0,0,0.04); }
        tr:hover td { background: rgba(0,0,0,0.01); }
        
        .badge { display: inline-block; padding: 4px 10px; border-radius: 6px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; }
        .badge-pending { background: rgba(245,158,11,0.15); color: #d97706; }
        .badge-signed { background: rgba(34,197,94,0.15); color: #16a34a; }
        .badge-expired { background: rgba(239,68,68,0.15); color: #dc2626; }
        .badge-reviewed { background: rgba(59,130,246,0.15); color: #2563eb; }
        
        .btn { padding: 8px 16px; border: none; border-radius: 8px; font-family: 'DM Sans', sans-serif; font-weight: 600; font-size: 0.82rem; cursor: pointer; transition: 0.2s; }
        .btn-primary { background: var(--flame-orange); color: white; }
        .btn-primary:hover { background: #d4741e; }
        .btn-sm { padding: 6px 12px; font-size: 0.78rem; }
        .btn-outline { background: transparent; border: 1.5px solid rgba(0,0,0,0.1); color: var(--navy); }
        .btn-outline:hover { border-color: var(--navy); }
        .btn-danger { background: rgba(239,68,68,0.1); color: #dc2626; border: 1px solid rgba(239,68,68,0.2); }
        .btn-danger:hover { background: rgba(239,68,68,0.2); }
        
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px; }
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; font-size: 0.82rem; font-weight: 600; color: var(--navy); margin-bottom: 6px; }
        .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 10px 14px; border: 1.5px solid rgba(0,0,0,0.1); border-radius: 10px; font-family: 'DM Sans', sans-serif; font-size: 0.9rem; outline: none; }
        .form-group input:focus, .form-group select:focus { border-color: var(--flame-orange); }
        
        .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center; }
        .modal-overlay.open { display: flex; }
        .modal { background: white; border-radius: 20px; padding: 32px; max-width: 600px; width: 90%; max-height: 80vh; overflow-y: auto; }
        .modal h3 { font-family: 'Inter', sans-serif; margin-bottom: 20px; }
        
        .empty-state { text-align: center; padding: 60px 24px; color: var(--slate); }
        .empty-state p { font-size: 0.95rem; }
        
        .quote-detail { padding: 24px; }
        .detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 20px; }
        .detail-item { }
        .detail-label { font-size: 0.75rem; color: var(--slate); text-transform: uppercase; }
        .detail-value { font-weight: 600; font-size: 0.92rem; }
        
        .svc-table td { vertical-align: middle; }
        .svc-actions { display: flex; gap: 6px; }
    </style>
</head>
<body>

<div class="topbar">
    <span class="topbar-brand">IGNYTE Admin</span>
    <nav>
        <a href="dashboard.php">Dashboard</a>
        <a href="clients.php">Clients</a>
        <a href="crm.php">Contacts</a>
        <a href="projects.php">Projects</a>
        <a href="quotes.php" class="active">Quotes</a>
        <a href="tools.php">Tools</a>
        <a href="logout.php">Logout</a>
    </nav>
</div>

<div class="main">
    <div class="page-header">
        <h1 class="page-title">Quotes & Contracts</h1>
        <a href="/quote.php" target="_blank" class="btn btn-primary">View Public Quote Page &rarr;</a>
    </div>
    
    <!-- Stats -->
    <div class="stats-row">
        <div class="stat-card">
            <div class="stat-label">Total Quotes</div>
            <div class="stat-value"><?= $stats['total'] ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Pending Review</div>
            <div class="stat-value orange"><?= $stats['pending'] ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Signed Contracts</div>
            <div class="stat-value green"><?= $stats['signed'] ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Monthly Revenue (Signed)</div>
            <div class="stat-value green">$<?= number_format($stats['monthly_revenue'], 0) ?></div>
        </div>
    </div>
    
    <!-- Tabs -->
    <div class="tabs">
        <a href="quotes.php?tab=quotes" class="tab <?= $tab === 'quotes' ? 'active' : '' ?>">Quotes</a>
        <a href="quotes.php?tab=contracts" class="tab <?= $tab === 'contracts' ? 'active' : '' ?>">Signed Contracts</a>
        <a href="quotes.php?tab=pricing" class="tab <?= $tab === 'pricing' ? 'active' : '' ?>">Pricing Table</a>
    </div>
    
    <?php if ($tab === 'quotes'): ?>
    <!-- Quotes Table -->
    <div class="card">
        <?php if (empty($quotes)): ?>
            <div class="empty-state">
                <p>No quotes submitted yet. Share the <a href="/quote.php" target="_blank">quote page</a> with prospects to start receiving quotes.</p>
            </div>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Reference</th>
                    <th>Company</th>
                    <th>Contact</th>
                    <th>Monthly</th>
                    <th>Status</th>
                    <th>Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($quotes as $q): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($q['reference_number']) ?></strong></td>
                    <td><?= htmlspecialchars($q['company_name']) ?></td>
                    <td>
                        <?= htmlspecialchars($q['contact_name']) ?><br>
                        <span style="font-size:0.78rem;color:var(--slate);"><?= htmlspecialchars($q['contact_email']) ?></span>
                    </td>
                    <td><strong>$<?= number_format($q['monthly_total'], 0) ?></strong>/mo</td>
                    <td>
                        <span class="badge badge-<?= $q['status'] ?>"><?= ucfirst($q['status']) ?></span>
                    </td>
                    <td style="font-size:0.82rem;color:var(--slate);"><?= date('M j, Y', strtotime($q['created_at'])) ?></td>
                    <td>
                        <button class="btn btn-sm btn-outline" onclick="viewQuote(<?= $q['id'] ?>)">View</button>
                        <?php if ($q['status'] === 'pending'): ?>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="quote_id" value="<?= $q['id'] ?>">
                            <input type="hidden" name="new_status" value="reviewed">
                            <button type="submit" name="update_status" class="btn btn-sm btn-primary">Mark Reviewed</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    
    <?php elseif ($tab === 'contracts'): ?>
    <!-- Contracts Table -->
    <div class="card">
        <?php if (empty($contracts)): ?>
            <div class="empty-state">
                <p>No signed contracts yet. Contracts are created when prospects sign their quote on the public page.</p>
            </div>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Quote Ref</th>
                    <th>Company</th>
                    <th>Signer</th>
                    <th>Monthly Value</th>
                    <th>Signed At</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($contracts as $c): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($c['reference_number']) ?></strong></td>
                    <td><?= htmlspecialchars($c['company_name']) ?></td>
                    <td>
                        <?= htmlspecialchars($c['signer_name']) ?><br>
                        <span style="font-size:0.78rem;color:var(--slate);"><?= htmlspecialchars($c['signer_email']) ?></span>
                    </td>
                    <td><strong>$<?= number_format($c['monthly_total'], 0) ?></strong>/mo</td>
                    <td style="font-size:0.82rem;"><?= date('M j, Y g:i A', strtotime($c['signed_at'])) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    
    <?php elseif ($tab === 'pricing'): ?>
    <!-- Pricing Table Management -->
    <div class="card">
        <div class="card-header">
            <h3>Service Pricing</h3>
            <button class="btn btn-primary" onclick="openServiceModal()">+ Add Service</button>
        </div>
        <?php if (empty($services)): ?>
            <div class="empty-state">
                <p>No services configured yet. Add services here and they&rsquo;ll appear on the public quote page. Default pricing is used until you configure custom services.</p>
                <button class="btn btn-primary" onclick="openServiceModal()" style="margin-top:16px;">Add Your First Service</button>
            </div>
        <?php else: ?>
        <table class="svc-table">
            <thead>
                <tr>
                    <th>Service</th>
                    <th>Category</th>
                    <th>Per User</th>
                    <th>Flat Fee</th>
                    <th>Billing</th>
                    <th>Active</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($services as $svc): ?>
                <tr>
                    <td>
                        <strong><?= htmlspecialchars($svc['name']) ?></strong><br>
                        <span style="font-size:0.78rem;color:var(--slate);"><?= htmlspecialchars(substr($svc['description'], 0, 60)) ?></span>
                    </td>
                    <td><span class="badge" style="background:rgba(59,130,246,0.1);color:#2563eb;"><?= ucfirst($svc['category']) ?></span></td>
                    <td><?= $svc['price_per_user'] > 0 ? '$' . number_format($svc['price_per_user'], 0) : '-' ?></td>
                    <td><?= $svc['price_flat'] > 0 ? '$' . number_format($svc['price_flat'], 0) : '-' ?></td>
                    <td><?= ucfirst(str_replace('_', ' ', $svc['billing_type'])) ?></td>
                    <td><?= $svc['is_active'] ? '<span style="color:var(--green);">Yes</span>' : '<span style="color:#dc2626;">No</span>' ?></td>
                    <td class="svc-actions">
                        <button class="btn btn-sm btn-outline" onclick="editService(<?= htmlspecialchars(json_encode($svc)) ?>)">Edit</button>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this service?');">
                            <input type="hidden" name="service_id" value="<?= $svc['id'] ?>">
                            <button type="submit" name="delete_service" class="btn btn-sm btn-danger">Delete</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Quote Detail Modal -->
<div class="modal-overlay" id="quoteModal">
    <div class="modal" id="quoteModalContent">
        <!-- Filled by JS -->
    </div>
</div>

<!-- Service Form Modal -->
<div class="modal-overlay" id="serviceModal">
    <div class="modal">
        <h3 id="svcModalTitle">Add Service</h3>
        <form method="POST">
            <input type="hidden" name="service_id" id="svc_id">
            <div class="form-row">
                <div class="form-group">
                    <label>Service Name *</label>
                    <input type="text" name="svc_name" id="svc_name" required>
                </div>
                <div class="form-group">
                    <label>Category *</label>
                    <select name="svc_category" id="svc_category" required>
                        <option value="managed">Managed Services</option>
                        <option value="cloud">Cloud & Collaboration</option>
                        <option value="security">Security & Compliance</option>
                        <option value="backup">Backup & Recovery</option>
                        <option value="network">Network & Connectivity</option>
                        <option value="infrastructure">Infrastructure</option>
                        <option value="web">Web & Applications</option>
                        <option value="data">Data & Analytics</option>
                        <option value="apps">Applications</option>
                        <option value="consulting">Strategy & Consulting</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label>Description</label>
                <textarea name="svc_description" id="svc_description" rows="2"></textarea>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Price Per User (/month)</label>
                    <input type="number" name="svc_price_per_user" id="svc_price_per_user" step="0.01" value="0">
                </div>
                <div class="form-group">
                    <label>Flat Fee</label>
                    <input type="number" name="svc_price_flat" id="svc_price_flat" step="0.01" value="0">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Billing Type *</label>
                    <select name="svc_billing_type" id="svc_billing_type" required>
                        <option value="per_user">Per User/Month</option>
                        <option value="flat">Flat Monthly</option>
                        <option value="one_time">One-Time</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Sort Order</label>
                    <input type="number" name="svc_sort_order" id="svc_sort_order" value="0">
                </div>
            </div>
            <div class="form-group">
                <label><input type="checkbox" name="svc_active" id="svc_active" checked> Active (visible on quote page)</label>
            </div>
            <div style="display:flex;gap:12px;margin-top:20px;">
                <button type="button" class="btn btn-outline" onclick="closeServiceModal()">Cancel</button>
                <button type="submit" name="save_service" class="btn btn-primary">Save Service</button>
            </div>
        </form>
    </div>
</div>

<script>
var quotesData = <?= json_encode($quotes) ?>;

function viewQuote(id) {
    var q = quotesData.find(function(x) { return x.id == id; });
    if (!q) return;
    
    var services = [];
    try { services = JSON.parse(q.selected_services); } catch(e) {}
    var challenges = [];
    try { challenges = JSON.parse(q.challenges); } catch(e) {}
    
    var html = '<h3>Quote: ' + (q.reference_number || '') + '</h3>';
    html += '<div class="detail-grid">';
    html += '<div class="detail-item"><div class="detail-label">Company</div><div class="detail-value">' + (q.company_name || '-') + '</div></div>';
    html += '<div class="detail-item"><div class="detail-label">Contact</div><div class="detail-value">' + (q.contact_name || '-') + '</div></div>';
    html += '<div class="detail-item"><div class="detail-label">Email</div><div class="detail-value">' + (q.contact_email || '-') + '</div></div>';
    html += '<div class="detail-item"><div class="detail-label">Phone</div><div class="detail-value">' + (q.contact_phone || '-') + '</div></div>';
    html += '<div class="detail-item"><div class="detail-label">Industry</div><div class="detail-value">' + (q.industry || '-') + '</div></div>';
    html += '<div class="detail-item"><div class="detail-label">Employees</div><div class="detail-value">' + (q.employee_count || '-') + '</div></div>';
    html += '<div class="detail-item"><div class="detail-label">Current Setup</div><div class="detail-value">' + (q.current_setup || '-') + '</div></div>';
    html += '<div class="detail-item"><div class="detail-label">Cloud Status</div><div class="detail-value">' + (q.cloud_status || '-') + '</div></div>';
    html += '<div class="detail-item"><div class="detail-label">Support Level</div><div class="detail-value">' + (q.support_level || '-') + '</div></div>';
    html += '<div class="detail-item"><div class="detail-label">Monthly Total</div><div class="detail-value" style="color:var(--green);font-size:1.1rem;">$' + parseFloat(q.monthly_total || 0).toFixed(2) + '/mo</div></div>';
    html += '<div class="detail-item"><div class="detail-label">Annual Total</div><div class="detail-value">$' + parseFloat(q.annual_total || 0).toFixed(2) + '/yr</div></div>';
    html += '<div class="detail-item"><div class="detail-label">One-Time Fees</div><div class="detail-value">$' + parseFloat(q.one_time_total || 0).toFixed(2) + '</div></div>';
    html += '</div>';
    
    if (challenges.length > 0) {
        html += '<div style="margin-bottom:16px;"><strong style="font-size:0.82rem;color:var(--slate);">CHALLENGES:</strong><br>' + challenges.join(', ') + '</div>';
    }
    if (services.length > 0) {
        html += '<div style="margin-bottom:16px;"><strong style="font-size:0.82rem;color:var(--slate);">SELECTED SERVICES:</strong><ul style="padding-left:20px;margin-top:6px;font-size:0.88rem;">';
        services.forEach(function(s) { html += '<li>' + s + '</li>'; });
        html += '</ul></div>';
    }
    if (q.notes) {
        html += '<div style="margin-bottom:16px;"><strong style="font-size:0.82rem;color:var(--slate);">NOTES:</strong><p style="font-size:0.88rem;margin-top:4px;">' + q.notes + '</p></div>';
    }
    
    if (q.contract_signer) {
        html += '<div style="background:rgba(34,197,94,0.1);padding:16px;border-radius:12px;margin-top:16px;"><strong style="color:var(--green);">Signed by ' + q.contract_signer + '</strong> on ' + q.contract_date + '</div>';
    }
    
    html += '<div style="margin-top:20px;"><button class="btn btn-outline" onclick="closeQuoteModal()">Close</button></div>';
    
    document.getElementById('quoteModalContent').innerHTML = html;
    document.getElementById('quoteModal').classList.add('open');
}

function closeQuoteModal() {
    document.getElementById('quoteModal').classList.remove('open');
}

function openServiceModal() {
    document.getElementById('svcModalTitle').textContent = 'Add Service';
    document.getElementById('svc_id').value = '';
    document.getElementById('svc_name').value = '';
    document.getElementById('svc_category').value = 'managed';
    document.getElementById('svc_description').value = '';
    document.getElementById('svc_price_per_user').value = '0';
    document.getElementById('svc_price_flat').value = '0';
    document.getElementById('svc_billing_type').value = 'per_user';
    document.getElementById('svc_sort_order').value = '0';
    document.getElementById('svc_active').checked = true;
    document.getElementById('serviceModal').classList.add('open');
}

function editService(svc) {
    document.getElementById('svcModalTitle').textContent = 'Edit Service';
    document.getElementById('svc_id').value = svc.id;
    document.getElementById('svc_name').value = svc.name;
    document.getElementById('svc_category').value = svc.category;
    document.getElementById('svc_description').value = svc.description || '';
    document.getElementById('svc_price_per_user').value = svc.price_per_user || 0;
    document.getElementById('svc_price_flat').value = svc.price_flat || 0;
    document.getElementById('svc_billing_type').value = svc.billing_type;
    document.getElementById('svc_sort_order').value = svc.sort_order || 0;
    document.getElementById('svc_active').checked = svc.is_active == 1;
    document.getElementById('serviceModal').classList.add('open');
}

function closeServiceModal() {
    document.getElementById('serviceModal').classList.remove('open');
}

// Close modals on overlay click
document.getElementById('quoteModal').addEventListener('click', function(e) { if (e.target === this) closeQuoteModal(); });
document.getElementById('serviceModal').addEventListener('click', function(e) { if (e.target === this) closeServiceModal(); });
</script>

</body>
</html>
