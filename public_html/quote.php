<?php
/**
 * IGNYTE Consulting - IT Services Quote Wizard
 * Multi-step questionnaire -> transparent quote -> contract/SOW -> signature
 */
require_once __DIR__ . '/admin/config.php';

$pdo = null;
try { $pdo = getDB(); } catch (Exception $e) { /* DB optional for form display */ }

// Load pricing from DB if available
$services = [];
if ($pdo) {
    try {
        $stmt = $pdo->query("SELECT * FROM quote_services WHERE is_active = 1 ORDER BY category, sort_order, name");
        $services = $stmt->fetchAll();
    } catch (Exception $e) { /* table may not exist yet */ }
}

// If no DB services, use defaults
if (empty($services)) {
    $services = [
        ['id'=>1,'name'=>'Managed IT Support','category'=>'managed','description'=>'Complete IT management including helpdesk, monitoring, and maintenance','price_per_user'=>75,'price_flat'=>0,'billing_type'=>'per_user'],
        ['id'=>2,'name'=>'Microsoft 365 Management','category'=>'cloud','description'=>'Full M365 administration, licensing, security, and support','price_per_user'=>25,'price_flat'=>0,'billing_type'=>'per_user'],
        ['id'=>3,'name'=>'Cybersecurity Suite','category'=>'security','description'=>'Endpoint protection, threat monitoring, security policies, and training','price_per_user'=>35,'price_flat'=>0,'billing_type'=>'per_user'],
        ['id'=>4,'name'=>'Cloud Backup & Recovery','category'=>'backup','description'=>'Automated backups, disaster recovery planning, and testing','price_per_user'=>15,'price_flat'=>0,'billing_type'=>'per_user'],
        ['id'=>5,'name'=>'Network Management','category'=>'network','description'=>'Firewall, switches, WiFi, VPN management and monitoring','price_per_user'=>0,'price_flat'=>500,'billing_type'=>'flat'],
        ['id'=>6,'name'=>'Server Management','category'=>'infrastructure','description'=>'On-premise or cloud server administration and patching','price_per_user'=>0,'price_flat'=>750,'billing_type'=>'flat'],
        ['id'=>7,'name'=>'Website Hosting & Management','category'=>'web','description'=>'Hosting, SSL, updates, backups, and performance optimization','price_per_user'=>0,'price_flat'=>200,'billing_type'=>'flat'],
        ['id'=>8,'name'=>'Data Management & Analytics','category'=>'data','description'=>'Database administration, reporting, and data governance','price_per_user'=>0,'price_flat'=>600,'billing_type'=>'flat'],
        ['id'=>9,'name'=>'Application Support','category'=>'apps','description'=>'Line-of-business application support and integration','price_per_user'=>20,'price_flat'=>0,'billing_type'=>'per_user'],
        ['id'=>10,'name'=>'WiFi Site Survey & Optimization','category'=>'network','description'=>'Professional WiFi assessment, heatmapping, and optimization','price_per_user'=>0,'price_flat'=>1500,'billing_type'=>'one_time'],
        ['id'=>11,'name'=>'IT Strategy & Consulting','category'=>'consulting','description'=>'Virtual CIO services, roadmap planning, and technology advisory','price_per_user'=>0,'price_flat'=>1000,'billing_type'=>'flat'],
        ['id'=>12,'name'=>'Compliance & Audit Support','category'=>'security','description'=>'PHIPA, SOC2, or industry compliance preparation and documentation','price_per_user'=>0,'price_flat'=>2000,'billing_type'=>'one_time'],
    ];
}

