<?php
session_start();
require_once __DIR__ . '/config.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$pdo = getDB();
$adminName = htmlspecialchars($_SESSION['admin_name']);

// Atlassian API helper
function atlassianRequest(string $endpoint, string $method = 'GET', ?array $body = null): ?array {
    if (!defined('ATLASSIAN_DOMAIN') || !ATLASSIAN_DOMAIN || !ATLASSIAN_API_TOKEN || !ATLASSIAN_EMAIL) {
        return null;
    }
    $base = 'https://' . ATLASSIAN_DOMAIN;
    $url = $base . $endpoint;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Basic ' . base64_encode(ATLASSIAN_EMAIL . ':' . ATLASSIAN_API_TOKEN),
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CUSTOMREQUEST => $method,
    ]);
    if ($body && $method !== 'GET') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error || $httpCode >= 400) {
        return ['_error' => true, '_code' => $httpCode, '_message' => $error ?: "HTTP $httpCode", '_body' => $response];
    }
    return json_decode($response, true) ?: [];
}

// Strip Atlassian wiki/storage markup to plain text
function stripAtlassianMarkup(string $html): string {
    $text = strip_tags($html);
    $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
    $text = preg_replace('/\s+/', ' ', $text);
    return trim($text);
}

$isConfigured = defined('ATLASSIAN_DOMAIN') && ATLASSIAN_DOMAIN && defined('ATLASSIAN_API_TOKEN') && ATLASSIAN_API_TOKEN && defined('ATLASSIAN_EMAIL') && ATLASSIAN_EMAIL;

$statusMsg = '';
$statusType = '';

// Test connection
$connectionOk = false;
$confluenceSpaces = [];
$jiraProjects = [];
if ($isConfigured) {
    $myself = atlassianRequest('/rest/api/3/myself');
    if ($myself && empty($myself['_error'])) {
        $connectionOk = true;
        // Fetch Confluence spaces
        $spacesResp = atlassianRequest('/wiki/api/v2/spaces?limit=50');
        if ($spacesResp && empty($spacesResp['_error'])) {
            $confluenceSpaces = $spacesResp['results'] ?? [];
        }
        // Fetch Jira projects
        $projectsResp = atlassianRequest('/rest/api/3/project?maxResults=50');
        if ($projectsResp && empty($projectsResp['_error'])) {
            $jiraProjects = is_array($projectsResp) ? $projectsResp : [];
        }
    }
}

// Fetch clients for mapping
$clients = $pdo->query('SELECT id, company_name, client_code FROM crm_clients WHERE is_client = 1 ORDER BY company_name ASC')->fetchAll();

// Handle Confluence import to Book of Truth
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confluence_import'])) {
    $spaceKey = trim($_POST['confluence_space'] ?? '');
    $clientId = (int)$_POST['target_client_id'];
    $importMode = $_POST['import_mode'] ?? 'all'; // 'all' or 'search'
    $searchQuery = trim($_POST['confluence_search'] ?? '');

    if ($spaceKey && $clientId) {
        $importedNotes = 0;

        if ($importMode === 'search' && $searchQuery) {
            // Search Confluence via CQL
            $cql = urlencode("space=\"$spaceKey\" AND text~\"$searchQuery\"");
            $pagesResp = atlassianRequest("/wiki/rest/api/content/search?cql=$cql&limit=25&expand=body.storage");
        } else {
            // Get all pages in space
            $pagesResp = atlassianRequest("/wiki/rest/api/content?spaceKey=$spaceKey&type=page&limit=50&expand=body.storage");
        }

        if ($pagesResp && empty($pagesResp['_error'])) {
            $pages = $pagesResp['results'] ?? [];
            $noteStmt = $pdo->prepare('INSERT INTO client_notes (client_id, category, title, content, source) VALUES (?, ?, ?, ?, ?)');

            foreach ($pages as $page) {
                $title = $page['title'] ?? 'Untitled';
                $bodyHtml = $page['body']['storage']['value'] ?? '';
                $plainText = stripAtlassianMarkup($bodyHtml);

                if (empty($plainText)) continue;

                // Auto-categorize based on title/content keywords
                $category = 'general';
                $titleLower = strtolower($title);
                if (preg_match('/(network|server|firewall|switch|router|infrastructure|rack|ups|dc|domain controller)/i', $titleLower)) {
                    $category = 'infrastructure';
                } elseif (preg_match('/(isp|internet|bandwidth|circuit|wan|connectivity)/i', $titleLower)) {
                    $category = 'isp';
                } elseif (preg_match('/(address|office|location|site|building)/i', $titleLower)) {
                    $category = 'address';
                } elseif (preg_match('/(credential|password|login|access|portal)/i', $titleLower)) {
                    $category = 'credentials';
                } elseif (preg_match('/(contact|directory|phone|people|team)/i', $titleLower)) {
                    $category = 'contact';
                } elseif (preg_match('/(contract|agreement|sla|billing|payment|renewal)/i', $titleLower)) {
                    $category = 'contract';
                } elseif (preg_match('/(software|license|subscription|365|office|antivirus|saas)/i', $titleLower)) {
                    $category = 'software';
                }

                // Truncate content to reasonable length
                $content = mb_substr($plainText, 0, 5000);
                $pageUrl = 'https://' . ATLASSIAN_DOMAIN . '/wiki' . ($page['_links']['webui'] ?? '');
                $content .= "\n\n[Source: Confluence - $pageUrl]";

                $noteStmt->execute([$clientId, $category, "Confluence: $title", $content, 'ai-parsed']);
                $importedNotes++;
            }
        }

        if ($importedNotes > 0) {
            $statusMsg = "Imported $importedNotes Confluence page(s) into the client's Book of Truth.";
            $statusType = 'success';
        } else {
            $statusMsg = 'No pages found or import failed. Check that the space key is correct and contains pages.';
            $statusType = 'error';
        }
    }
}

