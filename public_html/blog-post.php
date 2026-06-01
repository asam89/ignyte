<?php
require_once __DIR__ . '/admin/config.php';

$slug = $_GET['slug'] ?? '';
if (!$slug) {
    header('Location: blog.php');
    exit;
}

$pdo = getDB();
$stmt = $pdo->prepare("SELECT p.*, u.display_name as author FROM blog_posts p LEFT JOIN admin_users u ON p.author_id = u.id WHERE p.slug = ? AND p.status = 'published'");
$stmt->execute([$slug]);
$post = $stmt->fetch();

if (!$post) {
    header('HTTP/1.0 404 Not Found');
    echo '<!DOCTYPE html><html><head><title>Post Not Found</title>
    <style>body{font-family:"DM Sans",sans-serif;display:flex;justify-content:center;align-items:center;min-height:100vh;background:#f4f7fa;text-align:center;}
    .box{padding:40px;} h1{color:#002366;margin-bottom:12px;} a{color:#0047BB;font-weight:700;}</style></head>
    <body><div class="box"><h1>Post Not Found</h1><p>This post may have been removed or does not exist.</p><p><a href="blog.php">&larr; Back to Blog</a></p></div></body></html>';
    exit;
}

$pageTitle = htmlspecialchars($post['title']) . ' | IGNYTE Blog';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Syne:wght@700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <style>
        .post-hero {
            padding: 80px 0 40px;
            background: radial-gradient(ellipse at 50% -20%, #d6e8ff 0%, #ffffff 65%);
        }
        .post-hero .container { max-width: 800px; }
        .post-category {
            display: inline-block;
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            padding: 4px 12px;
            border-radius: 50px;
            margin-bottom: 20px;
            background: rgba(0,71,187,0.1);
            color: var(--brand-blue);
        }
        .post-hero h1 { font-size: 2.6rem; margin-bottom: 16px; line-height: 1.15; }
        .post-meta-bar {
            display: flex;
            gap: 20px;
            font-size: 0.9rem;
            color: var(--slate);
            margin-top: 8px;
        }

        .post-body {
            max-width: 800px;
            margin: 0 auto;
            padding: 48px 28px 80px;
        }
        .post-content {
            font-size: 1.05rem;
            line-height: 1.85;
            color: #1a202c;
        }
        .post-content p { margin-bottom: 20px; }
        .post-content h2 {
            font-size: 1.5rem;
            margin: 36px 0 16px;
            color: var(--navy);
        }
        .post-content h3 {
            font-size: 1.2rem;
            margin: 28px 0 12px;
            color: var(--navy);
        }
        .post-content ul, .post-content ol {
            margin: 16px 0;
            padding-left: 24px;
            list-style: disc;
        }
        .post-content li { margin-bottom: 8px; }
        .post-content a { color: var(--brand-blue); text-decoration: underline; }
        .post-content blockquote {
            border-left: 4px solid var(--flame-orange);
            padding: 16px 24px;
            margin: 24px 0;
            background: var(--light-grey);
            border-radius: 0 8px 8px 0;
            font-style: italic;
            color: var(--slate);
        }
        .post-content code {
            background: var(--light-grey);
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 0.9em;
        }
        .post-content pre {
            background: var(--navy);
            color: #e2e8f0;
            padding: 20px;
            border-radius: 10px;
            overflow-x: auto;
            margin: 20px 0;
        }
        .post-content pre code { background: none; padding: 0; color: inherit; }

        .post-nav {
            max-width: 800px;
            margin: 0 auto;
            padding: 0 28px 60px;
            text-align: center;
        }
        .post-nav a {
            display: inline-block;
            background: var(--light-grey);
            color: var(--brand-blue);
            padding: 12px 28px;
            border-radius: 8px;
            font-weight: 700;
            font-size: 0.92rem;
            transition: all 0.2s;
        }
        .post-nav a:hover { background: var(--brand-blue); color: white; }

        @media (max-width: 768px) {
            .post-hero h1 { font-size: 1.8rem; }
            .post-hero { padding: 60px 0 30px; }
        }
    </style>
</head>
<body>

<!-- Navigation -->
<nav id="navbar">
    <div class="container">
        <div class="nav-flex">
            <a href="index.html"><img src="logo.png" alt="IGNYTE Consulting" class="nav-logo"></a>
            <div class="nav-links" id="navLinks">
                <a href="index.html#services">Services</a>
                <a href="index.html#why">Why IGNYTE</a>
                <a href="blog.php" style="color: var(--brand-blue);">Blog</a>
                <a href="https://igy.atlassian.net/servicedesk/customer/portal/2" target="_blank" rel="noopener">Client Portal</a>
                <a href="index.html#contact" class="btn-nav">Contact Us</a>
            </div>
            <div class="mobile-menu-toggle" id="menuToggle" onclick="document.getElementById('navLinks').classList.toggle('open')">&#9776;</div>
        </div>
    </div>
</nav>

<!-- Post Header -->
<section class="post-hero">
    <div class="container">
        <div class="post-category"><?php echo htmlspecialchars($post['category']); ?></div>
        <h1><?php echo htmlspecialchars($post['title']); ?></h1>
        <div class="post-meta-bar">
            <span>By <?php echo htmlspecialchars($post['author']); ?></span>
            <span><?php echo date('F j, Y', strtotime($post['created_at'])); ?></span>
        </div>
    </div>
</section>

<!-- Post Content -->
<div class="post-body">
    <div class="post-content">
        <?php echo $post['content']; ?>
    </div>
</div>

<!-- Back to Blog -->
<div class="post-nav">
    <a href="blog.php">&larr; Back to All Posts</a>
</div>

<!-- Footer -->
<footer>
    <div class="container">
        <div class="footer-content">
            <div class="footer-brand">
                <img src="logo.png" alt="IGNYTE Consulting" class="footer-logo">
                <p>Transforming businesses through innovative strategies and cutting-edge solutions.</p>
            </div>
            <div class="footer-links">
                <h4>Quick Links</h4>
                <ul>
                    <li><a href="index.html#services">Services</a></li>
                    <li><a href="index.html#why">Why IGNYTE</a></li>
                    <li><a href="blog.php">Blog</a></li>
                    <li><a href="https://igy.atlassian.net/servicedesk/customer/portal/2" target="_blank" rel="noopener">Client Portal</a></li>
                </ul>
            </div>
            <div class="footer-contact">
                <h4>Contact</h4>
                <div class="contact-item">&#9993; <a href="mailto:contact@ignyteconsulting.com">contact@ignyteconsulting.com</a></div>
                <div class="contact-item">&#127760; <a href="https://www.ignyteconsulting.com">ignyteconsulting.com</a></div>
            </div>
        </div>
        <div class="copyright">&copy; 2026 IGNYTE Consulting. All rights reserved.</div>
    </div>
</footer>

<script>
    window.addEventListener('scroll', function() {
        document.getElementById('navbar').classList.toggle('scrolled', window.scrollY > 20);
    });
</script>

</body>
</html>
