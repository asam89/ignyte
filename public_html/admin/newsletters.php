<?php
/**
 * IGNYTE Consulting - Newsletter Campaign Manager
 * 
 * Create, preview, and send email newsletters directly from the admin panel.
 * Uses Resend API for delivery. Recipients pulled from CRM contacts.
 */
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/resend.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$pdo = getDB();
$adminName = htmlspecialchars($_SESSION['admin_name']);
$resend = new ResendAPI();
$configured = $resend->isConfigured();

$message = '';
$error = '';
$activeTab = $_GET['tab'] ?? 'compose';

// Get recipient counts from CRM
$recipientCounts = [];
try {
    $recipientCounts['all'] = (int)$pdo->query("SELECT COUNT(*) FROM crm_clients WHERE email IS NOT NULL AND email != ''")->fetchColumn();
    $recipientCounts['active'] = (int)$pdo->query("SELECT COUNT(*) FROM crm_clients WHERE email IS NOT NULL AND email != '' AND status = 'active'")->fetchColumn();
    $recipientCounts['prospect'] = (int)$pdo->query("SELECT COUNT(*) FROM crm_clients WHERE email IS NOT NULL AND email != '' AND status = 'prospect'")->fetchColumn();
} catch (Exception $e) {
    $recipientCounts = ['all' => 0, 'active' => 0, 'prospect' => 0];
}

// Get campaign history from local DB
$campaigns = [];
try {
    $campaigns = $pdo->query("SELECT * FROM newsletter_campaigns ORDER BY created_at DESC LIMIT 20")->fetchAll();
} catch (Exception $e) {
    // Table might not exist yet
}