// Handle Jira import to Projects
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['jira_import'])) {
    $jiraProjectKey = trim($_POST['jira_project'] ?? '');
    $clientId = (int)$_POST['jira_client_id'];
    $importStatus = $_POST['jira_status_filter'] ?? 'open'; // 'open', 'all', 'done'

    if ($jiraProjectKey && $clientId) {
        $importedTickets = 0;
        $skippedTickets = 0;

        // Build JQL query
        $jql = "project=\"$jiraProjectKey\"";
        if ($importStatus === 'open') {
            $jql .= ' AND statusCategory != "Done"';
        } elseif ($importStatus === 'done') {
            $jql .= ' AND statusCategory = "Done"';
        }
        $jql .= ' ORDER BY created DESC';

        $issuesResp = atlassianRequest('/rest/api/3/search?jql=' . urlencode($jql) . '&maxResults=100&fields=summary,status,priority,created,updated,assignee,issuetype,description');

        if ($issuesResp && empty($issuesResp['_error'])) {
            $issues = $issuesResp['issues'] ?? [];
            $projectStmt = $pdo->prepare('INSERT INTO crm_projects (project_name, client_id, description, status, priority, start_date, notes) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $checkStmt = $pdo->prepare('SELECT id FROM crm_projects WHERE notes LIKE ? AND client_id = ?');

            foreach ($issues as $issue) {
                $key = $issue['key'] ?? '';
                $fields = $issue['fields'] ?? [];
                $summary = $fields['summary'] ?? 'Untitled';
                $jiraStatus = $fields['status']['name'] ?? 'Unknown';
                $jiraPriority = strtolower($fields['priority']['name'] ?? 'medium');
                $issueType = $fields['issuetype']['name'] ?? 'Task';
                $created = $fields['created'] ?? '';
                $assignee = $fields['assignee']['displayName'] ?? 'Unassigned';

                // Map Jira status to project status
                $statusCat = strtolower($fields['status']['statusCategory']['name'] ?? '');
                $projectStatus = 'planned';
                if ($statusCat === 'in progress') $projectStatus = 'active';
                elseif ($statusCat === 'done') $projectStatus = 'completed';

                // Map Jira priority
                $projectPriority = 'medium';
                if (in_array($jiraPriority, ['highest', 'high', 'critical', 'blocker'])) $projectPriority = 'high';
                elseif (in_array($jiraPriority, ['low', 'lowest', 'trivial'])) $projectPriority = 'low';

                // Skip if already imported (check by Jira key in notes)
                $jiraRef = "[Jira: $key]";
                $checkStmt->execute(["%$jiraRef%", $clientId]);
                if ($checkStmt->fetch()) {
                    $skippedTickets++;
                    continue;
                }

                $startDate = $created ? date('Y-m-d', strtotime($created)) : null;
                $desc = "[$issueType] $summary\nStatus: $jiraStatus | Assignee: $assignee";
                $descBody = '';
                if (isset($fields['description']['content'])) {
                    foreach ($fields['description']['content'] as $block) {
                        if (isset($block['content'])) {
                            foreach ($block['content'] as $inline) {
                                $descBody .= ($inline['text'] ?? '') . ' ';
                            }
                        }
                        $descBody .= "\n";
                    }
                }
                if ($descBody) $desc .= "\n\n" . trim($descBody);

                $notes = "$jiraRef\nJira URL: https://" . ATLASSIAN_DOMAIN . "/browse/$key\nType: $issueType\nAssignee: $assignee";

                $projectStmt->execute([
                    "[$key] $summary",
                    $clientId,
                    mb_substr($desc, 0, 2000),
                    $projectStatus,
                    $projectPriority,
                    $startDate,
                    $notes
                ]);
                $importedTickets++;
            }
        }

        if ($importedTickets > 0 || $skippedTickets > 0) {
            $statusMsg = "Imported $importedTickets Jira ticket(s). Skipped $skippedTickets (already imported).";
            $statusType = 'success';
        } else {
            $statusMsg = 'No tickets found or import failed. Check that the project key is correct.';
            $statusType = 'error';
        }
    }
}

// Handle Jira ticket count sync for all clients
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sync_jira_counts'])) {
    // This syncs open ticket counts for each client that has Jira projects linked
    $projectRows = $pdo->query("SELECT client_id, notes FROM crm_projects WHERE notes LIKE '%[Jira:%'")->fetchAll();
    $countsByClient = [];
    foreach ($projectRows as $row) {
        $cid = $row['client_id'];
        if (!isset($countsByClient[$cid])) $countsByClient[$cid] = 0;
        $countsByClient[$cid]++;
    }
    $statusMsg = 'Jira ticket counts refreshed for ' . count($countsByClient) . ' client(s).';
    $statusType = 'success';
}

