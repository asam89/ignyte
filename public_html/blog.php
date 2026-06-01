<?php
require_once __DIR__ . '/admin/config.php';

$pdo = getDB();
$posts = $pdo->query("SELECT p.*, u.display_name as author FROM blog_posts p LEFT JOIN admin_users u ON p.author_id = u.id WHERE p.status = 'published' ORDER BY p.created_at DESC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Blog | IGNYTE Consulting</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Inter:wght@600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <style>
        .blog-hero {
            padding: 80px 0 60px;
            background: radial-gradient(ellipse at 50% -20%, #d6e8ff 0%, #ffffff 65%);
            text-align: center;
        }
        .blog-hero h1 { font-size: 2.8rem; margin-bottom: 10px; }
        .blog-hero p { font-size: 1.05rem; color: var(--slate); }

        .blog-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 28px;
            padding: 60px 0 80px;
        }

        .blog-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 2px 16px rgba(0,0,0,0.05);
            border: 1px solid rgba(0,0,0,0.04);
            padding: 36px 32px;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            display: flex;
            flex-direction: column;
        }
        .blog-card:hover { transform: translateY(-4px); box-shadow: 0 12px 40px rgba(0,0,0,0.1); }

        .blog-card-tag {
            display: inline-block;
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            padding: 4px 12px;
            border-radius: 50px;
            margin-bottom: 16px;
            width: fit-content;
            background: rgba(0,71,187,0.1);
            color: var(--brand-blue);
        }

        .blog-card h2 {
            font-size: 1.3rem;
            margin-bottom: 12px;
            line-height: 1.35;
        }
        .blog-card h2 a { color: var(--navy); }
        .blog-card h2 a:hover { color: var(--brand-blue); }

        .blog-card-excerpt {
            color: var(--slate);
            font-size: 0.92rem;
            line-height: 1.7;
            flex: 1;
            margin-bottom: 20px;
        }

        .blog-card-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.8rem;
            color: #999;
            padding-top: 16px;
            border-top: 1px solid rgba(0,0,0,0.06);
        }
        .blog-card-meta a {
            color: var(--brand-blue);
            font-weight: 700;
            font-size: 0.85rem;
        }
        .blog-card-meta a:hover { color: var(--flame-orange); }

        .empty-blog {
            grid-column: 1 / -1;
            text-align: center;
            padding: 80px 20px;
            color: var(--slate);
        }
        .empty-blog span { font-size: 3rem; display: block; margin-bottom: 16px; }

        @media (max-width: 768px) {
            .blog-grid { grid-template-columns: 1fr; }
            .blog-hero h1 { font-size: 2rem; }
            .blog-hero { padding: 60px 0 40px; }
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

<!-- Blog Hero -->
<section class="blog-hero">
    <div class="container">
        <div class="section-label">Insights & Updates</div>
        <h1>IGNYTE Blog</h1>
        <p>Perspectives on technology, strategy, and business transformation.</p>
    </div>
</section>

<!-- Blog Posts -->
<section style="background: var(--light-grey);">
    <div class="container">
        <div class="blog-grid">
            <?php if (empty($posts)): ?>
                <div class="empty-blog">
                    <span>&#128221;</span>
                    <p style="font-size:1.1rem; margin-bottom:8px;">No posts yet</p>
                    <p>Check back soon for insights and updates from our team.</p>
                </div>
            <?php else: ?>
                <?php foreach ($posts as $post): ?>
                <div class="blog-card">
                    <div class="blog-card-tag"><?php echo htmlspecialchars($post['category']); ?></div>
                    <h2><a href="blog-post.php?slug=<?php echo urlencode($post['slug']); ?>"><?php echo htmlspecialchars($post['title']); ?></a></h2>
                    <p class="blog-card-excerpt">
                        <?php
                        if ($post['excerpt']) {
                            echo htmlspecialchars($post['excerpt']);
                        } else {
                            echo htmlspecialchars(mb_substr(strip_tags($post['content']), 0, 160)) . '...';
                        }
                        ?>
                    </p>
                    <div class="blog-card-meta">
                        <span><?php echo htmlspecialchars($post['author']); ?> &middot; <?php echo date('M j, Y', strtotime($post['created_at'])); ?></span>
                        <a href="blog-post.php?slug=<?php echo urlencode($post['slug']); ?>">Read more &rarr;</a>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</section>

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