// Handle campaign creation and sending
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $configured) {
    $action = $_POST['campaign_action'] ?? '';

    if ($action === 'create_and_send' || $action === 'create_draft' || $action === 'send_test') {
        $subject = trim($_POST['subject'] ?? '');
        $previewText = trim($_POST['preview_text'] ?? '');
        $fromName = trim($_POST['from_name'] ?? 'IGNYTE Consulting');
        $replyTo = trim($_POST['reply_to'] ?? 'info@ignyteconsulting.com');
        $template = $_POST['template'] ?? 'modern';
        $headline = trim($_POST['headline'] ?? '');
        $bodyContent = trim($_POST['body_content'] ?? '');
        $ctaText = trim($_POST['cta_text'] ?? '');
        $ctaUrl = trim($_POST['cta_url'] ?? '');
        $footerText = trim($_POST['footer_text'] ?? '');
        $recipients = $_POST['recipients'] ?? 'active';

        if (!$subject || !$bodyContent) {
            $error = 'Subject line and body content are required.';
        } else {
            // Build the HTML email from template
            $html = buildEmailHtml($template, [
                'headline' => $headline,
                'body' => $bodyContent,
                'cta_text' => $ctaText,
                'cta_url' => $ctaUrl,
                'footer' => $footerText,
                'subject' => $subject,
                'preview_text' => $previewText,
            ]);

            if ($action === 'send_test') {
                $testEmail = trim($_POST['test_email'] ?? '');
                if (!$testEmail) $testEmail = $replyTo;
                $sendResult = $resend->sendTestEmail($testEmail, $subject, $html, $fromName, $replyTo);
                if ($sendResult['success']) {
                    $message = "Test email sent to {$testEmail}! Check your inbox.";
                } else {
                    $error = 'Failed to send test: ' . ($sendResult['message'] ?? $sendResult['error'] ?? 'Unknown error');
                }
                $activeTab = 'compose';
            } elseif ($action === 'create_and_send') {
                // Get recipients from CRM
                $recipientList = getRecipients($pdo, $recipients);

                if (empty($recipientList)) {
                    $error = 'No recipients found for the selected group.';
                } else {
                    $sendResult = $resend->sendNewsletter($recipientList, $subject, $html, $fromName, $replyTo);

                    // Save campaign to local DB
                    saveCampaign($pdo, [
                        'subject' => $subject,
                        'preview_text' => $previewText,
                        'from_name' => $fromName,
                        'reply_to' => $replyTo,
                        'template' => $template,
                        'headline' => $headline,
                        'body_content' => $bodyContent,
                        'cta_text' => $ctaText,
                        'cta_url' => $ctaUrl,
                        'footer_text' => $footerText,
                        'recipients_group' => $recipients,
                        'recipients_count' => count($recipientList),
                        'sent_count' => $sendResult['sent'] ?? 0,
                        'failed_count' => $sendResult['failed'] ?? 0,
                        'status' => $sendResult['success'] ? 'sent' : 'partial',
                    ]);

                    if ($sendResult['success']) {
                        $message = "Newsletter \"{$subject}\" sent to {$sendResult['sent']} recipients!";
                    } else {
                        $error = "Sent to {$sendResult['sent']} of " . count($recipientList) . " recipients. Errors: " . implode(', ', $sendResult['errors']);
                    }
                    $activeTab = 'history';
                }
            } else {
                // Save as draft
                saveCampaign($pdo, [
                    'subject' => $subject,
                    'preview_text' => $previewText,
                    'from_name' => $fromName,
                    'reply_to' => $replyTo,
                    'template' => $template,
                    'headline' => $headline,
                    'body_content' => $bodyContent,
                    'cta_text' => $ctaText,
                    'cta_url' => $ctaUrl,
                    'footer_text' => $footerText,
                    'recipients_group' => $recipients,
                    'recipients_count' => 0,
                    'sent_count' => 0,
                    'failed_count' => 0,
                    'status' => 'draft',
                ]);
                $message = "Campaign \"{$subject}\" saved as draft.";
                $activeTab = 'history';
            }
        }
    }

    if ($action === 'send_draft') {
        $campaignId = (int)($_POST['campaign_id'] ?? 0);
        if ($campaignId) {
            $stmt = $pdo->prepare("SELECT * FROM newsletter_campaigns WHERE id = ?");
            $stmt->execute([$campaignId]);
            $campaign = $stmt->fetch();

            if ($campaign && $campaign['status'] === 'draft') {
                $html = buildEmailHtml($campaign['template'], [
                    'headline' => $campaign['headline'],
                    'body' => $campaign['body_content'],
                    'cta_text' => $campaign['cta_text'],
                    'cta_url' => $campaign['cta_url'],
                    'footer' => $campaign['footer_text'],
                    'subject' => $campaign['subject'],
                    'preview_text' => $campaign['preview_text'],
                ]);

                $recipientList = getRecipients($pdo, $campaign['recipients_group']);
                $sendResult = $resend->sendNewsletter($recipientList, $campaign['subject'], $html, $campaign['from_name'], $campaign['reply_to']);

                $stmt = $pdo->prepare("UPDATE newsletter_campaigns SET status = ?, sent_count = ?, failed_count = ?, recipients_count = ?, sent_at = NOW() WHERE id = ?");
                $stmt->execute([
                    $sendResult['success'] ? 'sent' : 'partial',
                    $sendResult['sent'],
                    $sendResult['failed'],
                    count($recipientList),
                    $campaignId
                ]);

                if ($sendResult['success']) {
                    $message = "Newsletter sent to {$sendResult['sent']} recipients!";
                } else {
                    $error = "Partially sent: {$sendResult['sent']} of " . count($recipientList);
                }
            }
        }
        $activeTab = 'history';
    }

    if ($action === 'delete_campaign') {
        $campaignId = (int)($_POST['campaign_id'] ?? 0);
        if ($campaignId) {
            $stmt = $pdo->prepare("DELETE FROM newsletter_campaigns WHERE id = ? AND status = 'draft'");
            $stmt->execute([$campaignId]);
            $message = 'Draft deleted.';
        }
        $activeTab = 'history';
    }

    // Refresh campaigns after action
    try {
        $campaigns = $pdo->query("SELECT * FROM newsletter_campaigns ORDER BY created_at DESC LIMIT 20")->fetchAll();
    } catch (Exception $e) {}
}

function getRecipients(PDO $pdo, string $group): array {
    $sql = "SELECT email, full_name FROM crm_clients WHERE email IS NOT NULL AND email != ''";
    if ($group === 'active') {
        $sql .= " AND status = 'active'";
    } elseif ($group === 'prospect') {
        $sql .= " AND status = 'prospect'";
    }
    return $pdo->query($sql)->fetchAll();
}

function saveCampaign(PDO $pdo, array $data): void {
    try {
        $stmt = $pdo->prepare("INSERT INTO newsletter_campaigns (subject, preview_text, from_name, reply_to, template, headline, body_content, cta_text, cta_url, footer_text, recipients_group, recipients_count, sent_count, failed_count, status, sent_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        $stmt->execute([
            $data['subject'],
            $data['preview_text'],
            $data['from_name'],
            $data['reply_to'],
            $data['template'],
            $data['headline'],
            $data['body_content'],
            $data['cta_text'],
            $data['cta_url'],
            $data['footer_text'],
            $data['recipients_group'],
            $data['recipients_count'],
            $data['sent_count'],
            $data['failed_count'],
            $data['status'],
            $data['status'] === 'sent' || $data['status'] === 'partial' ? date('Y-m-d H:i:s') : null,
        ]);
    } catch (Exception $e) {
        // Table might not exist — silently fail for history tracking
    }
}