// Count synced items
$confluenceNotes = 0;
$jiraTickets = 0;
try {
    $confluenceNotes = $pdo->query("SELECT COUNT(*) FROM client_notes WHERE title LIKE 'Confluence:%'")->fetchColumn();
} catch (PDOException $e) {}
try {
    $jiraTickets = $pdo->query("SELECT COUNT(*) FROM crm_projects WHERE notes LIKE '%[Jira:%'")->fetchColumn();
} catch (PDOException $e) {}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Atlassian Integration | IGNYTE Consulting</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --brand-blue: #0047BB;
            --navy: #002366;
            --flame-orange: #EE5A24;
            --light-grey: #F4F7FA;
            --slate: #4A5568;
            --white: #FFFFFF;
            --success: #059669;
            --error: #dc2626;
            --atlassian-blue: #0052CC;
            --confluence-blue: #1868DB;
            --jira-blue: #0052CC;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'DM Sans', sans-serif; background: var(--light-grey); color: var(--navy); }

        .topbar {
            background: var(--navy); color: white; padding: 14px 28px;
            display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap;
        }
        .topbar-left { display: flex; align-items: center; gap: 16px; }
        .topbar-left img { height: 36px; filter: brightness(0) invert(1); }
        .topbar-left h2 { font-family: 'Inter', sans-serif; font-size: 1.1rem; font-weight: 800; }
        .topbar-right { display: flex; align-items: center; gap: 12px; font-size: 0.85rem; flex-wrap: wrap; }
        .topbar-right a {
            color: rgba(255,255,255,0.7); text-decoration: none; font-weight: 600;
            padding: 6px 14px; border-radius: 8px; transition: all 0.2s;
        }
        .topbar-right a:hover { background: rgba(255,255,255,0.1); color: white; }
        .topbar-right .active-nav { background: rgba(255,255,255,0.15); color: white; }
        .topbar-right .logout-btn { background: rgba(238,90,36,0.2); color: #ff8c42; }
        .topbar-right .logout-btn:hover { background: var(--flame-orange); color: white; }

        .dashboard { max-width: 1100px; margin: 32px auto; padding: 0 20px; }

        .stats-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .stat-box {
            background: white; border-radius: 14px; padding: 20px 24px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.04); text-align: center;
        }
        .stat-box .num { font-family: 'Inter', sans-serif; font-size: 1.8rem; font-weight: 800; color: var(--navy); }
        .stat-box .label { font-size: 0.82rem; color: var(--slate); margin-top: 4px; }
        .stat-box.connected .num { color: var(--success); }
        .stat-box.disconnected .num { color: var(--error); }
        .stat-box.atlassian .num { color: var(--atlassian-blue); }

        .card {
            background: white; border-radius: 14px; padding: 28px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.04); margin-bottom: 24px;
        }
        .card h3 { font-family: 'Inter', sans-serif; font-size: 1.2rem; margin-bottom: 18px; color: var(--navy); }

        .alert { padding: 14px 20px; border-radius: 10px; margin-bottom: 20px; font-size: 0.9rem; border: 1px solid; }
        .alert-success { background: rgba(5,150,105,0.08); border-color: rgba(5,150,105,0.2); color: #065f46; }
        .alert-error { background: rgba(220,38,38,0.08); border-color: rgba(220,38,38,0.2); color: #991b1b; }
        .alert-warning { background: rgba(234,179,8,0.08); border-color: rgba(234,179,8,0.25); color: #a16207; }

        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .form-group { display: flex; flex-direction: column; gap: 6px; }
        .form-group.full { grid-column: 1 / -1; }
        .form-group label { font-size: 0.82rem; font-weight: 600; color: var(--slate); text-transform: uppercase; letter-spacing: 0.5px; }
        .form-group input, .form-group select, .form-group textarea {
            padding: 10px 14px; border: 1.5px solid rgba(0,0,0,0.1); border-radius: 10px;
            font-size: 0.9rem; font-family: 'DM Sans', sans-serif;
        }

        .btn { padding: 10px 22px; border-radius: 10px; font-weight: 700; font-size: 0.88rem; border: none; cursor: pointer; font-family: 'DM Sans', sans-serif; transition: all 0.2s; display: inline-flex; align-items: center; gap: 8px; }
        .btn-confluence { background: var(--confluence-blue); color: white; }
        .btn-confluence:hover { background: #0747A6; }
        .btn-jira { background: var(--jira-blue); color: white; }
        .btn-jira:hover { background: #0747A6; }
        .btn-save { background: var(--brand-blue); color: white; }
        .btn-save:hover { background: var(--navy); }

        .integration-header { display: flex; align-items: center; gap: 14px; margin-bottom: 20px; }
        .integration-header img, .integration-header svg { width: 40px; height: 40px; }
        .integration-header .title { font-family: 'Inter', sans-serif; font-size: 1.15rem; font-weight: 700; }
        .integration-header .subtitle { font-size: 0.82rem; color: var(--slate); }

        .config-box {
            background: rgba(0,82,204,0.04); border: 1.5px solid rgba(0,82,204,0.12);
            border-radius: 12px; padding: 20px 24px; margin-bottom: 20px;
        }
        .config-box code { background: rgba(0,0,0,0.06); padding: 2px 8px; border-radius: 6px; font-size: 0.85rem; }

        .space-list, .project-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 12px; margin: 16px 0; }
        .space-item, .project-item {
            border: 1.5px solid rgba(0,0,0,0.08); border-radius: 10px; padding: 14px 18px;
            display: flex; justify-content: space-between; align-items: center; font-size: 0.88rem;
        }
        .space-item:hover, .project-item:hover { border-color: var(--atlassian-blue); background: rgba(0,82,204,0.02); }
        .item-name { font-weight: 600; }
        .item-key { color: var(--slate); font-size: 0.8rem; font-family: monospace; }

        @media (max-width: 768px) {
            .form-grid { grid-template-columns: 1fr; }
            .topbar { flex-direction: column; gap: 10px; text-align: center; }
            .space-list, .project-list { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<div class="topbar">
    <div class="topbar-left">
        <img src="../logo.png" alt="IGNYTE">
        <h2>Atlassian Integration</h2>
    </div>
    <div class="topbar-right">
        <span>Welcome, <?php echo $adminName; ?></span>
        <a href="dashboard.php">Blog</a>
        <a href="clients.php">Clients</a>
        <a href="crm.php">Contacts</a>
        <a href="projects.php">Projects</a>
        <a href="tools.php">Tools/Licenses</a>
        <a href="atlassian.php" class="active-nav">Atlassian</a>
        <a href="../index.html">View Site</a>
        <a href="logout.php" class="logout-btn">Logout</a>
    </div>
</div>

<div class="dashboard">

    <?php if ($statusMsg): ?>
        <div class="alert alert-<?php echo $statusType; ?>"><?php echo htmlspecialchars($statusMsg); ?></div>
    <?php endif; ?>

    <!-- Connection Status -->
    <div class="stats-row">
        <div class="stat-box <?php echo $connectionOk ? 'connected' : 'disconnected'; ?>">
            <div class="num"><?php echo $connectionOk ? '&#10003;' : '&#10007;'; ?></div>
            <div class="label">Connection</div>
        </div>
        <div class="stat-box atlassian">
            <div class="num"><?php echo count($confluenceSpaces); ?></div>
            <div class="label">Confluence Spaces</div>
        </div>
        <div class="stat-box atlassian">
            <div class="num"><?php echo count($jiraProjects); ?></div>
            <div class="label">Jira Projects</div>
        </div>
        <div class="stat-box">
            <div class="num"><?php echo $confluenceNotes; ?></div>
            <div class="label">Synced Notes</div>
        </div>
        <div class="stat-box">
            <div class="num"><?php echo $jiraTickets; ?></div>
            <div class="label">Synced Tickets</div>
        </div>
    </div>

    <?php if (!$isConfigured): ?>
    <!-- Setup Instructions -->
    <div class="card">
        <h3>&#9881; Setup Required</h3>
        <div class="alert alert-warning">Atlassian credentials not configured. Add them to <code>config.php</code> on your server.</div>
        <div class="config-box">
            <p style="margin-bottom:12px;font-weight:600;">Add these to <code>public_html/admin/config.php</code> on Hostinger:</p>
            <pre style="background:rgba(0,0,0,0.04);padding:16px;border-radius:8px;font-size:0.85rem;overflow-x:auto;">
define('ATLASSIAN_EMAIL', 'your-email@company.com');
define('ATLASSIAN_API_TOKEN', 'your-api-token-here');
define('ATLASSIAN_DOMAIN', 'yourcompany.atlassian.net');</pre>
            <p style="margin-top:12px;font-size:0.85rem;color:var(--slate);">
                <strong>Get your API token:</strong> <a href="https://id.atlassian.com/manage-profile/security/api-tokens" target="_blank" style="color:var(--atlassian-blue);">id.atlassian.com/manage-profile/security/api-tokens</a>
            </p>
        </div>
    </div>

    <?php elseif (!$connectionOk): ?>
    <div class="card">
        <h3>&#9888; Connection Failed</h3>
        <div class="alert alert-error">Could not connect to Atlassian. Check that your email, API token, and domain are correct in <code>config.php</code>.</div>
    </div>

    <?php else: ?>
    <!-- Confluence Integration -->
    <div class="card">
        <div class="integration-header">
            <svg viewBox="0 0 32 32" width="40" height="40"><path d="M3.7 24.7c-.4.7-.9 1.5-1.3 2.2-.3.5-.1 1.2.4 1.5l5.7 3.5c.5.3 1.2.2 1.5-.4.3-.5.7-1.2 1.2-1.9 3.3-5.3 6.6-4.7 12.6-1.8l5.6 2.7c.6.3 1.2 0 1.5-.5l3.1-6.3c.2-.5 0-1.1-.5-1.4-1.7-.8-5.1-2.5-8.6-4.1-9.3-4.4-15.3-3.4-21.2 6.5z" fill="#1868DB"/><path d="M28.3 7.3c.4-.7.9-1.5 1.3-2.2.3-.5.1-1.2-.4-1.5L23.5.1c-.5-.3-1.2-.2-1.5.4-.3.5-.7 1.2-1.2 1.9-3.3 5.3-6.6 4.7-12.6 1.8L2.6 1.5c-.6-.3-1.2 0-1.5.5L-2 8.3c-.2.5 0 1.1.5 1.4 1.7.8 5.1 2.5 8.6 4.1 9.3 4.4 15.3 3.4 21.2-6.5z" fill="#1868DB"/></svg>
            <div>
                <div class="title">Confluence &#8594; Book of Truth</div>
                <div class="subtitle">Import client documentation pages as categorized notes</div>
            </div>
        </div>

        <?php if (!empty($confluenceSpaces)): ?>
        <p style="font-size:0.88rem;color:var(--slate);margin-bottom:12px;">Available spaces:</p>
        <div class="space-list">
            <?php foreach ($confluenceSpaces as $space): ?>
            <div class="space-item">
                <div>
                    <div class="item-name"><?php echo htmlspecialchars($space['name'] ?? ''); ?></div>
                    <div class="item-key"><?php echo htmlspecialchars($space['key'] ?? ''); ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <form method="POST" style="margin-top:20px;">
            <input type="hidden" name="confluence_import" value="1">
            <div class="form-grid">
                <div class="form-group">
                    <label>Confluence Space Key *</label>
                    <select name="confluence_space" required>
                        <option value="">Select a space...</option>
                        <?php foreach ($confluenceSpaces as $space): ?>
                        <option value="<?php echo htmlspecialchars($space['key'] ?? ''); ?>"><?php echo htmlspecialchars(($space['name'] ?? '') . ' (' . ($space['key'] ?? '') . ')'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Target Client *</label>
                    <select name="target_client_id" required>
                        <option value="">Select a client...</option>
                        <?php foreach ($clients as $c): ?>
                        <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['company_name'] . ($c['client_code'] ? ' (' . $c['client_code'] . ')' : '')); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Import Mode</label>
                    <select name="import_mode" id="import_mode" onchange="document.getElementById('search-field').style.display=this.value==='search'?'flex':'none'">
                        <option value="all">All pages in space</option>
                        <option value="search">Search by keyword</option>
                    </select>
                </div>
                <div class="form-group" id="search-field" style="display:none;">
                    <label>Search Keyword</label>
                    <input type="text" name="confluence_search" placeholder="e.g. network, infrastructure">
                </div>
            </div>
            <div style="margin-top:14px;">
                <button type="submit" class="btn btn-confluence">&#128218; Import from Confluence</button>
                <span style="font-size:0.78rem;color:var(--slate);margin-left:12px;">Pages are auto-categorized (infrastructure, ISP, credentials, etc.) and saved to the client's Book of Truth</span>
            </div>
        </form>
    </div>

    <!-- Jira Integration -->
    <div class="card">
        <div class="integration-header">
            <svg viewBox="0 0 32 32" width="40" height="40"><path d="M27.8 14.6L17.4 4.2 16 2.8 5.4 13.4l-3.2 3.2c-.5.5-.5 1.3 0 1.8l8.8 8.8L16 32l10.6-10.6.4-.4-3.2-3.2 3-3.2zM16 20.5l-4.5-4.5L16 11.5l4.5 4.5-4.5 4.5z" fill="#0052CC"/></svg>
            <div>
                <div class="title">Jira &#8594; Projects Tracker</div>
                <div class="subtitle">Import Jira tickets as projects linked to your clients</div>
            </div>
        </div>

        <?php if (!empty($jiraProjects)): ?>
        <p style="font-size:0.88rem;color:var(--slate);margin-bottom:12px;">Available projects:</p>
        <div class="project-list">
            <?php foreach ($jiraProjects as $proj): ?>
            <div class="project-item">
                <div>
                    <div class="item-name"><?php echo htmlspecialchars($proj['name'] ?? ''); ?></div>
                    <div class="item-key"><?php echo htmlspecialchars($proj['key'] ?? ''); ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <form method="POST" style="margin-top:20px;">
            <input type="hidden" name="jira_import" value="1">
            <div class="form-grid">
                <div class="form-group">
                    <label>Jira Project *</label>
                    <select name="jira_project" required>
                        <option value="">Select a project...</option>
                        <?php foreach ($jiraProjects as $proj): ?>
                        <option value="<?php echo htmlspecialchars($proj['key'] ?? ''); ?>"><?php echo htmlspecialchars(($proj['name'] ?? '') . ' (' . ($proj['key'] ?? '') . ')'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Map to Client *</label>
                    <select name="jira_client_id" required>
                        <option value="">Select a client...</option>
                        <?php foreach ($clients as $c): ?>
                        <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['company_name'] . ($c['client_code'] ? ' (' . $c['client_code'] . ')' : '')); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Ticket Filter</label>
                    <select name="jira_status_filter">
                        <option value="open">Open tickets only</option>
                        <option value="all">All tickets</option>
                        <option value="done">Completed only</option>
                    </select>
                </div>
            </div>
            <div style="margin-top:14px;">
                <button type="submit" class="btn btn-jira">&#127919; Import from Jira</button>
                <span style="font-size:0.78rem;color:var(--slate);margin-left:12px;">Tickets imported as projects with status, priority, and assignee info. Duplicates auto-skipped.</span>
            </div>
        </form>
    </div>

    <!-- Quick Actions -->
    <div class="card">
        <h3>&#9889; Quick Actions</h3>
        <div style="display:flex;gap:12px;flex-wrap:wrap;">
            <form method="POST" style="display:inline;">
                <input type="hidden" name="sync_jira_counts" value="1">
                <button type="submit" class="btn btn-save">&#128202; Refresh Jira Ticket Counts</button>
            </form>
            <a href="clients.php" class="btn btn-save" style="text-decoration:none;">&#128101; View Clients</a>
            <a href="projects.php" class="btn btn-save" style="text-decoration:none;">&#128203; View Projects</a>
        </div>
    </div>
    <?php endif; ?>

</div>
</body>
</html>
