<?php
session_start();
require_once __DIR__ . '/config.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$pdo = getDB();
$adminName = htmlspecialchars($_SESSION['admin_name']);

// Handle client status change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['client_action'])) {
    $clientId = (int)$_POST['client_id'];
    $action = $_POST['client_action'];
    if ($action === 'approve') {
        $pdo->prepare('UPDATE clients SET status = ? WHERE id = ?')->execute(['active', $clientId]);
    } elseif ($action === 'suspend') {
        $pdo->prepare('UPDATE clients SET status = ? WHERE id = ?')->execute(['suspended', $clientId]);
    } elseif ($action === 'delete') {
        $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$clientId]);
    }
    header('Location: dashboard.php?client_updated=1');
    exit;
}

// Handle post deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $stmt = $pdo->prepare('DELETE FROM blog_posts WHERE id = ?');
    $stmt->execute([$_POST['delete_id']]);
    header('Location: dashboard.php?deleted=1');
    exit;
}

// Handle new post / edit post
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $title = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $excerpt = trim($_POST['excerpt'] ?? '');
    $category = trim($_POST['category'] ?? 'General');
    $status = ($_POST['status'] ?? 'published') === 'draft' ? 'draft' : 'published';

    if ($title && $content) {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $title));
        $slug = trim($slug, '-');

        if ($_POST['action'] === 'create') {
            // Ensure unique slug
            $checkSlug = $pdo->prepare('SELECT COUNT(*) FROM blog_posts WHERE slug = ?');
            $checkSlug->execute([$slug]);
            if ($checkSlug->fetchColumn() > 0) {
                $slug .= '-' . time();
            }

            $stmt = $pdo->prepare('INSERT INTO blog_posts (title, slug, content, excerpt, category, status, author_id) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$title, $slug, $content, $excerpt, $category, $status, $_SESSION['admin_id']]);
            header('Location: dashboard.php?created=1');
            exit;
        } elseif ($_POST['action'] === 'update' && isset($_POST['post_id'])) {
            $stmt = $pdo->prepare('UPDATE blog_posts SET title = ?, content = ?, excerpt = ?, category = ?, status = ? WHERE id = ?');
            $stmt->execute([$title, $content, $excerpt, $category, $status, $_POST['post_id']]);
            header('Location: dashboard.php?updated=1');
            exit;
        }
    }
}

// Fetch all posts
$posts = $pdo->query('SELECT p.*, u.display_name as author FROM blog_posts p LEFT JOIN admin_users u ON p.author_id = u.id ORDER BY p.created_at DESC')->fetchAll();

