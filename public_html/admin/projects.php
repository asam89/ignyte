<?php
session_start();
require_once __DIR__ . '/config.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$pdo = getDB();
$adminName = htmlspecialchars($_SESSION['admin_name']);

// Handle add/edit project
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['project_action'])) {
    $action = $_POST['project_action'];
    $name = trim($_POST['project_name'] ?? '');
    $clientId = (int)($_POST['client_id'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $status = $_POST['project_status'] ?? 'planned';
    $priority = $_POST['priority'] ?? 'medium';
    $startDate = $_POST['start_date'] ?: null;
    $endDate = $_POST['end_date'] ?: null;
    $budget = trim($_POST['budget'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    if ($name) {
        if ($action === 'add') {
            $stmt = $pdo->prepare('INSERT INTO crm_projects (project_name, client_id, description, status, priority, start_date, end_date, budget, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$name, $clientId ?: null, $description, $status, $priority, $startDate, $endDate, $budget, $notes]);
            header('Location: projects.php?added=1');
            exit;
        } elseif ($action === 'update' && isset($_POST['project_id'])) {
            $stmt = $pdo->prepare('UPDATE crm_projects SET project_name=?, client_id=?, description=?, status=?, priority=?, start_date=?, end_date=?, budget=?, notes=? WHERE id=?');
            $stmt->execute([$name, $clientId ?: null, $description, $status, $priority, $startDate, $endDate, $budget, $notes, $_POST['project_id']]);
            header('Location: projects.php?updated=1');
            exit;
        }
    }
}

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_project'])) {
    $pdo->prepare('DELETE FROM crm_projects WHERE id = ?')->execute([$_POST['delete_project']]);
    header('Location: projects.php?deleted=1');
    exit;
}

// Fetch projects with client names
$projects = $pdo->query('SELECT p.*, c.full_name as client_name, c.company_name as client_company FROM crm_projects p LEFT JOIN crm_clients c ON p.client_id = c.id ORDER BY CASE p.status WHEN "active" THEN 1 WHEN "planned" THEN 2 WHEN "on_hold" THEN 3 WHEN "completed" THEN 4 ELSE 5 END, p.start_date ASC')->fetchAll();

// Fetch clients for dropdown
$clients = $pdo->query('SELECT id, full_name, company_name FROM crm_clients WHERE status = "active" ORDER BY full_name ASC')->fetchAll();

// If editing
$editProject = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM crm_projects WHERE id = ?');
    $stmt->execute([$_GET['edit']]);
    $editProject = $stmt->fetch();
}

// Filter
$filterStatus = $_GET['status'] ?? 'all';
$displayProjects = $projects;
if ($filterStatus !== 'all') {
    $displayProjects = array_filter($projects, function($p) use ($filterStatus) { return $p['status'] === $filterStatus; });
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Projects | IGNYTE Consulting</title>
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

        .alert { padding: 12px 18px; border-radius: 10px; margin-bottom: 24px; font-size: 0.9rem; font-weight: 600; }
        .alert-success { background: rgba(34,197,94,0.1); color: #16a34a; border: 1px solid rgba(34,197,94,0.2); }

        .card { background: white; border-radius: 16px; box-shadow: 0 2px 16px rgba(0,0,0,0.05); padding: 32px; margin-bottom: 32px; }
        .card h3 { font-family: 'Inter', sans-serif; font-size: 1.3rem; margin-bottom: 24px; color: var(--navy); }
        .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 12px; }
        .card-header h3 { margin-bottom: 0; }

        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
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

        .projects-table { width: 100%; border-collapse: collapse; }
        .projects-table th { text-align: left; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.06em; color: var(--slate); padding: 12px 14px; border-bottom: 2px solid var(--light-grey); }
        .projects-table td { padding: 14px; border-bottom: 1px solid rgba(0,0,0,0.04); font-size: 0.88rem; vertical-align: middle; }
        .projects-table tr:hover td { background: rgba(0,71,187,0.02); }
        .project-name { font-weight: 600; color: var(--navy); }
        .project-client { font-size: 0.8rem; color: var(--slate); }

        .badge { display: inline-block; padding: 3px 10px; border-radius: 50px; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; }
        .badge-planned { background: rgba(0,71,187,0.1); color: var(--brand-blue); }
        .badge-active { background: rgba(34,197,94,0.1); color: #16a34a; }
        .badge-on_hold { background: rgba(234,179,8,0.15); color: #ca8a04; }
        .badge-completed { background: rgba(0,35,102,0.08); color: var(--navy); }
        .badge-cancelled { background: rgba(220,38,38,0.08); color: #dc2626; }
        .priority-high { color: #dc2626; font-weight: 700; }
        .priority-medium { color: #ca8a04; font-weight: 600; }
        .priority-low { color: #16a34a; font-weight: 600; }

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
            .projects-table th:nth-child(4), .projects-table td:nth-child(4),
            .projects-table th:nth-child(5), .projects-table td:nth-child(5) { display: none; }
        }
    </style>
</head>
<body>

<div class="topbar">
    <div class="topbar-left">
        <img src="../logo.png" alt="IGNYTE">
        <h2>Projects</h2>
    </div>
    <div class="topbar-right">
        <span>Welcome, <?php echo $adminName; ?></span>
        <a href="dashboard.php">Blog</a>
        <a href="clients.php">Clients</a>
        <a href="crm.php">Contacts</a>
        <a href="projects.php" class="active-nav">Projects</a>
        <a href="quotes.php">Quotes</a>
        <a href="newsletters.php">Newsletters</a>
        <a href="tools.php">Tools/Licenses</a>
        <a href="../index.html">View Site</a>
        <a href="logout.php" class="logout-btn">Logout</a>
    </div>
</div>

<div class="dashboard">

    <?php if (isset($_GET['added'])): ?>
        <div class="alert alert-success">Project added successfully!</div>
    <?php elseif (isset($_GET['updated'])): ?>
        <div class="alert alert-success">Project updated successfully!</div>
    <?php elseif (isset($_GET['deleted'])): ?>
        <div class="alert alert-success">Project deleted.</div>
    <?php endif; ?>

    <!-- Stats -->
    <?php
    $totalProj = count($projects);
    $activeProj = count(array_filter($projects, function($p) { return $p['status'] === 'active'; }));
    $plannedProj = count(array_filter($projects, function($p) { return $p['status'] === 'planned'; }));
    $completedProj = count(array_filter($projects, function($p) { return $p['status'] === 'completed'; }));
    ?>
    <div class="stats-row">
        <div class="stat-box"><div class="num"><?php echo $totalProj; ?></div><div class="label">Total Projects</div></div>
        <div class="stat-box"><div class="num"><?php echo $activeProj; ?></div><div class="label">Active</div></div>
        <div class="stat-box"><div class="num"><?php echo $plannedProj; ?></div><div class="label">Planned</div></div>
        <div class="stat-box"><div class="num"><?php echo $completedProj; ?></div><div class="label">Completed</div></div>
    </div>

    <!-- Add / Edit Project -->
    <div class="card">
        <h3><?php echo $editProject ? 'Edit Project' : 'Add New Project'; ?></h3>
        <form method="POST">
            <input type="hidden" name="project_action" value="<?php echo $editProject ? 'update' : 'add'; ?>">
            <?php if ($editProject): ?>
                <input type="hidden" name="project_id" value="<?php echo $editProject['id']; ?>">
            <?php endif; ?>

            <div class="form-grid">
                <div class="form-group">
                    <label>Project Name *</label>
                    <input type="text" name="project_name" required value="<?php echo htmlspecialchars($editProject['project_name'] ?? ''); ?>" placeholder="Website Redesign">
                </div>
                <div class="form-group">
                    <label>Client</label>
                    <select name="client_id">
                        <option value="0">-- No Client --</option>
                        <?php foreach ($clients as $c): ?>
                            <option value="<?php echo $c['id']; ?>" <?php echo (($editProject['client_id'] ?? 0) == $c['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($c['full_name'] . ($c['company_name'] ? ' (' . $c['company_name'] . ')' : '')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select name="project_status">
                        <?php foreach (['planned', 'active', 'on_hold', 'completed', 'cancelled'] as $s): ?>
                            <option value="<?php echo $s; ?>" <?php echo (($editProject['status'] ?? 'planned') === $s) ? 'selected' : ''; ?>><?php echo ucfirst(str_replace('_', ' ', $s)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Priority</label>
                    <select name="priority">
                        <?php foreach (['low', 'medium', 'high'] as $p): ?>
                            <option value="<?php echo $p; ?>" <?php echo (($editProject['priority'] ?? 'medium') === $p) ? 'selected' : ''; ?>><?php echo ucfirst($p); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Start Date</label>
                    <input type="date" name="start_date" value="<?php echo $editProject['start_date'] ?? ''; ?>">
                </div>
                <div class="form-group">
                    <label>End Date</label>
                    <input type="date" name="end_date" value="<?php echo $editProject['end_date'] ?? ''; ?>">
                </div>
                <div class="form-group">
                    <label>Budget</label>
                    <input type="text" name="budget" value="<?php echo htmlspecialchars($editProject['budget'] ?? ''); ?>" placeholder="$5,000">
                </div>
                <div class="form-group full">
                    <label>Description</label>
                    <textarea name="description" placeholder="Project scope and details..."><?php echo htmlspecialchars($editProject['description'] ?? ''); ?></textarea>
                </div>
                <div class="form-group full">
                    <label>Notes</label>
                    <textarea name="notes" placeholder="Internal notes..."><?php echo htmlspecialchars($editProject['notes'] ?? ''); ?></textarea>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-save"><?php echo $editProject ? 'Update Project' : 'Add Project'; ?></button>
                <?php if ($editProject): ?>
                    <a href="projects.php" class="btn btn-cancel">Cancel</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Projects List -->
    <div class="card">
        <div class="card-header">
            <h3>All Projects (<?php echo count($displayProjects); ?>)</h3>
            <div class="filter-bar">
                <a href="projects.php?status=all" class="filter-btn <?php echo $filterStatus === 'all' ? 'active' : ''; ?>">All</a>
                <a href="projects.php?status=active" class="filter-btn <?php echo $filterStatus === 'active' ? 'active' : ''; ?>">Active</a>
                <a href="projects.php?status=planned" class="filter-btn <?php echo $filterStatus === 'planned' ? 'active' : ''; ?>">Planned</a>
                <a href="projects.php?status=on_hold" class="filter-btn <?php echo $filterStatus === 'on_hold' ? 'active' : ''; ?>">On Hold</a>
                <a href="projects.php?status=completed" class="filter-btn <?php echo $filterStatus === 'completed' ? 'active' : ''; ?>">Completed</a>
            </div>
        </div>

        <?php if (empty($displayProjects)): ?>
            <div class="empty-state">
                <span>&#128204;</span>
                <p>No projects found</p>
                <p style="font-size:0.9rem;">Add your first project using the form above.</p>
            </div>
        <?php else: ?>
            <table class="projects-table">
                <thead>
                    <tr>
                        <th>Project</th>
                        <th>Client</th>
                        <th>Status</th>
                        <th>Priority</th>
                        <th>Timeline</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($displayProjects as $p): ?>
                    <tr>
                        <td>
                            <div class="project-name"><?php echo htmlspecialchars($p['project_name']); ?></div>
                            <?php if ($p['budget']): ?>
                                <div class="project-client"><?php echo htmlspecialchars($p['budget']); ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($p['client_name']): ?>
                                <?php echo htmlspecialchars($p['client_name']); ?>
                                <?php if ($p['client_company']): ?>
                                    <div class="project-client"><?php echo htmlspecialchars($p['client_company']); ?></div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span style="color:var(--slate);">-</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge badge-<?php echo $p['status']; ?>"><?php echo ucfirst(str_replace('_', ' ', $p['status'])); ?></span></td>
                        <td><span class="priority-<?php echo $p['priority']; ?>"><?php echo ucfirst($p['priority']); ?></span></td>
                        <td>
                            <?php if ($p['start_date']): ?>
                                <?php echo date('M j', strtotime($p['start_date'])); ?>
                                <?php if ($p['end_date']): ?>
                                    - <?php echo date('M j, Y', strtotime($p['end_date'])); ?>
                                <?php endif; ?>
                            <?php else: ?>
                                <span style="color:var(--slate);">TBD</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="action-btns">
                                <a href="projects.php?edit=<?php echo $p['id']; ?>" class="btn-edit">Edit</a>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this project?');">
                                    <input type="hidden" name="delete_project" value="<?php echo $p['id']; ?>">
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
