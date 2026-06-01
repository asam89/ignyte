<?php
/**
 * IGNYTE Consulting - AI Consultation Endpoint
 * 
 * Receives a business problem description, creates a CRM lead,
 * generates follow-up questions, and emails them to the team.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$name = trim($input['name'] ?? '');
$email = trim($input['email'] ?? '');
$businessProblem = trim($input['problem'] ?? '');

if (!$email || !$businessProblem) {
    echo json_encode(['success' => false, 'error' => 'Email and business problem are required.']);
    exit;
}

if (!$name) {
    $name = explode('@', $email)[0];
}

require_once __DIR__ . '/admin/config.php';

// Generate AI follow-up questions based on the business problem
$followUpQuestions = generateFollowUpQuestions($businessProblem);

// Create CRM lead
$leadCreated = false;
try {
    $pdo = getDB();
    
    // Check if email already exists
    $stmt = $pdo->prepare('SELECT id FROM crm_clients WHERE email = ?');
    $stmt->execute([$email]);
    $existing = $stmt->fetch();
    
    if ($existing) {
        // Update notes with new inquiry
        $stmt = $pdo->prepare('UPDATE crm_clients SET notes = CONCAT(IFNULL(notes, ""), "\n\n[AI Consult ' . date('Y-m-d H:i') . '] ", ?) WHERE id = ?');
        $stmt->execute([$businessProblem, $existing['id']]);
        $leadCreated = true;
    } else {
        // Create new prospect
        $stmt = $pdo->prepare('INSERT INTO crm_clients (full_name, email, status, notes) VALUES (?, ?, "prospect", ?)');
        $stmt->execute([$name, $email, '[AI Consult ' . date('Y-m-d H:i') . '] ' . $businessProblem]);
        $leadCreated = true;
    }
} catch (Exception $e) {
    // CRM insert failed but we can still proceed with the email
}

// Send email to asam@ignyteconsulting.com
$emailSent = sendConsultationEmail($name, $email, $businessProblem, $followUpQuestions);

// Build response for the user
$response = [
    'success' => true,
    'message' => "Thank you, $name! We've received your inquiry and our team will follow up shortly.",
    'follow_up_questions' => $followUpQuestions,
    'lead_created' => $leadCreated,
];

echo json_encode($response);

/**
 * Generate contextual follow-up questions based on the business problem.
 * Uses keyword analysis for instant responses (no external API needed).
 */
