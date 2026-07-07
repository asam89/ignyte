<?php
/**
 * IGNYTE Consulting - Resend Email API Integration
 * 
 * Handles sending newsletters via the Resend API.
 * Uses Resend REST API v1 via cURL (no external dependencies).
 * 
 * API docs: https://resend.com/docs/api-reference
 */

require_once __DIR__ . '/config.php';

class ResendAPI {
    private $apiKey;
    private $baseUrl = 'https://api.resend.com';
    private $fromEmail;
    private $fromName;

    public function __construct() {
        $this->apiKey = defined('RESEND_API_KEY') ? RESEND_API_KEY : '';
        $this->fromEmail = defined('RESEND_FROM_EMAIL') ? RESEND_FROM_EMAIL : 'info@ignyteconsulting.com';
        $this->fromName = defined('RESEND_FROM_NAME') ? RESEND_FROM_NAME : 'IGNYTE Consulting';
    }

    public function isConfigured(): bool {
        return !empty($this->apiKey);
    }

    private function request(string $method, string $endpoint, array $data = null): array {
        $url = $this->baseUrl . $endpoint;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        } elseif ($method === 'GET') {
            // default
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['success' => false, 'error' => "cURL error: $error", 'http_code' => 0];
        }

        $decoded = json_decode($response, true) ?? [];
        $decoded['http_code'] = $httpCode;
        $decoded['success'] = ($httpCode >= 200 && $httpCode < 300);

        return $decoded;
    }

    /**
     * Send a single email.
     */
    public function sendEmail(string $to, string $subject, string $html, string $fromName = null, string $replyTo = null): array {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'Resend not configured'];
        }

        $from = ($fromName ?? $this->fromName) . ' <' . $this->fromEmail . '>';

        $data = [
            'from' => $from,
            'to' => [$to],
            'subject' => $subject,
            'html' => $html,
        ];

        if ($replyTo) {
            $data['reply_to'] = $replyTo;
        }

        return $this->request('POST', '/emails', $data);
    }

    /**
     * Send a batch of emails (up to 100 per request).
     */
    public function sendBatch(array $emails): array {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'Resend not configured'];
        }

        return $this->request('POST', '/emails/batch', $emails);
    }

    /**
     * Send newsletter to a list of recipients.
     * Splits into batches of 100 if needed.
     */
    public function sendNewsletter(array $recipients, string $subject, string $html, string $fromName = null, string $replyTo = null): array {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'Resend not configured'];
        }

        $from = ($fromName ?? $this->fromName) . ' <' . $this->fromEmail . '>';
        $sent = 0;
        $failed = 0;
        $errors = [];

        // Split into batches of 100
        $batches = array_chunk($recipients, 100);

        foreach ($batches as $batch) {
            $emails = [];
            foreach ($batch as $recipient) {
                $email = is_array($recipient) ? $recipient['email'] : $recipient;
                $emailData = [
                    'from' => $from,
                    'to' => [$email],
                    'subject' => $subject,
                    'html' => $html,
                ];
                if ($replyTo) {
                    $emailData['reply_to'] = $replyTo;
                }
                $emails[] = $emailData;
            }

            $result = $this->sendBatch($emails);

            if ($result['success'] && isset($result['data'])) {
                $sent += count($result['data']);
            } elseif ($result['success']) {
                $sent += count($batch);
            } else {
                $failed += count($batch);
                $errors[] = $result['error'] ?? $result['message'] ?? 'Unknown batch error';
            }
        }

        return [
            'success' => $failed === 0,
            'sent' => $sent,
            'failed' => $failed,
            'errors' => $errors,
            'total' => count($recipients),
        ];
    }

    /**
     * Send a test email to a single address.
     */
    public function sendTestEmail(string $testEmail, string $subject, string $html, string $fromName = null, string $replyTo = null): array {
        return $this->sendEmail($testEmail, '[TEST] ' . $subject, $html, $fromName, $replyTo);
    }

    /**
     * Get list of emails sent (for history/tracking).
     */
    public function getEmails(): array {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'Resend not configured'];
        }

        return $this->request('GET', '/emails');
    }

    /**
     * Get a single email's delivery status.
     */
    public function getEmail(string $emailId): array {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'Resend not configured'];
        }

        return $this->request('GET', '/emails/' . $emailId);
    }

    /**
     * Verify the API key works by listing domains.
     */
    public function testConnection(): array {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'Resend API Key is not configured. Edit config.php on Hostinger.'];
        }

        $result = $this->request('GET', '/domains');
        if ($result['success']) {
            $domains = $result['data'] ?? [];
            return [
                'success' => true,
                'domains' => $domains,
                'domain_count' => count($domains),
            ];
        }

        return $result;
    }
}