// If editing a post
$editPost = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM blog_posts WHERE id = ?');
    $stmt->execute([$_GET['edit']]);
    $editPost = $stmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard | IGNYTE Consulting</title>
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

        /* Top Bar */
        .topbar {
            background: var(--navy);
            color: white;
            padding: 14px 28px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .topbar-left { display: flex; align-items: center; gap: 16px; }
        .topbar-left img { height: 36px; filter: brightness(0) invert(1); }
        .topbar-left h2 { font-family: 'Inter', sans-serif; font-size: 1.1rem; font-weight: 800; }
        .topbar-right { display: flex; align-items: center; gap: 16px; font-size: 0.9rem; }
        .topbar-right a {
            color: rgba(255,255,255,0.7);
            text-decoration: none;
            font-weight: 600;
            padding: 6px 14px;
            border-radius: 6px;
            transition: all 0.2s;
        }
        .topbar-right a:hover { background: rgba(255,255,255,0.1); color: white; }
        .topbar-right .logout-btn { background: rgba(238,90,36,0.2); color: #ff8c42; }
        .topbar-right .logout-btn:hover { background: var(--flame-orange); color: white; }

        /* Layout */
        .dashboard { max-width: 1100px; margin: 0 auto; padding: 32px 28px; }

        /* Stats */
        .stats-row { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 32px; }
        .stat-box {
            background: white;
            padding: 24px;
            border-radius: 14px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.04);
        }
        .stat-box .num {
            font-family: 'Inter', sans-serif;
            font-size: 2rem;
            font-weight: 800;
            color: var(--navy);
        }
        .stat-box .label { font-size: 0.85rem; color: var(--slate); margin-top: 4px; }

        /* Alert */
        .alert {
            padding: 12px 18px;
            border-radius: 10px;
            margin-bottom: 24px;
            font-size: 0.9rem;
            font-weight: 600;
        }
        .alert-success { background: rgba(34,197,94,0.1); color: #16a34a; border: 1px solid rgba(34,197,94,0.2); }

        /* Post Form */
        .card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 2px 16px rgba(0,0,0,0.05);
            padding: 32px;
            margin-bottom: 32px;
        }
        .card h3 {
            font-family: 'Inter', sans-serif;
            font-size: 1.3rem;
            margin-bottom: 24px;
            color: var(--navy);
        }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px; }
        .form-group { margin-bottom: 16px; }
        .form-group label {
            display: block;
            font-weight: 600;
            font-size: 0.85rem;
            color: var(--navy);
            margin-bottom: 6px;
        }
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px 14px;
            border: 1.5px solid rgba(0,0,0,0.1);
            border-radius: 8px;
            font-size: 0.92rem;
            font-family: 'DM Sans', sans-serif;
            outline: none;
            transition: border-color 0.2s;
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus { border-color: var(--brand-blue); }
        .form-group textarea { min-height: 200px; resize: vertical; line-height: 1.7; }
        .form-actions { display: flex; gap: 12px; }
        .btn {
            padding: 10px 24px;
            border: none;
            border-radius: 8px;
            font-size: 0.9rem;
            font-weight: 700;
            font-family: 'DM Sans', sans-serif;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-publish { background: var(--flame-orange); color: white; box-shadow: 0 2px 12px rgba(238,90,36,0.3); }
        .btn-publish:hover { background: var(--navy); }
        .btn-draft { background: var(--light-grey); color: var(--navy); }
        .btn-draft:hover { background: #e2e8f0; }
        .btn-cancel { background: transparent; color: var(--slate); }
        .btn-cancel:hover { color: var(--navy); }

        /* Posts Table */
        .posts-table { width: 100%; border-collapse: collapse; }
        .posts-table th {
            text-align: left;
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--slate);
            padding: 12px 16px;
            border-bottom: 2px solid var(--light-grey);
        }
        .posts-table td {
            padding: 16px;
            border-bottom: 1px solid rgba(0,0,0,0.04);
            font-size: 0.9rem;
            vertical-align: middle;
        }
        .posts-table tr:hover td { background: rgba(0,71,187,0.02); }
        .post-title-cell { font-weight: 600; color: var(--navy); }
        .badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 50px;
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
        }
        .badge-published, .badge-active { background: rgba(34,197,94,0.1); color: #16a34a; }
        .badge-draft { background: rgba(234,179,8,0.15); color: #ca8a04; }
        .badge-pending { background: rgba(238,90,36,0.1); color: var(--flame-orange); }
        .badge-suspended { background: rgba(220,38,38,0.08); color: #dc2626; }
        .action-btns { display: flex; gap: 8px; }
        .action-btns a, .action-btns button {
            padding: 5px 12px;
            border-radius: 6px;
            font-size: 0.8rem;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            border: none;
            font-family: 'DM Sans', sans-serif;
            transition: all 0.2s;
        }
        .btn-edit { background: rgba(0,71,187,0.1); color: var(--brand-blue); }
        .btn-edit:hover { background: var(--brand-blue); color: white; }
        .btn-view { background: rgba(0,35,102,0.06); color: var(--navy); }
        .btn-view:hover { background: var(--navy); color: white; }
        .btn-delete { background: rgba(220,38,38,0.08); color: #dc2626; }
        .btn-delete:hover { background: #dc2626; color: white; }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--slate);
        }
        .empty-state p { font-size: 1.1rem; margin-bottom: 8px; }
        .empty-state span { font-size: 3rem; display: block; margin-bottom: 16px; }

        /* Responsive */
        @media (max-width: 768px) {
            .stats-row { grid-template-columns: 1fr; }
            .form-row { grid-template-columns: 1fr; }
            .topbar { flex-direction: column; gap: 10px; text-align: center; }
            .posts-table { font-size: 0.82rem; }
            .posts-table th:nth-child(3),
            .posts-table td:nth-child(3),
            .posts-table th:nth-child(4),
            .posts-table td:nth-child(4) { display: none; }
        }
    </style>
</head>
<body>

<!-- Top Bar -->
<div class="topbar">
    <div class="topbar-left">
        <img src="../logo.png" alt="IGNYTE">
        <h2>Admin Dashboard</h2>
    </div>
    <div class="topbar-right">
        <span>Welcome, <?php echo $adminName; ?></span>
        <a href="dashboard.php" style="background:rgba(255,255,255,0.15);color:white;">Blog</a>
        <a href="clients.php">Clients</a>
        <a href="crm.php">Contacts</a>
        <a href="projects.php">Projects</a>
        <a href="quotes.php">Quotes</a>
        <a href="newsletters.php">Newsletters</a>
        <a href="tools.php">Tools</a>
        <a href="#clients-section">Portal Users</a>
        <a href="../index.html">View Site</a>
        <a href="change-password.php">Password</a>
        <a href="logout.php" class="logout-btn">Logout</a>
    </div>
</div>

<div class="dashboard">

    <!-- Alerts -->
    <?php if (isset($_GET['created'])): ?>
        <div class="alert alert-success">Blog post published successfully!</div>
    <?php elseif (isset($_GET['updated'])): ?>
        <div class="alert alert-success">Blog post updated successfully!</div>
    <?php elseif (isset($_GET['deleted'])): ?>
        <div class="alert alert-success">Blog post deleted.</div>
    <?php elseif (isset($_GET['client_updated'])): ?>
        <div class="alert alert-success">Client status updated.</div>
    <?php endif; ?>

    <!-- Stats -->
    <?php
    $totalPosts = count($posts);
    $publishedPosts = count(array_filter($posts, function($p) { return $p['status'] === 'published'; }));
    $draftPosts = $totalPosts - $publishedPosts;
    ?>
    <div class="stats-row">
        <div class="stat-box">
            <div class="num"><?php echo $totalPosts; ?></div>
            <div class="label">Total Posts</div>
        </div>
        <div class="stat-box">
            <div class="num"><?php echo $publishedPosts; ?></div>
            <div class="label">Published</div>
        </div>
        <div class="stat-box">
            <div class="num"><?php echo $draftPosts; ?></div>
            <div class="label">Drafts</div>
        </div>
    </div>

    <!-- Post Editor -->
    <div class="card">
        <h3><?php echo $editPost ? 'Edit Post' : 'Create New Post'; ?></h3>
        <form method="POST" action="">
            <input type="hidden" name="action" value="<?php echo $editPost ? 'update' : 'create'; ?>">
            <?php if ($editPost): ?>
                <input type="hidden" name="post_id" value="<?php echo $editPost['id']; ?>">
            <?php endif; ?>

            <div class="form-group">
                <label for="title">Post Title</label>
                <input type="text" id="title" name="title" placeholder="Enter post title..." required
                       value="<?php echo htmlspecialchars($editPost['title'] ?? ''); ?>">
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="category">Category</label>
                    <select id="category" name="category">
                        <?php
                        $categories = ['General', 'Technology', 'Strategy', 'Cybersecurity', 'Project Management', 'Digital Transformation', 'Industry News'];
                        foreach ($categories as $cat):
                            $selected = (($editPost['category'] ?? 'General') === $cat) ? 'selected' : '';
                        ?>
                            <option value="<?php echo $cat; ?>" <?php echo $selected; ?>><?php echo $cat; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="status">Status</label>
                    <select id="status" name="status">
                        <option value="published" <?php echo (($editPost['status'] ?? 'published') === 'published') ? 'selected' : ''; ?>>Published</option>
                        <option value="draft" <?php echo (($editPost['status'] ?? '') === 'draft') ? 'selected' : ''; ?>>Draft</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label for="excerpt">Excerpt (optional)</label>
                <input type="text" id="excerpt" name="excerpt" placeholder="Brief summary for the blog listing..."
                       value="<?php echo htmlspecialchars($editPost['excerpt'] ?? ''); ?>">
            </div>

            <div class="form-group">
                <label for="content">Content</label>
                <textarea id="content" name="content" placeholder="Write your blog post content here. HTML is supported for formatting." required><?php echo htmlspecialchars($editPost['content'] ?? ''); ?></textarea>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-publish"><?php echo $editPost ? 'Update Post' : 'Publish Post'; ?></button>
                <?php if ($editPost): ?>
                    <a href="dashboard.php" class="btn btn-cancel">Cancel Edit</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Posts List -->
    <div class="card">
        <h3>All Posts</h3>
        <?php if (empty($posts)): ?>
            <div class="empty-state">
                <span>&#9997;</span>
                <p>No blog posts yet</p>
                <p style="font-size:0.9rem;">Create your first post using the form above.</p>
            </div>
        <?php else: ?>
            <table class="posts-table">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Category</th>
                        <th>Status</th>
                        <th>Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($posts as $post): ?>
                    <tr>
                        <td class="post-title-cell"><?php echo htmlspecialchars($post['title']); ?></td>
                        <td><?php echo htmlspecialchars($post['category']); ?></td>
                        <td>
                            <span class="badge badge-<?php echo $post['status']; ?>">
                                <?php echo $post['status']; ?>
                            </span>
                        </td>
                        <td><?php echo date('M j, Y', strtotime($post['created_at'])); ?></td>
                        <td>
                            <div class="action-btns">
                                <a href="dashboard.php?edit=<?php echo $post['id']; ?>" class="btn-edit">Edit</a>
                                <a href="../blog-post.php?slug=<?php echo urlencode($post['slug']); ?>" target="_blank" class="btn-view">View</a>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this post?');">
                                    <input type="hidden" name="delete_id" value="<?php echo $post['id']; ?>">
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

    <!-- Client Management -->
    <?php
    try {
        $clientsResult = $pdo->query('SELECT * FROM clients ORDER BY created_at DESC');
        $allClients = $clientsResult->fetchAll();
        $pendingClients = array_filter($allClients, function($c) { return $c['status'] === 'pending'; });
    } catch (Exception $e) {
        $allClients = [];
        $pendingClients = [];
    }
    ?>
    <div class="card" id="clients-section">
        <h3>Client Management <?php if (count($pendingClients) > 0): ?><span style="background:rgba(238,90,36,0.15);color:var(--flame-orange);padding:2px 10px;border-radius:50px;font-size:0.75rem;margin-left:8px;"><?php echo count($pendingClients); ?> pending</span><?php endif; ?></h3>
        <?php if (empty($allClients)): ?>
            <div class="empty-state">
                <span>&#128100;</span>
                <p>No client accounts yet</p>
                <p style="font-size:0.9rem;">Clients can register at <code>/client/register.php</code></p>
            </div>
        <?php else: ?>
            <table class="posts-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Company</th>
                        <th>Status</th>
                        <th>Joined</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($allClients as $cl): ?>
                    <tr>
                        <td class="post-title-cell"><?php echo htmlspecialchars($cl['full_name']); ?></td>
                        <td><?php echo htmlspecialchars($cl['email']); ?></td>
                        <td><?php echo htmlspecialchars($cl['company_name'] ?? '-'); ?></td>
                        <td>
                            <span class="badge badge-<?php echo $cl['status']; ?>">
                                <?php echo ucfirst($cl['status']); ?>
                            </span>
                        </td>
                        <td><?php echo date('M j, Y', strtotime($cl['created_at'])); ?></td>
                        <td>
                            <div class="action-btns">
                                <?php if ($cl['status'] === 'pending'): ?>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="client_id" value="<?php echo $cl['id']; ?>">
                                        <input type="hidden" name="client_action" value="approve">
                                        <button type="submit" class="btn-edit">Approve</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($cl['status'] === 'active'): ?>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="client_id" value="<?php echo $cl['id']; ?>">
                                        <input type="hidden" name="client_action" value="suspend">
                                        <button type="submit" class="btn-view">Suspend</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($cl['status'] === 'suspended'): ?>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="client_id" value="<?php echo $cl['id']; ?>">
                                        <input type="hidden" name="client_action" value="approve">
                                        <button type="submit" class="btn-edit">Reactivate</button>
                                    </form>
                                <?php endif; ?>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this client?');">
                                    <input type="hidden" name="client_id" value="<?php echo $cl['id']; ?>">
                                    <input type="hidden" name="client_action" value="delete">
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