function generateFollowUpQuestions(string $problem): array {
    $problem = strtolower($problem);
    $questions = [];
    
    // Always ask these
    $questions[] = "What is the size of your team / organization?";
    $questions[] = "What is your timeline for addressing this?";
    
    // Context-specific questions based on keywords
    if (strpos($problem, 'security') !== false || strpos($problem, 'breach') !== false || strpos($problem, 'hack') !== false || strpos($problem, 'cyber') !== false) {
        $questions[] = "Have you experienced any security incidents recently?";
        $questions[] = "Do you currently have any security tools or protocols in place?";
        $questions[] = "Are you subject to any compliance requirements (HIPAA, SOC2, PCI)?";
    }
    
    if (strpos($problem, 'cloud') !== false || strpos($problem, 'migration') !== false || strpos($problem, 'aws') !== false || strpos($problem, 'azure') !== false) {
        $questions[] = "What is your current hosting infrastructure?";
        $questions[] = "Do you have an existing cloud strategy or are you starting from scratch?";
        $questions[] = "What is your estimated monthly cloud budget?";
    }
    
    if (strpos($problem, 'ai') !== false || strpos($problem, 'automation') !== false || strpos($problem, 'machine learning') !== false || strpos($problem, 'artificial') !== false) {
        $questions[] = "What manual processes are you looking to automate?";
        $questions[] = "What type of data do you currently collect?";
        $questions[] = "Have you explored any AI tools already?";
    }
    
    if (strpos($problem, 'network') !== false || strpos($problem, 'slow') !== false || strpos($problem, 'downtime') !== false || strpos($problem, 'connectivity') !== false) {
        $questions[] = "How many locations/offices do you have?";
        $questions[] = "What is your current network setup (ISP, hardware, VPN)?";
        $questions[] = "How often do you experience downtime?";
    }
    
    if (strpos($problem, 'software') !== false || strpos($problem, 'app') !== false || strpos($problem, 'develop') !== false || strpos($problem, 'build') !== false || strpos($problem, 'website') !== false) {
        $questions[] = "Do you have an existing development team?";
        $questions[] = "What platforms do your users primarily use (web, mobile, desktop)?";
        $questions[] = "Do you have specific technology preferences or constraints?";
    }
    
    if (strpos($problem, 'cost') !== false || strpos($problem, 'expensive') !== false || strpos($problem, 'budget') !== false || strpos($problem, 'save') !== false || strpos($problem, 'reduce') !== false) {
        $questions[] = "What is your current annual IT spend?";
        $questions[] = "Which tools/services are you currently paying for?";
        $questions[] = "Are there specific areas where you feel you're overspending?";
    }
    
    if (strpos($problem, 'data') !== false || strpos($problem, 'analytics') !== false || strpos($problem, 'report') !== false || strpos($problem, 'dashboard') !== false) {
        $questions[] = "What data sources are you currently working with?";
        $questions[] = "What insights are most valuable to your business?";
        $questions[] = "Do you have a data warehouse or BI tool in place?";
    }
    
    // General fallback if no specific category matched
    if (count($questions) <= 2) {
        $questions[] = "Can you walk us through the specific pain points you're experiencing?";
        $questions[] = "What solutions have you tried so far?";
        $questions[] = "What does success look like for your organization?";
    }
    
    // Cap at 5 questions
    return array_slice(array_unique($questions), 0, 5);
}

/**
 * Send consultation email to the IGNYTE team.
 */
function sendConsultationEmail(string $name, string $email, string $problem, array $questions): bool {
    $to = 'asam@ignyteconsulting.com';
    $subject = "New AI Consultation Lead: $name";
    
    $questionsHtml = '';
    foreach ($questions as $i => $q) {
        $num = $i + 1;
        $questionsHtml .= "<li style='margin-bottom:8px;'>$q</li>";
    }
    
    $body = "
    <html>
    <body style='font-family: Arial, sans-serif; color: #333; max-width: 600px;'>
        <div style='background: #002366; color: white; padding: 20px 24px; border-radius: 8px 8px 0 0;'>
            <h2 style='margin:0;'>New AI Consultation Lead</h2>
        </div>
        <div style='background: #f9fafb; padding: 24px; border: 1px solid #e5e7eb; border-top: none; border-radius: 0 0 8px 8px;'>
            <p><strong>Name:</strong> $name</p>
            <p><strong>Email:</strong> <a href='mailto:$email'>$email</a></p>
            <p><strong>Submitted:</strong> " . date('M j, Y g:i A') . "</p>
            
            <div style='background: white; padding: 16px; border-radius: 8px; margin: 16px 0; border-left: 4px solid #EE5A24;'>
                <h3 style='margin-top:0; color: #002366;'>Business Problem</h3>
                <p>" . nl2br(htmlspecialchars($problem)) . "</p>
            </div>
            
            <div style='background: white; padding: 16px; border-radius: 8px; margin: 16px 0; border-left: 4px solid #0047BB;'>
                <h3 style='margin-top:0; color: #002366;'>Suggested Follow-Up Questions</h3>
                <ol style='padding-left: 20px;'>$questionsHtml</ol>
            </div>
            
            <p style='font-size: 0.85rem; color: #666;'>This lead was auto-created in your CRM. <a href='" . SITE_URL . "/admin/crm.php'>View in CRM &rarr;</a></p>
        </div>
    </body>
    </html>";
    
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: IGNYTE AI Consultation <noreply@ignyteconsulting.com>\r\n";
    $headers .= "Reply-To: $email\r\n";
    
    return @mail($to, $subject, $body, $headers);
}