/**
 * Build responsive HTML email from template and content.
 */
function buildEmailHtml(string $template, array $data): string {
    $headline = htmlspecialchars($data['headline']);
    $body = nl2br(htmlspecialchars($data['body']));
    $ctaText = htmlspecialchars($data['cta_text']);
    $ctaUrl = htmlspecialchars($data['cta_url']);
    $footer = htmlspecialchars($data['footer'] ?: 'IGNYTE Consulting | Managed IT Services');
    $previewText = htmlspecialchars($data['preview_text'] ?? '');

    $ctaBlock = '';
    if ($ctaText && $ctaUrl) {
        $ctaBlock = "
        <table role=\"presentation\" cellspacing=\"0\" cellpadding=\"0\" border=\"0\" style=\"margin: 30px auto;\">
            <tr>
                <td style=\"border-radius: 6px; background: #EE5A24;\">
                    <a href=\"{$ctaUrl}\" target=\"_blank\" style=\"background: #EE5A24; border: 15px solid #EE5A24; border-left: 25px solid #EE5A24; border-right: 25px solid #EE5A24; font-family: 'Helvetica Neue', Arial, sans-serif; font-size: 16px; line-height: 1.1; text-align: center; text-decoration: none; display: block; border-radius: 6px; font-weight: bold; color: #ffffff;\">{$ctaText}</a>
                </td>
            </tr>
        </table>";
    }

    $previewBlock = $previewText ? "<span style=\"display:none;max-height:0;overflow:hidden;\">{$previewText}</span>" : '';

    if ($template === 'minimal') {
        return buildMinimalTemplate($headline, $body, $ctaBlock, $footer, $previewBlock);
    }

    return buildModernTemplate($headline, $body, $ctaBlock, $footer, $previewBlock);
}

function buildModernTemplate(string $headline, string $body, string $ctaBlock, string $footer, string $previewBlock): string {
    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Newsletter</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f4f7fa; font-family: 'Helvetica Neue', Arial, sans-serif;">
    {$previewBlock}
    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #f4f7fa;">
        <tr>
            <td style="padding: 40px 20px;">
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="600" style="margin: 0 auto; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 12px rgba(0,0,0,0.06);">
                    <!-- Header -->
                    <tr>
                        <td style="background: #002366; padding: 30px 40px; text-align: center;">
                            <img src="https://www.ignyteconsulting.com/logo.png" alt="IGNYTE Consulting" style="height: 40px; filter: brightness(0) invert(1);">
                        </td>
                    </tr>
                    <!-- Body -->
                    <tr>
                        <td style="padding: 40px;">
                            <h1 style="font-family: 'Helvetica Neue', Arial, sans-serif; color: #002366; font-size: 24px; margin: 0 0 20px 0; line-height: 1.3;">{$headline}</h1>
                            <div style="font-size: 16px; line-height: 1.7; color: #4A5568;">
                                {$body}
                            </div>
                            {$ctaBlock}
                        </td>
                    </tr>
                    <!-- Footer -->
                    <tr>
                        <td style="background: #f8fafc; padding: 24px 40px; border-top: 1px solid #e2e8f0;">
                            <p style="font-size: 13px; color: #718096; margin: 0; text-align: center; line-height: 1.6;">
                                {$footer}<br>
                                <a href="https://www.ignyteconsulting.com" style="color: #0047BB; text-decoration: none;">www.ignyteconsulting.com</a>
                            </p>
                            <p style="font-size: 11px; color: #a0aec0; margin: 12px 0 0 0; text-align: center;">
                                If you no longer wish to receive these emails, please reply with "unsubscribe".
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;
}