// Handle form submission
$rawInput = file_get_contents('php://input');
$jsonData = json_decode($rawInput, true);
$action = $jsonData['action'] ?? ($_POST['action'] ?? null);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action) {
    header('Content-Type: application/json');
    
    if ($action === 'submit_quote') {
        $data = $jsonData ?: $_POST;
        
        $response = ['success' => false];
        
        if ($pdo) {
            try {
                $quoteRef = 'IGN-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid()), 0, 6));
                
                $stmt = $pdo->prepare("INSERT INTO quotes (
                    reference_number, company_name, contact_name, contact_email, contact_phone,
                    industry, employee_count, current_setup, challenges, cloud_status,
                    support_level, selected_services, monthly_total, annual_total,
                    one_time_total, notes, status, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())");
                
                $stmt->execute([
                    $quoteRef,
                    $data['company_name'] ?? '',
                    $data['contact_name'] ?? '',
                    $data['contact_email'] ?? '',
                    $data['contact_phone'] ?? '',
                    $data['industry'] ?? '',
                    $data['employee_count'] ?? 0,
                    $data['current_setup'] ?? '',
                    json_encode($data['challenges'] ?? []),
                    $data['cloud_status'] ?? '',
                    $data['support_level'] ?? '',
                    json_encode($data['selected_services'] ?? []),
                    $data['monthly_total'] ?? 0,
                    $data['annual_total'] ?? 0,
                    $data['one_time_total'] ?? 0,
                    $data['notes'] ?? '',
                ]);
                
                $response = ['success' => true, 'reference' => $quoteRef, 'quote_id' => $pdo->lastInsertId()];
                
                // Send notification email
                $to = 'asam@ignyteconsulting.com';
                $subject = "New Quote Request: $quoteRef - " . ($data['company_name'] ?? 'Unknown');
                $body = "New quote submitted!\n\n";
                $body .= "Reference: $quoteRef\n";
                $body .= "Company: " . ($data['company_name'] ?? '') . "\n";
                $body .= "Contact: " . ($data['contact_name'] ?? '') . "\n";
                $body .= "Email: " . ($data['contact_email'] ?? '') . "\n";
                $body .= "Phone: " . ($data['contact_phone'] ?? '') . "\n";
                $body .= "Monthly Total: $" . number_format($data['monthly_total'] ?? 0, 2) . "\n";
                $body .= "Annual Total: $" . number_format($data['annual_total'] ?? 0, 2) . "\n";
                $body .= "\nView in admin: " . SITE_URL . "/admin/quotes.php\n";
                $headers = "From: noreply@ignyteconsulting.com\r\nReply-To: " . ($data['contact_email'] ?? '');
                @mail($to, $subject, $body, $headers);
                
            } catch (Exception $e) {
                $response = ['success' => false, 'error' => 'Database error'];
            }
        } else {
            $response = ['success' => false, 'error' => 'Database not configured'];
        }
        
        echo json_encode($response);
        exit;
    }
    
    if ($action === 'sign_contract') {
        $data = $jsonData ?: $_POST;
        
        $response = ['success' => false];
        
        if ($pdo && !empty($data['quote_id'])) {
            try {
                $stmt = $pdo->prepare("INSERT INTO contracts (
                    quote_id, signer_name, signer_email, signer_title,
                    signature_text, agreed_terms, ip_address, signed_at
                ) VALUES (?, ?, ?, ?, ?, 1, ?, NOW())");
                
                $stmt->execute([
                    $data['quote_id'],
                    $data['signer_name'] ?? '',
                    $data['signer_email'] ?? '',
                    $data['signer_title'] ?? '',
                    $data['signature_text'] ?? '',
                    $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
                ]);
                
                // Update quote status
                $pdo->prepare("UPDATE quotes SET status = 'signed' WHERE id = ?")->execute([$data['quote_id']]);
                
                $contractId = $pdo->lastInsertId();
                $response = ['success' => true, 'contract_id' => $contractId];
                
                // Notify admin
                $to = 'asam@ignyteconsulting.com';
                $subject = "Contract Signed! Quote #" . $data['quote_id'];
                $body = "A contract has been signed!\n\n";
                $body .= "Signer: " . ($data['signer_name'] ?? '') . "\n";
                $body .= "Email: " . ($data['signer_email'] ?? '') . "\n";
                $body .= "Title: " . ($data['signer_title'] ?? '') . "\n";
                $body .= "Signed at: " . date('Y-m-d H:i:s') . "\n";
                $body .= "\nView in admin: " . SITE_URL . "/admin/quotes.php\n";
                $headers = "From: noreply@ignyteconsulting.com";
                @mail($to, $subject, $body, $headers);
                
            } catch (Exception $e) {
                $response = ['success' => false, 'error' => 'Database error'];
            }
        }
        
        echo json_encode($response);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Get a Quote | IGNYTE Consulting</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Inter:wght@600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <style>
        .quote-page { min-height: 100vh; background: var(--light-grey); padding-top: 80px; }
        .quote-container { max-width: 860px; margin: 0 auto; padding: 40px 20px 80px; }
        .quote-header { text-align: center; margin-bottom: 48px; }
        .quote-header h1 { font-family: 'Inter', sans-serif; font-size: 2.2rem; color: var(--navy); margin-bottom: 12px; }
        .quote-header p { color: var(--slate); font-size: 1.05rem; max-width: 600px; margin: 0 auto; }
        
        .progress-bar { display: flex; align-items: center; justify-content: center; gap: 8px; margin-bottom: 40px; }
        .progress-step { width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.85rem; background: white; border: 2px solid rgba(0,0,0,0.1); color: var(--slate); transition: all 0.3s; }
        .progress-step.active { background: var(--flame-orange); border-color: var(--flame-orange); color: white; }
        .progress-step.done { background: #22c55e; border-color: #22c55e; color: white; }
        .progress-line { width: 40px; height: 3px; background: rgba(0,0,0,0.1); border-radius: 3px; transition: background 0.3s; }
        .progress-line.done { background: #22c55e; }
        
        .step-card { background: white; border-radius: 20px; padding: 40px; box-shadow: 0 4px 24px rgba(0,0,0,0.06); display: none; }
        .step-card.active { display: block; animation: fadeIn 0.3s ease; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        
        .step-title { font-family: 'Inter', sans-serif; font-size: 1.4rem; color: var(--navy); margin-bottom: 8px; }
        .step-subtitle { color: var(--slate); font-size: 0.95rem; margin-bottom: 28px; }
        
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; font-weight: 600; font-size: 0.88rem; color: var(--navy); margin-bottom: 8px; }
        .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 14px 18px; border: 1.5px solid rgba(0,0,0,0.1); border-radius: 12px; font-size: 0.95rem; font-family: 'DM Sans', sans-serif; transition: border-color 0.2s; outline: none; box-sizing: border-box; }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus { border-color: var(--flame-orange); }
        
        .radio-grid, .checkbox-grid { display: grid; gap: 10px; }
        .radio-option, .checkbox-option { display: flex; align-items: center; gap: 14px; padding: 16px 20px; background: var(--light-grey); border: 1.5px solid transparent; border-radius: 12px; cursor: pointer; transition: all 0.2s; }
        .radio-option:hover, .checkbox-option:hover { border-color: var(--flame-orange); background: white; }
        .radio-option.selected, .checkbox-option.selected { border-color: var(--flame-orange); background: rgba(238,90,36,0.05); }
        .radio-option input, .checkbox-option input { accent-color: var(--flame-orange); width: 18px; height: 18px; flex-shrink: 0; }
        .option-content { flex: 1; }
        .option-title { font-weight: 600; color: var(--navy); font-size: 0.95rem; }
        .option-desc { font-size: 0.82rem; color: var(--slate); margin-top: 2px; }
        
        .service-card { display: flex; align-items: start; gap: 16px; padding: 20px; background: var(--light-grey); border: 1.5px solid transparent; border-radius: 14px; cursor: pointer; transition: all 0.2s; margin-bottom: 12px; }
        .service-card:hover { border-color: var(--flame-orange); background: white; }
        .service-card.selected { border-color: var(--flame-orange); background: rgba(238,90,36,0.04); }
        .service-card input[type="checkbox"] { accent-color: var(--flame-orange); width: 20px; height: 20px; margin-top: 2px; flex-shrink: 0; }
        .service-info { flex: 1; }
        .service-name { font-weight: 700; color: var(--navy); font-size: 1rem; margin-bottom: 4px; }
        .service-desc { font-size: 0.85rem; color: var(--slate); line-height: 1.5; }
        .service-price { font-weight: 700; color: var(--flame-orange); font-size: 0.95rem; white-space: nowrap; }
        
        .btn-row { display: flex; gap: 12px; margin-top: 32px; }
        .btn-back { flex: 1; padding: 16px; border: 1.5px solid rgba(0,0,0,0.1); border-radius: 12px; background: white; font-family: 'DM Sans', sans-serif; font-size: 0.95rem; font-weight: 600; color: var(--slate); cursor: pointer; transition: all 0.2s; }
        .btn-back:hover { border-color: var(--navy); color: var(--navy); }
        .btn-next { flex: 2; padding: 16px; border: none; border-radius: 12px; background: var(--flame-orange); color: white; font-family: 'DM Sans', sans-serif; font-size: 0.95rem; font-weight: 700; cursor: pointer; transition: all 0.2s; }
        .btn-next:hover { background: #d4741e; transform: translateY(-1px); }
        .btn-next:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }
        
        /* Quote Summary */
        .quote-summary { background: linear-gradient(135deg, var(--navy), #001a4d); border-radius: 20px; padding: 40px; color: white; margin-bottom: 24px; }
        .quote-ref { font-size: 0.82rem; color: rgba(255,255,255,0.5); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 4px; }
        .quote-total-label { font-size: 0.9rem; color: rgba(255,255,255,0.7); margin-bottom: 4px; }
        .quote-total { font-family: 'Inter', sans-serif; font-size: 2.8rem; font-weight: 800; margin-bottom: 4px; }
        .quote-annual { font-size: 0.95rem; color: rgba(255,255,255,0.6); }
        
        .line-items { background: white; border-radius: 16px; overflow: hidden; margin-bottom: 24px; }
        .line-item { display: flex; justify-content: space-between; align-items: center; padding: 18px 24px; border-bottom: 1px solid rgba(0,0,0,0.05); }
        .line-item:last-child { border-bottom: none; }
        .line-item-name { font-weight: 600; color: var(--navy); font-size: 0.95rem; }
        .line-item-detail { font-size: 0.82rem; color: var(--slate); margin-top: 2px; }
        .line-item-price { font-weight: 700; color: var(--navy); font-size: 1rem; }
        .line-item-freq { font-size: 0.78rem; color: var(--slate); }
        .line-items-total { background: var(--light-grey); padding: 18px 24px; display: flex; justify-content: space-between; font-weight: 700; color: var(--navy); font-size: 1.1rem; }
        
        /* Contract/SOW */
        .contract-box { background: white; border-radius: 16px; padding: 36px; border: 1.5px solid rgba(0,0,0,0.08); margin-bottom: 24px; }
        .contract-title { font-family: 'Inter', sans-serif; font-size: 1.3rem; color: var(--navy); margin-bottom: 20px; text-align: center; }
        .contract-section { margin-bottom: 24px; }
        .contract-section h4 { font-size: 0.95rem; color: var(--navy); margin-bottom: 10px; padding-bottom: 8px; border-bottom: 1px solid rgba(0,0,0,0.06); }
        .contract-text { font-size: 0.88rem; color: var(--slate); line-height: 1.8; }
        .contract-text ul { padding-left: 20px; margin: 8px 0; }
        .contract-text li { margin-bottom: 6px; }
        
        .signature-area { background: var(--light-grey); border-radius: 14px; padding: 28px; text-align: center; }
        .signature-input { width: 100%; max-width: 400px; padding: 16px; border: none; border-bottom: 2px solid var(--navy); background: transparent; font-family: 'Dancing Script', cursive, 'DM Sans', sans-serif; font-size: 1.8rem; text-align: center; color: var(--navy); outline: none; }
        .signature-label { font-size: 0.82rem; color: var(--slate); margin-top: 8px; }
        
        .terms-check { display: flex; align-items: start; gap: 12px; padding: 16px; background: var(--light-grey); border-radius: 12px; margin-bottom: 16px; }
        .terms-check input { accent-color: var(--flame-orange); width: 20px; height: 20px; margin-top: 2px; flex-shrink: 0; }
        .terms-check label { font-size: 0.88rem; color: var(--slate); line-height: 1.6; cursor: pointer; }
        
        /* Success */
        .success-card { text-align: center; padding: 60px 40px; }
        .success-icon { font-size: 4rem; margin-bottom: 20px; }
        .success-title { font-family: 'Inter', sans-serif; font-size: 1.6rem; color: var(--navy); margin-bottom: 12px; }
        .success-text { color: var(--slate); font-size: 1rem; line-height: 1.7; max-width: 500px; margin: 0 auto 24px; }
        
        .category-label { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 1px; color: var(--flame-orange); font-weight: 700; margin-bottom: 12px; margin-top: 24px; }
        .category-label:first-child { margin-top: 0; }
        
        @media (max-width: 768px) {
            .quote-container { padding: 20px 16px 60px; }
            .step-card { padding: 24px 20px; }
            .quote-total { font-size: 2rem; }
            .line-item { flex-direction: column; align-items: start; gap: 4px; }
        }
    </style>
</head>
<body>

<!-- Navbar -->
<nav id="navbar" class="scrolled">
    <div class="container">
        <div class="nav-flex">
            <a href="/"><img src="logo.png" alt="IGNYTE Consulting" class="nav-logo"></a>
            <div class="nav-links" id="navLinks">
                <a href="/#about">About</a>
                <a href="/#services">Services</a>
                <a href="/#ai-msp">AI Solutions</a>
                <a href="/quote.php" style="color:var(--flame-orange);font-weight:700;">Get a Quote</a>
                <a href="blog.php">Blog</a>
                <a href="client/login.php">Client Login</a>
                <a href="/#consultation" class="btn-nav">Free Consultation</a>
            </div>
            <div class="mobile-menu-toggle" id="menuToggle" onclick="document.getElementById('navLinks').classList.toggle('open')">&#9776;</div>
        </div>
    </div>
</nav>

<div class="quote-page">
    <div class="quote-container">
        <div class="quote-header">
            <h1>Get Your IT Services Quote</h1>
            <p>Answer a few questions about your business and IT needs. Get a transparent, itemized quote in minutes &mdash; no hidden fees, no surprises.</p>
        </div>
        
        <!-- Progress Bar -->
        <div class="progress-bar" id="progressBar">
            <div class="progress-step active" data-step="1">1</div>
            <div class="progress-line" data-line="1"></div>
            <div class="progress-step" data-step="2">2</div>
            <div class="progress-line" data-line="2"></div>
            <div class="progress-step" data-step="3">3</div>
            <div class="progress-line" data-line="3"></div>
            <div class="progress-step" data-step="4">4</div>
            <div class="progress-line" data-line="4"></div>
            <div class="progress-step" data-step="5">5</div>
            <div class="progress-line" data-line="5"></div>
            <div class="progress-step" data-step="6">6</div>
        </div>

        <!-- Step 1: Business Info -->
        <div class="step-card active" id="step-1">
            <h2 class="step-title">Tell Us About Your Business</h2>
            <p class="step-subtitle">Basic information to customize your quote.</p>
            
            <div class="form-group">
                <label>Company Name *</label>
                <input type="text" id="company_name" placeholder="e.g. Acme Corp" required>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                <div class="form-group">
                    <label>Your Name *</label>
                    <input type="text" id="contact_name" placeholder="Full name">
                </div>
                <div class="form-group">
                    <label>Your Email *</label>
                    <input type="email" id="contact_email" placeholder="you@company.com">
                </div>
            </div>
            <div class="form-group">
                <label>Phone Number</label>
                <input type="tel" id="contact_phone" placeholder="(416) 555-1234">
            </div>
            <div class="form-group">
                <label>Industry *</label>
                <div class="radio-grid">
                    <label class="radio-option" onclick="selectRadio(this)">
                        <input type="radio" name="industry" value="healthcare"> 
                        <span class="option-title">Healthcare / Medical</span>
                    </label>
                    <label class="radio-option" onclick="selectRadio(this)">
                        <input type="radio" name="industry" value="legal"> 
                        <span class="option-title">Legal / Law Firm</span>
                    </label>
                    <label class="radio-option" onclick="selectRadio(this)">
                        <input type="radio" name="industry" value="nonprofit"> 
                        <span class="option-title">Non-Profit / NGO</span>
                    </label>
                    <label class="radio-option" onclick="selectRadio(this)">
                        <input type="radio" name="industry" value="finance"> 
                        <span class="option-title">Finance / Accounting</span>
                    </label>
                    <label class="radio-option" onclick="selectRadio(this)">
                        <input type="radio" name="industry" value="retail"> 
                        <span class="option-title">Retail / E-Commerce</span>
                    </label>
                    <label class="radio-option" onclick="selectRadio(this)">
                        <input type="radio" name="industry" value="education"> 
                        <span class="option-title">Education</span>
                    </label>
                    <label class="radio-option" onclick="selectRadio(this)">
                        <input type="radio" name="industry" value="manufacturing"> 
                        <span class="option-title">Manufacturing / Industrial</span>
                    </label>
                    <label class="radio-option" onclick="selectRadio(this)">
                        <input type="radio" name="industry" value="other"> 
                        <span class="option-title">Other</span>
                    </label>
                </div>
            </div>
            
            <div class="btn-row">
                <button class="btn-next" onclick="goToStep(2)">Next: Your IT Environment &rarr;</button>
            </div>
        </div>

        <!-- Step 2: Current IT Setup -->
        <div class="step-card" id="step-2">
            <h2 class="step-title">Your Current IT Environment</h2>
            <p class="step-subtitle">Help us understand what you&rsquo;re working with today.</p>
            
            <div class="form-group">
                <label>How many employees/users need IT support? *</label>
                <div class="radio-grid">
                    <label class="radio-option" onclick="selectRadio(this)">
                        <input type="radio" name="employee_count" value="1-10"> 
                        <div class="option-content"><span class="option-title">1 &ndash; 10 users</span><span class="option-desc">Small team or startup</span></div>
                    </label>
                    <label class="radio-option" onclick="selectRadio(this)">
                        <input type="radio" name="employee_count" value="11-25"> 
                        <div class="option-content"><span class="option-title">11 &ndash; 25 users</span><span class="option-desc">Growing team</span></div>
                    </label>
                    <label class="radio-option" onclick="selectRadio(this)">
                        <input type="radio" name="employee_count" value="26-50"> 
                        <div class="option-content"><span class="option-title">26 &ndash; 50 users</span><span class="option-desc">Mid-size organization</span></div>
                    </label>
                    <label class="radio-option" onclick="selectRadio(this)">
                        <input type="radio" name="employee_count" value="51-100"> 
                        <div class="option-content"><span class="option-title">51 &ndash; 100 users</span><span class="option-desc">Large organization</span></div>
                    </label>
                    <label class="radio-option" onclick="selectRadio(this)">
                        <input type="radio" name="employee_count" value="100+"> 
                        <div class="option-content"><span class="option-title">100+ users</span><span class="option-desc">Enterprise</span></div>
                    </label>
                </div>
            </div>
            
            <div class="form-group">
                <label>What is your current IT setup? *</label>
                <div class="radio-grid">
                    <label class="radio-option" onclick="selectRadio(this)">
                        <input type="radio" name="current_setup" value="none"> 
                        <div class="option-content"><span class="option-title">No dedicated IT</span><span class="option-desc">We handle IT ourselves or have no formal systems</span></div>
                    </label>
                    <label class="radio-option" onclick="selectRadio(this)">
                        <input type="radio" name="current_setup" value="basic"> 
                        <div class="option-content"><span class="option-title">Basic setup</span><span class="option-desc">Email, shared files, basic antivirus</span></div>
                    </label>
                    <label class="radio-option" onclick="selectRadio(this)">
                        <input type="radio" name="current_setup" value="managed"> 
                        <div class="option-content"><span class="option-title">Managed by IT person/MSP</span><span class="option-desc">We have someone handling IT but want to change</span></div>
                    </label>
                    <label class="radio-option" onclick="selectRadio(this)">
                        <input type="radio" name="current_setup" value="advanced"> 
                        <div class="option-content"><span class="option-title">Full IT department</span><span class="option-desc">Servers, cloud, security &mdash; looking to augment</span></div>
                    </label>
                </div>
            </div>
            
            <div class="form-group">
                <label>Cloud services status? *</label>
                <div class="radio-grid">
                    <label class="radio-option" onclick="selectRadio(this)">
                        <input type="radio" name="cloud_status" value="none"> 
                        <span class="option-title">No cloud services</span>
                    </label>
                    <label class="radio-option" onclick="selectRadio(this)">
                        <input type="radio" name="cloud_status" value="basic"> 
                        <span class="option-title">Basic (Google Workspace or M365 only)</span>
                    </label>
                    <label class="radio-option" onclick="selectRadio(this)">
                        <input type="radio" name="cloud_status" value="hybrid"> 
                        <span class="option-title">Hybrid (some cloud, some on-premise)</span>
                    </label>
                    <label class="radio-option" onclick="selectRadio(this)">
                        <input type="radio" name="cloud_status" value="full"> 
                        <span class="option-title">Fully cloud-based</span>
                    </label>
                </div>
            </div>
            
            <div class="btn-row">
                <button class="btn-back" onclick="goToStep(1)">&larr; Back</button>
                <button class="btn-next" onclick="goToStep(3)">Next: Your Challenges &rarr;</button>
            </div>
        </div>

        <!-- Step 3: Challenges & Needs -->
        <div class="step-card" id="step-3">
            <h2 class="step-title">Your IT Challenges</h2>
            <p class="step-subtitle">Select all that apply &mdash; this helps us recommend the right services.</p>
            
            <div class="checkbox-grid">
                <label class="checkbox-option" onclick="selectCheckbox(this)">
                    <input type="checkbox" name="challenges" value="security"> 
                    <div class="option-content"><span class="option-title">Cybersecurity & Data Protection</span><span class="option-desc">Worried about breaches, ransomware, or compliance</span></div>
                </label>
                <label class="checkbox-option" onclick="selectCheckbox(this)">
                    <input type="checkbox" name="challenges" value="cloud"> 
                    <div class="option-content"><span class="option-title">Cloud Migration & Management</span><span class="option-desc">Need to move to or better manage cloud services</span></div>
                </label>
                <label class="checkbox-option" onclick="selectCheckbox(this)">
                    <input type="checkbox" name="challenges" value="network"> 
                    <div class="option-content"><span class="option-title">Network & WiFi Issues</span><span class="option-desc">Slow internet, dead zones, unreliable connections</span></div>
                </label>
                <label class="checkbox-option" onclick="selectCheckbox(this)">
                    <input type="checkbox" name="challenges" value="helpdesk"> 
                    <div class="option-content"><span class="option-title">IT Support & Helpdesk</span><span class="option-desc">Need reliable support when things break</span></div>
                </label>
                <label class="checkbox-option" onclick="selectCheckbox(this)">
                    <input type="checkbox" name="challenges" value="backup"> 
                    <div class="option-content"><span class="option-title">Backup & Disaster Recovery</span><span class="option-desc">No backup plan or worried about data loss</span></div>
                </label>
                <label class="checkbox-option" onclick="selectCheckbox(this)">
                    <input type="checkbox" name="challenges" value="email"> 
                    <div class="option-content"><span class="option-title">Email & Collaboration</span><span class="option-desc">Need better email, Teams, SharePoint, or similar</span></div>
                </label>
                <label class="checkbox-option" onclick="selectCheckbox(this)">
                    <input type="checkbox" name="challenges" value="servers"> 
                    <div class="option-content"><span class="option-title">Server & Infrastructure</span><span class="option-desc">Physical or virtual servers need management</span></div>
                </label>
                <label class="checkbox-option" onclick="selectCheckbox(this)">
                    <input type="checkbox" name="challenges" value="apps"> 
                    <div class="option-content"><span class="option-title">Application & Software Management</span><span class="option-desc">Line-of-business apps, licensing, updates</span></div>
                </label>
                <label class="checkbox-option" onclick="selectCheckbox(this)">
                    <input type="checkbox" name="challenges" value="web"> 
                    <div class="option-content"><span class="option-title">Website & Web Applications</span><span class="option-desc">Need a new site, hosting, or web app development</span></div>
                </label>
                <label class="checkbox-option" onclick="selectCheckbox(this)">
                    <input type="checkbox" name="challenges" value="compliance"> 
                    <div class="option-content"><span class="option-title">Compliance & Auditing</span><span class="option-desc">PHIPA, SOC2, PCI, or industry regulations</span></div>
                </label>
            </div>
            
            <div class="btn-row">
                <button class="btn-back" onclick="goToStep(2)">&larr; Back</button>
                <button class="btn-next" onclick="goToStep(4)">Next: Choose Services &rarr;</button>
            </div>
        </div>

        <!-- Step 4: Service Selection -->
        <div class="step-card" id="step-4">
            <h2 class="step-title">Select Your Services</h2>
            <p class="step-subtitle">Choose the services you need. Pricing is shown per user/month or as a flat monthly fee.</p>
            
            <div id="services-list">
                <?php
                $lastCategory = '';
                $categoryNames = [
                    'managed' => 'Managed Services',
                    'cloud' => 'Cloud & Collaboration',
                    'security' => 'Security & Compliance',
                    'backup' => 'Backup & Recovery',
                    'network' => 'Network & Connectivity',
                    'infrastructure' => 'Infrastructure',
                    'web' => 'Web & Applications',
                    'data' => 'Data & Analytics',
                    'apps' => 'Applications',
                    'consulting' => 'Strategy & Consulting',
                ];
                foreach ($services as $svc):
                    $cat = $svc['category'] ?? 'other';
                    if ($cat !== $lastCategory):
                        $lastCategory = $cat;
                ?>
                <div class="category-label"><?= htmlspecialchars($categoryNames[$cat] ?? ucfirst($cat)) ?></div>
                <?php endif; ?>
                <label class="service-card" onclick="selectService(this)" data-id="<?= $svc['id'] ?>" data-price-user="<?= $svc['price_per_user'] ?? 0 ?>" data-price-flat="<?= $svc['price_flat'] ?? 0 ?>" data-billing="<?= $svc['billing_type'] ?? 'flat' ?>" data-name="<?= htmlspecialchars($svc['name']) ?>">
                    <input type="checkbox" name="services" value="<?= $svc['id'] ?>">
                    <div class="service-info">
                        <div class="service-name"><?= htmlspecialchars($svc['name']) ?></div>
                        <div class="service-desc"><?= htmlspecialchars($svc['description']) ?></div>
                    </div>
                    <div class="service-price">
                        <?php if (($svc['billing_type'] ?? '') === 'per_user'): ?>
                            $<?= number_format($svc['price_per_user'], 0) ?><span class="line-item-freq">/user/mo</span>
                        <?php elseif (($svc['billing_type'] ?? '') === 'one_time'): ?>
                            $<?= number_format($svc['price_flat'], 0) ?><span class="line-item-freq"> one-time</span>
                        <?php else: ?>
                            $<?= number_format($svc['price_flat'], 0) ?><span class="line-item-freq">/mo</span>
                        <?php endif; ?>
                    </div>
                </label>
                <?php endforeach; ?>
            </div>
            
            <div class="btn-row">
                <button class="btn-back" onclick="goToStep(3)">&larr; Back</button>
                <button class="btn-next" onclick="goToStep(5)">Next: Support Level &rarr;</button>
            </div>
        </div>

        <!-- Step 5: Support Level & Notes -->
        <div class="step-card" id="step-5">
            <h2 class="step-title">Support Preferences</h2>
            <p class="step-subtitle">Choose your preferred support level and add any additional details.</p>
            
            <div class="form-group">
                <label>Preferred Support Level *</label>
                <div class="radio-grid">
                    <label class="radio-option" onclick="selectRadio(this)">
                        <input type="radio" name="support_level" value="business"> 
                        <div class="option-content">
                            <span class="option-title">Business Hours (Mon-Fri 9am-5pm)</span>
                            <span class="option-desc">Standard support with next-business-day response for non-critical issues</span>
                        </div>
                    </label>
                    <label class="radio-option" onclick="selectRadio(this)">
                        <input type="radio" name="support_level" value="extended"> 
                        <div class="option-content">
                            <span class="option-title">Extended Hours (Mon-Sat 7am-9pm)</span>
                            <span class="option-desc">Extended coverage with 4-hour response for critical issues</span>
                        </div>
                    </label>
                    <label class="radio-option" onclick="selectRadio(this)">
                        <input type="radio" name="support_level" value="24x7"> 
                        <div class="option-content">
                            <span class="option-title">24/7 Support</span>
                            <span class="option-desc">Round-the-clock monitoring and 1-hour response for critical issues</span>
                        </div>
                    </label>
                </div>
            </div>
            
            <div class="form-group">
                <label>Contract Term *</label>
                <div class="radio-grid">
                    <label class="radio-option" onclick="selectRadio(this)">
                        <input type="radio" name="contract_term" value="monthly"> 
                        <div class="option-content">
                            <span class="option-title">Month-to-Month</span>
                            <span class="option-desc">Flexible, cancel anytime with 30 days notice</span>
                        </div>
                    </label>
                    <label class="radio-option" onclick="selectRadio(this)">
                        <input type="radio" name="contract_term" value="annual"> 
                        <div class="option-content">
                            <span class="option-title">Annual (Save 10%)</span>
                            <span class="option-desc">12-month commitment with 10% discount</span>
                        </div>
                    </label>
                    <label class="radio-option" onclick="selectRadio(this)">
                        <input type="radio" name="contract_term" value="2year"> 
                        <div class="option-content">
                            <span class="option-title">2-Year (Save 15%)</span>
                            <span class="option-desc">24-month commitment with 15% discount</span>
                        </div>
                    </label>
                </div>
            </div>
            
            <div class="form-group">
                <label>Additional Notes or Requirements</label>
                <textarea id="notes" rows="4" placeholder="Any specific requirements, timelines, or questions..."></textarea>
            </div>
            
            <div class="btn-row">
                <button class="btn-back" onclick="goToStep(4)">&larr; Back</button>
                <button class="btn-next" onclick="generateQuote()">Generate My Quote &rarr;</button>
            </div>
        </div>

        <!-- Step 6: Quote & Contract -->
        <div class="step-card" id="step-6">
            <div id="quote-display"></div>
        </div>
    </div>
</div>

<script>
var currentStep = 1;
var quoteData = {};
var quoteId = null;

function goToStep(step) {
    // Validate current step
    if (step > currentStep) {
        if (!validateStep(currentStep)) return;
    }
    
    document.getElementById('step-' + currentStep).classList.remove('active');
    document.getElementById('step-' + step).classList.add('active');
    currentStep = step;
    updateProgress();
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function updateProgress() {
    var steps = document.querySelectorAll('.progress-step');
    var lines = document.querySelectorAll('.progress-line');
    steps.forEach(function(s, i) {
        var n = i + 1;
        s.classList.remove('active', 'done');
        if (n < currentStep) s.classList.add('done');
        else if (n === currentStep) s.classList.add('active');
    });
    lines.forEach(function(l, i) {
        l.classList.toggle('done', i + 1 < currentStep);
    });
}

function validateStep(step) {
    if (step === 1) {
        var name = document.getElementById('company_name').value.trim();
        var cname = document.getElementById('contact_name').value.trim();
        var email = document.getElementById('contact_email').value.trim();
        var industry = document.querySelector('input[name="industry"]:checked');
        if (!name) { alert('Please enter your company name.'); return false; }
        if (!cname) { alert('Please enter your name.'); return false; }
        if (!email || email.indexOf('@') === -1) { alert('Please enter a valid email.'); return false; }
        if (!industry) { alert('Please select your industry.'); return false; }
        return true;
    }
    if (step === 2) {
        if (!document.querySelector('input[name="employee_count"]:checked')) { alert('Please select your employee count.'); return false; }
        if (!document.querySelector('input[name="current_setup"]:checked')) { alert('Please select your current IT setup.'); return false; }
        if (!document.querySelector('input[name="cloud_status"]:checked')) { alert('Please select your cloud status.'); return false; }
        return true;
    }
    if (step === 3) {
        var checks = document.querySelectorAll('input[name="challenges"]:checked');
        if (checks.length === 0) { alert('Please select at least one challenge.'); return false; }
        return true;
    }
    if (step === 4) {
        var selected = document.querySelectorAll('input[name="services"]:checked');
        if (selected.length === 0) { alert('Please select at least one service.'); return false; }
        return true;
    }
    if (step === 5) {
        if (!document.querySelector('input[name="support_level"]:checked')) { alert('Please select a support level.'); return false; }
        if (!document.querySelector('input[name="contract_term"]:checked')) { alert('Please select a contract term.'); return false; }
        return true;
    }
    return true;
}

function selectRadio(el) {
    var group = el.closest('.radio-grid');
    group.querySelectorAll('.radio-option').forEach(function(opt) { opt.classList.remove('selected'); });
    el.classList.add('selected');
    el.querySelector('input').checked = true;
}

function selectCheckbox(el) {
    var cb = el.querySelector('input');
    // Let the browser handle the checkbox toggle, just update style
    setTimeout(function() {
        el.classList.toggle('selected', cb.checked);
    }, 0);
}

function selectService(el) {
    var cb = el.querySelector('input');
    setTimeout(function() {
        el.classList.toggle('selected', cb.checked);
    }, 0);
}

function getUserCount() {
    var val = document.querySelector('input[name="employee_count"]:checked');
    if (!val) return 10;
    var map = { '1-10': 5, '11-25': 18, '26-50': 38, '51-100': 75, '100+': 125 };
    return map[val.value] || 10;
}

function getDiscount() {
    var term = document.querySelector('input[name="contract_term"]:checked');
    if (!term) return 0;
    if (term.value === 'annual') return 0.10;
    if (term.value === '2year') return 0.15;
    return 0;
}

function generateQuote() {
    if (!validateStep(5)) return;
    
    var userCount = getUserCount();
    var discount = getDiscount();
    var selectedServices = document.querySelectorAll('input[name="services"]:checked');
    var monthlyTotal = 0;
    var oneTimeTotal = 0;
    var lineItems = [];
    
    selectedServices.forEach(function(cb) {
        var card = cb.closest('.service-card');
        var priceUser = parseFloat(card.dataset.priceUser) || 0;
        var priceFlat = parseFloat(card.dataset.priceFlat) || 0;
        var billing = card.dataset.billing;
        var name = card.dataset.name;
        var monthly = 0;
        var detail = '';
        
        if (billing === 'per_user') {
            monthly = priceUser * userCount;
            detail = userCount + ' users x $' + priceUser + '/user';
        } else if (billing === 'one_time') {
            oneTimeTotal += priceFlat;
            detail = 'One-time setup';
        } else {
            monthly = priceFlat;
            detail = 'Flat monthly';
        }
        
        lineItems.push({ name: name, monthly: monthly, detail: detail, billing: billing, oneTime: billing === 'one_time' ? priceFlat : 0 });
        monthlyTotal += monthly;
    });
    
    // Support level surcharge
    var supportLevel = document.querySelector('input[name="support_level"]:checked').value;
    var supportSurcharge = 0;
    if (supportLevel === 'extended') { supportSurcharge = monthlyTotal * 0.15; }
    else if (supportLevel === '24x7') { supportSurcharge = monthlyTotal * 0.30; }
    
    if (supportSurcharge > 0) {
        var pct = supportLevel === 'extended' ? '15%' : '30%';
        lineItems.push({ name: (supportLevel === '24x7' ? '24/7' : 'Extended Hours') + ' Support Premium', monthly: supportSurcharge, detail: pct + ' of base services', billing: 'flat', oneTime: 0 });
        monthlyTotal += supportSurcharge;
    }
    
    // Apply discount
    var discountAmount = monthlyTotal * discount;
    var discountedMonthly = monthlyTotal - discountAmount;
    var annualTotal = discountedMonthly * 12;
    
    var contractTerm = document.querySelector('input[name="contract_term"]:checked').value;
    var termLabel = contractTerm === 'monthly' ? 'Month-to-Month' : (contractTerm === 'annual' ? '12-Month' : '24-Month');
    
    // Collect all form data
    quoteData = {
        company_name: document.getElementById('company_name').value.trim(),
        contact_name: document.getElementById('contact_name').value.trim(),
        contact_email: document.getElementById('contact_email').value.trim(),
        contact_phone: document.getElementById('contact_phone').value.trim(),
        industry: document.querySelector('input[name="industry"]:checked').value,
        employee_count: userCount,
        current_setup: document.querySelector('input[name="current_setup"]:checked').value,
        challenges: Array.from(document.querySelectorAll('input[name="challenges"]:checked')).map(function(c) { return c.value; }),
        cloud_status: document.querySelector('input[name="cloud_status"]:checked').value,
        support_level: supportLevel,
        contract_term: contractTerm,
        selected_services: lineItems.map(function(l) { return l.name; }),
        monthly_total: discountedMonthly,
        annual_total: annualTotal,
        one_time_total: oneTimeTotal,
        notes: document.getElementById('notes').value.trim()
    };
    
    // Build quote display
    var html = '';
    html += '<div class="quote-summary">';
    html += '<div class="quote-ref">Quote Estimate &bull; ' + termLabel + ' Term</div>';
    html += '<div class="quote-total-label">Estimated Monthly Investment</div>';
    html += '<div class="quote-total">$' + numberFormat(discountedMonthly) + '<span style="font-size:1rem;font-weight:400;opacity:0.6;">/month</span></div>';
    html += '<div class="quote-annual">Annual: $' + numberFormat(annualTotal) + '/year';
    if (oneTimeTotal > 0) html += ' + $' + numberFormat(oneTimeTotal) + ' one-time setup';
    html += '</div>';
    if (discount > 0) {
        html += '<div style="margin-top:12px;padding:8px 16px;background:rgba(34,197,94,0.2);border-radius:8px;display:inline-block;font-size:0.85rem;font-weight:600;">Saving $' + numberFormat(discountAmount) + '/mo with ' + termLabel + ' commitment</div>';
    }
    html += '</div>';
    
    // Line items
    html += '<div class="line-items">';
    html += '<div style="padding:18px 24px;font-weight:700;color:var(--navy);border-bottom:2px solid rgba(0,0,0,0.06);font-size:0.95rem;">Service Breakdown</div>';
    for (var i = 0; i < lineItems.length; i++) {
        var item = lineItems[i];
        html += '<div class="line-item">';
        html += '<div><div class="line-item-name">' + item.name + '</div><div class="line-item-detail">' + item.detail + '</div></div>';
        if (item.billing === 'one_time') {
            html += '<div style="text-align:right;"><div class="line-item-price">$' + numberFormat(item.oneTime) + '</div><div class="line-item-freq">one-time</div></div>';
        } else {
            html += '<div style="text-align:right;"><div class="line-item-price">$' + numberFormat(item.monthly) + '</div><div class="line-item-freq">/month</div></div>';
        }
        html += '</div>';
    }
    if (discount > 0) {
        html += '<div class="line-item" style="color:#22c55e;"><div><div class="line-item-name" style="color:#22c55e;">' + (discount*100) + '% ' + termLabel + ' Discount</div></div><div class="line-item-price" style="color:#22c55e;">-$' + numberFormat(discountAmount) + '/mo</div></div>';
    }
    html += '<div class="line-items-total"><span>Monthly Total</span><span>$' + numberFormat(discountedMonthly) + '/mo</span></div>';
    html += '</div>';
    
    // Contract/SOW section
    html += '<div class="contract-box">';
    html += '<div class="contract-title">Statement of Work (SOW)</div>';
    html += '<div class="contract-section"><h4>1. Parties</h4><div class="contract-text">';
    html += '<p>This Statement of Work (&ldquo;SOW&rdquo;) is entered into between <strong>IGNYTE Consulting</strong> (&ldquo;Provider&rdquo;) and <strong>' + escapeHtml(quoteData.company_name) + '</strong> (&ldquo;Client&rdquo;).</p>';
    html += '</div></div>';
    
    html += '<div class="contract-section"><h4>2. Scope of Services</h4><div class="contract-text"><p>Provider agrees to deliver the following managed IT services:</p><ul>';
    for (var j = 0; j < lineItems.length; j++) {
        html += '<li>' + escapeHtml(lineItems[j].name) + '</li>';
    }
    html += '</ul></div></div>';
    
    html += '<div class="contract-section"><h4>3. Service Level Agreement (SLA)</h4><div class="contract-text">';
    if (supportLevel === 'business') {
        html += '<p>Support hours: Monday&ndash;Friday, 9:00 AM &ndash; 5:00 PM EST<br>Response time (Critical): 4 hours<br>Response time (Standard): Next business day</p>';
    } else if (supportLevel === 'extended') {
        html += '<p>Support hours: Monday&ndash;Saturday, 7:00 AM &ndash; 9:00 PM EST<br>Response time (Critical): 2 hours<br>Response time (Standard): 4 hours</p>';
    } else {
        html += '<p>Support hours: 24/7/365<br>Response time (Critical): 1 hour<br>Response time (Standard): 2 hours</p>';
    }
    html += '</div></div>';
    
    html += '<div class="contract-section"><h4>4. Term & Billing</h4><div class="contract-text">';
    html += '<p>Contract Term: <strong>' + termLabel + '</strong><br>';
    html += 'Monthly Fee: <strong>$' + numberFormat(discountedMonthly) + '</strong><br>';
    if (oneTimeTotal > 0) html += 'One-Time Setup Fees: <strong>$' + numberFormat(oneTimeTotal) + '</strong><br>';
    html += 'Billing Cycle: Monthly, due on the 1st<br>';
    html += 'Payment Terms: Net 15 days</p>';
    html += '</div></div>';
    
    html += '<div class="contract-section"><h4>5. Terms & Conditions</h4><div class="contract-text">';
    html += '<ul>';
    html += '<li>Services commence upon execution of this SOW and payment of any one-time fees.</li>';
    html += '<li>Either party may terminate with 30 days written notice (month-to-month) or at end of term (annual/2-year).</li>';
    html += '<li>Provider maintains confidentiality of all Client data and systems access.</li>';
    html += '<li>Client agrees to provide reasonable access to systems and timely responses to Provider requests.</li>';
    html += '<li>Provider carries professional liability and cyber liability insurance.</li>';
    html += '<li>Scope changes require written amendment to this SOW.</li>';
    html += '<li>All prices are in CAD and exclude applicable taxes.</li>';
    html += '</ul>';
    html += '</div></div>';
    html += '</div>';
    
    // Signature area
    html += '<div class="contract-box">';
    html += '<h3 style="font-family:Inter,sans-serif;font-size:1.1rem;color:var(--navy);margin-bottom:20px;text-align:center;">Sign & Confirm</h3>';
    html += '<div class="form-group"><label>Full Name *</label><input type="text" id="signer_name" value="' + escapeHtml(quoteData.contact_name) + '"></div>';
    html += '<div class="form-group"><label>Email *</label><input type="email" id="signer_email" value="' + escapeHtml(quoteData.contact_email) + '"></div>';
    html += '<div class="form-group"><label>Title / Role</label><input type="text" id="signer_title" placeholder="e.g. CEO, Office Manager, IT Director"></div>';
    html += '<div class="signature-area"><p style="font-size:0.85rem;color:var(--slate);margin-bottom:12px;">Type your full name below as your electronic signature</p>';
    html += '<input type="text" class="signature-input" id="signature_text" placeholder="Type your full name"><p class="signature-label">Electronic Signature</p></div>';
    html += '<div style="margin-top:20px;">';
    html += '<div class="terms-check"><input type="checkbox" id="agree_terms"><label for="agree_terms">I have read and agree to the Statement of Work, service terms, and pricing outlined above. I understand this constitutes a binding agreement.</label></div>';
    html += '<div class="terms-check"><input type="checkbox" id="agree_billing"><label for="agree_billing">I authorize IGNYTE Consulting to invoice the monthly fees as described above beginning on the service start date.</label></div>';
    html += '</div>';
    html += '<div class="btn-row"><button class="btn-back" onclick="goToStep(5)">&larr; Edit Quote</button>';
    html += '<button class="btn-next" id="btn-sign" onclick="submitSignedContract()">Submit Quote Request &rarr;</button></div>';
    html += '</div>';
    
    document.getElementById('quote-display').innerHTML = html;
    goToStep(6);
    
    // Submit quote to DB
    submitQuoteToDb();
}

function submitQuoteToDb() {
    fetch('/quote.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(Object.assign({}, quoteData, { action: 'submit_quote' }))
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            quoteId = data.quote_id;
            // Update ref number display
            var refEl = document.querySelector('.quote-ref');
            if (refEl) refEl.textContent = 'Quote ' + data.reference + ' \u2022 ' + refEl.textContent.split('\u2022')[1];
        }
    })
    .catch(function() { /* silent - quote still displays */ });
}

function submitSignedContract() {
    var signerName = document.getElementById('signer_name').value.trim();
    var signerEmail = document.getElementById('signer_email').value.trim();
    var signerTitle = document.getElementById('signer_title').value.trim();
    var signature = document.getElementById('signature_text').value.trim();
    var agreeTerms = document.getElementById('agree_terms').checked;
    var agreeBilling = document.getElementById('agree_billing').checked;
    
    if (!signerName) { alert('Please enter your name.'); return; }
    if (!signerEmail) { alert('Please enter your email.'); return; }
    if (!signature) { alert('Please type your full name as your electronic signature.'); return; }
    if (!agreeTerms) { alert('Please agree to the terms and conditions.'); return; }
    if (!agreeBilling) { alert('Please authorize billing.'); return; }
    
    var btn = document.getElementById('btn-sign');
    btn.disabled = true;
    btn.textContent = 'Submitting...';
    
    var contractData = {
        action: 'sign_contract',
        quote_id: quoteId,
        signer_name: signerName,
        signer_email: signerEmail,
        signer_title: signerTitle,
        signature_text: signature
    };
    
    fetch('/quote.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(contractData)
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            showSuccess();
        } else {
            // Even if DB fails, show success (quote was generated)
            showSuccess();
        }
    })
    .catch(function() {
        showSuccess();
    });
}

function showSuccess() {
    var html = '<div class="success-card">';
    html += '<div class="success-icon">&#127881;</div>';
    html += '<div class="success-title">Quote Submitted Successfully!</div>';
    html += '<div class="success-text">Thank you, <strong>' + escapeHtml(quoteData.contact_name) + '</strong>. Your quote request has been received and our team will review it within 1 business day. You\'ll receive a confirmation email at <strong>' + escapeHtml(quoteData.contact_email) + '</strong>.</div>';
    html += '<div style="background:var(--light-grey);padding:24px;border-radius:14px;margin-bottom:24px;">';
    html += '<p style="font-size:0.88rem;color:var(--slate);margin-bottom:8px;"><strong style="color:var(--navy);">What happens next?</strong></p>';
    html += '<ol style="font-size:0.88rem;color:var(--slate);line-height:2;padding-left:20px;margin:0;">';
    html += '<li>Our team reviews your requirements and confirms pricing</li>';
    html += '<li>We schedule a brief discovery call to finalize details</li>';
    html += '<li>You receive the final SOW for signature</li>';
    html += '<li>Onboarding begins within 5 business days of signing</li>';
    html += '</ol></div>';
    html += '<a href="/" class="btn-primary" style="display:inline-block;text-decoration:none;padding:16px 32px;border-radius:12px;">&larr; Back to Home</a>';
    html += '</div>';
    
    document.getElementById('quote-display').innerHTML = html;
    document.querySelector('.progress-bar').style.display = 'none';
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function numberFormat(n) {
    return n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

function escapeHtml(str) {
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(str || ''));
    return div.innerHTML;
}
</script>

</body>
</html>