function buildMinimalTemplate(string $headline, string $body, string $ctaBlock, string $footer, string $previewBlock): string {
    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Newsletter</title>
</head>
<body style="margin: 0; padding: 0; background-color: #ffffff; font-family: 'Helvetica Neue', Arial, sans-serif;">
    {$previewBlock}
    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #ffffff;">
        <tr>
            <td style="padding: 40px 20px;">
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="560" style="margin: 0 auto;">
                    <!-- Logo -->
                    <tr>
                        <td style="padding-bottom: 30px; border-bottom: 2px solid #002366;">
                            <img src="https://www.ignyteconsulting.com/logo.png" alt="IGNYTE Consulting" style="height: 36px;">
                        </td>
                    </tr>
                    <!-- Body -->
                    <tr>
                        <td style="padding: 30px 0;">
                            <h1 style="font-family: 'Helvetica Neue', Arial, sans-serif; color: #002366; font-size: 22px; margin: 0 0 20px 0; line-height: 1.3;">{$headline}</h1>
                            <div style="font-size: 15px; line-height: 1.8; color: #4A5568;">
                                {$body}
                            </div>
                            {$ctaBlock}
                        </td>
                    </tr>
                    <!-- Footer -->
                    <tr>
                        <td style="padding-top: 24px; border-top: 1px solid #e2e8f0;">
                            <p style="font-size: 12px; color: #718096; margin: 0; line-height: 1.6;">
                                {$footer}<br>
                                If you no longer wish to receive these emails, please reply with "unsubscribe".
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Newsletters | IGNYTE Consulting</title>
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
        .alert-error { background: rgba(220,38,38,0.08); color: #dc2626; border: 1px solid rgba(220,38,38,0.2); }

        .tabs { display: flex; gap: 4px; margin-bottom: 32px; background: white; border-radius: 12px; padding: 6px; box-shadow: 0 2px 12px rgba(0,0,0,0.04); }
        .tab-btn {
            padding: 10px 24px; border-radius: 8px; font-size: 0.9rem;
            font-weight: 600; border: none; cursor: pointer;
            background: transparent; color: var(--slate); transition: all 0.2s;
            font-family: 'DM Sans', sans-serif;
        }
        .tab-btn:hover { color: var(--navy); background: var(--light-grey); }
        .tab-btn.active { background: var(--navy); color: white; }

        .tab-content { display: none; }
        .tab-content.active { display: block; }

        .card {
            background: white; border-radius: 16px;
            box-shadow: 0 2px 16px rgba(0,0,0,0.05);
            padding: 32px; margin-bottom: 32px;
        }
        .card h3 { font-family: 'Inter', sans-serif; font-size: 1.3rem; margin-bottom: 24px; color: var(--navy); }
        .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 12px; }
        .card-header h3 { margin-bottom: 0; }

        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .form-group { margin-bottom: 16px; }
        .form-group.full { grid-column: 1 / -1; }
        .form-group label {
            display: block; font-weight: 600; font-size: 0.85rem;
            color: var(--navy); margin-bottom: 6px;
        }
        .form-group label .hint {
            font-weight: 400; color: var(--slate); font-size: 0.8rem;
        }
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%; padding: 10px 14px;
            border: 1.5px solid rgba(0,0,0,0.1); border-radius: 8px;
            font-size: 0.92rem; font-family: 'DM Sans', sans-serif;
            outline: none; transition: border-color 0.2s;
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus { border-color: var(--brand-blue); }
        .form-group textarea { min-height: 200px; resize: vertical; line-height: 1.7; }

        .form-actions { display: flex; gap: 12px; margin-top: 24px; flex-wrap: wrap; }
        .btn {
            padding: 10px 24px; border: none; border-radius: 8px;
            font-size: 0.9rem; font-weight: 700; font-family: 'DM Sans', sans-serif;
            cursor: pointer; transition: all 0.2s; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;
        }
        .btn-send { background: var(--flame-orange); color: white; box-shadow: 0 2px 12px rgba(238,90,36,0.3); }
        .btn-send:hover { background: #d14b18; }
        .btn-draft { background: var(--brand-blue); color: white; }
        .btn-draft:hover { background: var(--navy); }
        .btn-test { background: rgba(0,71,187,0.1); color: var(--brand-blue); }
        .btn-test:hover { background: rgba(0,71,187,0.2); }
        .btn-delete { background: rgba(220,38,38,0.08); color: #dc2626; border: none; cursor: pointer; padding: 6px 14px; border-radius: 6px; font-size: 0.82rem; font-weight: 600; font-family: 'DM Sans', sans-serif; }
        .btn-delete:hover { background: #dc2626; color: white; }
        .btn-small { padding: 6px 14px; font-size: 0.82rem; }

        .template-selector { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 24px; }
        .template-option {
            border: 2px solid rgba(0,0,0,0.1); border-radius: 12px; padding: 20px;
            cursor: pointer; transition: all 0.2s; text-align: center;
        }
        .template-option:hover { border-color: var(--brand-blue); }
        .template-option.selected { border-color: var(--flame-orange); background: rgba(238,90,36,0.04); }
        .template-option h4 { font-size: 0.95rem; margin-bottom: 4px; }
        .template-option p { font-size: 0.8rem; color: var(--slate); }

        .campaigns-table { width: 100%; border-collapse: collapse; }
        .campaigns-table th {
            text-align: left; font-size: 0.78rem; text-transform: uppercase;
            letter-spacing: 0.06em; color: var(--slate); padding: 12px 14px;
            border-bottom: 2px solid var(--light-grey);
        }
        .campaigns-table td {
            padding: 14px; border-bottom: 1px solid rgba(0,0,0,0.04);
            font-size: 0.88rem; vertical-align: middle;
        }
        .campaigns-table tr:hover td { background: rgba(0,71,187,0.02); }

        .badge {
            display: inline-block; padding: 3px 10px; border-radius: 50px;
            font-size: 0.72rem; font-weight: 700; text-transform: uppercase;
        }
        .badge-sent { background: rgba(34,197,94,0.1); color: #16a34a; }
        .badge-draft { background: rgba(245,158,11,0.1); color: #f59e0b; }
        .badge-partial { background: rgba(220,38,38,0.08); color: #dc2626; }

        .empty-state { text-align: center; padding: 60px 20px; color: var(--slate); }
        .empty-state p { font-size: 1.1rem; margin-bottom: 8px; }

        .not-configured {
            text-align: center; padding: 60px 40px; background: white;
            border-radius: 16px; box-shadow: 0 2px 16px rgba(0,0,0,0.05);
        }
        .not-configured h3 { color: var(--navy); margin-bottom: 16px; }
        .not-configured p { color: var(--slate); max-width: 500px; margin: 0 auto 20px; }

        @media (max-width: 768px) {
            .stats-row { grid-template-columns: 1fr 1fr; }
            .form-grid { grid-template-columns: 1fr; }
            .template-selector { grid-template-columns: 1fr; }
            .topbar { flex-direction: column; gap: 10px; text-align: center; }
            .form-actions { flex-direction: column; }
        }
    </style>
</head>
<body>

<div class="topbar">
    <div class="topbar-left">
        <img src="../logo.png" alt="IGNYTE">
        <h2>Newsletters</h2>
    </div>
    <div class="topbar-right">
        <span>Welcome, <?php echo $adminName; ?></span>
        <a href="dashboard.php">Blog</a>
        <a href="clients.php">Clients</a>
        <a href="crm.php">Contacts</a>
        <a href="projects.php">Projects</a>
        <a href="quotes.php">Quotes</a>
        <a href="newsletters.php" class="active-nav">Newsletters</a>
        <a href="tools.php">Tools/Licenses</a>
        <a href="../index.html">View Site</a>
        <a href="logout.php" class="logout-btn">Logout</a>
    </div>
</div>

<div class="dashboard">

    <?php if ($message): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <?php if (!$configured): ?>
        <div class="not-configured">
            <h3>Resend Not Connected</h3>
            <p>To send newsletters, add your Resend API Key to <code>config.php</code> on Hostinger.</p>
            <pre style="background:var(--light-grey);padding:14px;border-radius:8px;font-size:0.82rem;text-align:left;max-width:500px;margin:0 auto;overflow-x:auto;">define('RESEND_API_KEY', 're_xxxxxxxxxxxx');
define('RESEND_FROM_EMAIL', 'newsletter@faezsports.com');
define('RESEND_FROM_NAME', 'IGNYTE Consulting');</pre>
            <p style="margin-top:16px;font-size:0.85rem;">
                Get your API key at <a href="https://resend.com/api-keys" target="_blank">resend.com/api-keys</a><br>
                Sends from your verified Resend domain (faezsports.com).
            </p>
        </div>
    <?php else: ?>

    <!-- Stats -->
    <div class="stats-row">
        <div class="stat-box">
            <div class="num"><?php echo $recipientCounts['active']; ?></div>
            <div class="label">Active Clients</div>
        </div>
        <div class="stat-box">
            <div class="num"><?php echo $recipientCounts['prospect']; ?></div>
            <div class="label">Prospects</div>
        </div>
        <div class="stat-box">
            <div class="num"><?php echo $recipientCounts['all']; ?></div>
            <div class="label">Total Recipients</div>
        </div>
        <div class="stat-box">
            <div class="num"><?php echo count($campaigns); ?></div>
            <div class="label">Campaigns Sent</div>
        </div>
    </div>

    <!-- Tabs -->
    <div class="tabs">
        <button class="tab-btn <?php echo $activeTab === 'compose' ? 'active' : ''; ?>" onclick="switchTab('compose')">Compose</button>
        <button class="tab-btn <?php echo $activeTab === 'templates' ? 'active' : ''; ?>" onclick="switchTab('templates')">Templates</button>
        <button class="tab-btn <?php echo $activeTab === 'history' ? 'active' : ''; ?>" onclick="switchTab('history')">History</button>
    </div>

    <!-- Compose Tab -->
    <div class="tab-content <?php echo $activeTab === 'compose' ? 'active' : ''; ?>" id="tab-compose">
        <div class="card">
            <h3>Compose Newsletter</h3>
            <form method="POST" id="compose-form">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Subject Line *</label>
                        <input type="text" name="subject" required placeholder="e.g. IGNYTE Monthly: Cybersecurity Tips for SMBs" value="<?php echo htmlspecialchars($_POST['subject'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>Preview Text <span class="hint">(shows in inbox preview)</span></label>
                        <input type="text" name="preview_text" placeholder="Brief preview shown before opening..." value="<?php echo htmlspecialchars($_POST['preview_text'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>From Name</label>
                        <input type="text" name="from_name" value="<?php echo htmlspecialchars($_POST['from_name'] ?? 'IGNYTE Consulting'); ?>">
                    </div>
                    <div class="form-group">
                        <label>Reply-To Email</label>
                        <input type="email" name="reply_to" value="<?php echo htmlspecialchars($_POST['reply_to'] ?? 'info@ignyteconsulting.com'); ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label>Send To</label>
                    <select name="recipients">
                        <option value="active">Active Clients (<?php echo $recipientCounts['active']; ?>)</option>
                        <option value="prospect">Prospects Only (<?php echo $recipientCounts['prospect']; ?>)</option>
                        <option value="all">All Contacts (<?php echo $recipientCounts['all']; ?>)</option>
                    </select>
                </div>

                <div class="form-group" style="margin-top: 8px;">
                    <label>Email Template</label>
                    <div class="template-selector">
                        <div class="template-option selected" onclick="selectTemplate('modern')">
                            <h4>Modern</h4>
                            <p>Navy header with logo, white body, branded footer</p>
                            <input type="radio" name="template" value="modern" checked style="display:none;">
                        </div>
                        <div class="template-option" onclick="selectTemplate('minimal')">
                            <h4>Minimal</h4>
                            <p>Clean white design, simple text-focused layout</p>
                            <input type="radio" name="template" value="minimal" style="display:none;">
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label>Headline</label>
                    <input type="text" name="headline" placeholder="e.g. Your Monthly IT Security Update" value="<?php echo htmlspecialchars($_POST['headline'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label>Body Content * <span class="hint">(plain text — line breaks preserved)</span></label>
                    <textarea name="body_content" required placeholder="Write your newsletter content here...

Tips:
- Start with a greeting
- Share valuable IT insights, news, or tips
- Keep paragraphs short
- End with a clear next step"><?php echo htmlspecialchars($_POST['body_content'] ?? ''); ?></textarea>
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label>CTA Button Text <span class="hint">(optional)</span></label>
                        <input type="text" name="cta_text" placeholder="e.g. Book a Free Consultation" value="<?php echo htmlspecialchars($_POST['cta_text'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>CTA Button URL</label>
                        <input type="url" name="cta_url" placeholder="https://www.ignyteconsulting.com/quote.php" value="<?php echo htmlspecialchars($_POST['cta_url'] ?? ''); ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label>Footer Text <span class="hint">(optional — defaults to company name)</span></label>
                    <input type="text" name="footer_text" placeholder="IGNYTE Consulting | Managed IT Services" value="<?php echo htmlspecialchars($_POST['footer_text'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label>Test Email <span class="hint">(send a preview to yourself first)</span></label>
                    <input type="email" name="test_email" placeholder="asam@ignyteconsulting.com" value="<?php echo htmlspecialchars($_POST['test_email'] ?? ''); ?>">
                </div>

                <div class="form-actions">
                    <button type="submit" name="campaign_action" value="create_and_send" class="btn btn-send" onclick="return confirm('Send this newsletter to the selected recipients? This cannot be undone.');">Send Newsletter</button>
                    <button type="submit" name="campaign_action" value="send_test" class="btn btn-test">Send Test Email</button>
                    <button type="submit" name="campaign_action" value="create_draft" class="btn btn-draft">Save as Draft</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Templates Tab -->
    <div class="tab-content <?php echo $activeTab === 'templates' ? 'active' : ''; ?>" id="tab-templates">
        <div class="card">
            <h3>Newsletter Templates</h3>
            <p style="color:var(--slate);margin-bottom:24px;font-size:0.9rem;">Click a template to start composing with it pre-filled.</p>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
                <div class="card" style="margin-bottom:0;border-left:4px solid var(--flame-orange);cursor:pointer;" onclick="loadTemplate('monthly')">
                    <h4 style="font-size:1rem;margin-bottom:8px;">Monthly IT Newsletter</h4>
                    <p style="font-size:0.85rem;color:var(--slate);">Monthly roundup of IT tips, security news, and company updates for your clients.</p>
                </div>
                <div class="card" style="margin-bottom:0;border-left:4px solid var(--brand-blue);cursor:pointer;" onclick="loadTemplate('security')">
                    <h4 style="font-size:1rem;margin-bottom:8px;">Security Alert</h4>
                    <p style="font-size:0.85rem;color:var(--slate);">Urgent security bulletin — notify clients about threats, patches, or recommended actions.</p>
                </div>
                <div class="card" style="margin-bottom:0;border-left:4px solid #16a34a;cursor:pointer;" onclick="loadTemplate('promo')">
                    <h4 style="font-size:1rem;margin-bottom:8px;">Service Promotion</h4>
                    <p style="font-size:0.85rem;color:var(--slate);">Promote a new service, seasonal offer, or partnership announcement.</p>
                </div>
                <div class="card" style="margin-bottom:0;border-left:4px solid #8b5cf6;cursor:pointer;" onclick="loadTemplate('welcome')">
                    <h4 style="font-size:1rem;margin-bottom:8px;">Welcome / Onboarding</h4>
                    <p style="font-size:0.85rem;color:var(--slate);">Welcome new clients with next steps, key contacts, and what to expect.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- History Tab -->
    <div class="tab-content <?php echo $activeTab === 'history' ? 'active' : ''; ?>" id="tab-history">
        <div class="card">
            <div class="card-header">
                <h3>Campaign History</h3>
            </div>

            <?php if (empty($campaigns)): ?>
                <div class="empty-state">
                    <p>No campaigns yet</p>
                    <span style="font-size:0.9rem;">Create your first newsletter in the Compose tab.</span>
                </div>
            <?php else: ?>
                <table class="campaigns-table">
                    <thead>
                        <tr>
                            <th>Subject</th>
                            <th>Status</th>
                            <th>Recipients</th>
                            <th>Sent</th>
                            <th>Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($campaigns as $camp): ?>
                            <?php
                            $status = $camp['status'] ?? 'draft';
                            $badgeClass = 'badge-draft';
                            if ($status === 'sent') $badgeClass = 'badge-sent';
                            elseif ($status === 'partial') $badgeClass = 'badge-partial';
                            $sentTime = !empty($camp['sent_at']) ? date('M j, Y g:ia', strtotime($camp['sent_at'])) : '-';
                            ?>
                            <tr>
                                <td style="font-weight:600;"><?php echo htmlspecialchars($camp['subject']); ?></td>
                                <td><span class="badge <?php echo $badgeClass; ?>"><?php echo ucfirst($status); ?></span></td>
                                <td><?php echo $camp['recipients_count'] ?: '-'; ?></td>
                                <td><?php echo $camp['sent_count'] ?: '-'; ?></td>
                                <td><?php echo $sentTime; ?></td>
                                <td>
                                    <?php if ($status === 'draft'): ?>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="campaign_action" value="send_draft">
                                            <input type="hidden" name="campaign_id" value="<?php echo $camp['id']; ?>">
                                            <button type="submit" class="btn btn-send btn-small" onclick="return confirm('Send this draft now?');">Send</button>
                                        </form>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="campaign_action" value="delete_campaign">
                                            <input type="hidden" name="campaign_id" value="<?php echo $camp['id']; ?>">
                                            <button type="submit" class="btn-delete" onclick="return confirm('Delete this draft?');">Delete</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <?php endif; ?>
</div>

<script>
function switchTab(tab) {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
    document.getElementById('tab-' + tab).classList.add('active');
    event.target.classList.add('active');
}

function selectTemplate(type) {
    document.querySelectorAll('.template-option').forEach(o => o.classList.remove('selected'));
    event.currentTarget.classList.add('selected');
    document.querySelector('input[name="template"][value="' + type + '"]').checked = true;
}

const templates = {
    monthly: {
        subject: 'IGNYTE Monthly: Your IT Update for ' + new Date().toLocaleString('default', { month: 'long', year: 'numeric' }),
        preview_text: 'This month\'s IT tips, security updates, and more',
        headline: 'Your Monthly IT Update',
        body_content: `Hi there,

Here's your monthly roundup from the IGNYTE team. We've put together the latest IT insights to keep your business secure and running smoothly.

WHAT'S NEW THIS MONTH:

1. [Topic 1 - e.g., New security patch released]
Brief description of why this matters for your business.

2. [Topic 2 - e.g., Cloud backup tip]
Brief description and actionable advice.

3. [Topic 3 - e.g., Upcoming maintenance window]
What to expect and how to prepare.

QUICK TIP:
[Share a practical 1-2 sentence tip they can use immediately]

As always, if you have any questions or need support, don't hesitate to reach out. We're here to help.

Best regards,
The IGNYTE Team`,
        cta_text: 'Get a Free IT Assessment',
        cta_url: 'https://www.ignyteconsulting.com/quote.php'
    },
    security: {
        subject: 'Security Alert: Action Required',
        preview_text: 'Important security update from IGNYTE Consulting',
        headline: 'Security Alert',
        body_content: `ATTENTION: Important Security Update

We're reaching out to inform you about [describe the security issue].

WHAT HAPPENED:
[Brief, clear explanation of the threat or vulnerability]

WHO IS AFFECTED:
[Describe who should be concerned]

WHAT YOU SHOULD DO:
1. [Action step 1]
2. [Action step 2]
3. [Action step 3]

WHAT WE'RE DOING:
[Describe the steps IGNYTE is taking to protect clients]

If you need immediate assistance, contact us directly. Our team is standing by to help.

Stay safe,
The IGNYTE Security Team`,
        cta_text: 'Contact Support Now',
        cta_url: 'https://www.ignyteconsulting.com/#contact'
    },
    promo: {
        subject: 'Introducing: [New Service Name]',
        preview_text: 'Something new from IGNYTE that your business will love',
        headline: 'Introducing Something New',
        body_content: `Hi there,

We're excited to announce [new service/offer].

HERE'S WHAT IT MEANS FOR YOUR BUSINESS:

[Describe the benefit in 2-3 sentences. Focus on the problem it solves, not features.]

KEY HIGHLIGHTS:
- [Benefit 1]
- [Benefit 2]
- [Benefit 3]

SPECIAL OFFER:
[If applicable, describe any introductory pricing, free trial, or limited-time offer]

Want to learn more? Click below to get started or reply to this email with any questions.

Looking forward to helping you grow,
The IGNYTE Team`,
        cta_text: 'Learn More',
        cta_url: 'https://www.ignyteconsulting.com'
    },
    welcome: {
        subject: 'Welcome to IGNYTE Consulting!',
        preview_text: 'Here\'s everything you need to get started',
        headline: 'Welcome Aboard!',
        body_content: `Welcome to the IGNYTE family!

We're thrilled to have you on board. Here's everything you need to know to get started with our managed IT services.

YOUR KEY CONTACTS:
- Support: support@ignyteconsulting.com
- Emergencies: [phone number]
- Account Manager: [name]

WHAT HAPPENS NEXT:
1. We'll schedule your onboarding call within 48 hours
2. Our team will audit your current IT environment
3. You'll receive a customized IT roadmap
4. We begin proactive monitoring and support

HELPFUL RESOURCES:
- Client Portal: https://www.ignyteconsulting.com/client/
- Knowledge Base: [link]
- Emergency Procedures: [link]

If you have any questions at all, don't hesitate to reach out. We're here for you.

Welcome aboard,
The IGNYTE Team`,
        cta_text: 'Access Client Portal',
        cta_url: 'https://www.ignyteconsulting.com/client/'
    }
};

function loadTemplate(name) {
    const t = templates[name];
    if (!t) return;

    document.querySelector('input[name="subject"]').value = t.subject;
    document.querySelector('input[name="preview_text"]').value = t.preview_text;
    document.querySelector('input[name="headline"]').value = t.headline;
    document.querySelector('textarea[name="body_content"]').value = t.body_content;
    document.querySelector('input[name="cta_text"]').value = t.cta_text;
    document.querySelector('input[name="cta_url"]').value = t.cta_url;

    // Switch to compose tab
    switchTab('compose');
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.tab-btn')[0].classList.add('active');
}
</script>

</body>
</html>
