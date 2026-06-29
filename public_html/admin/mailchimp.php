<?php
/**
 * IGNYTE Consulting - Mailchimp API Integration
 * 
 * Handles syncing CRM clients to a Mailchimp audience.
 * Uses Mailchimp Marketing API v3 via cURL (no external dependencies).
 */

require_once __DIR__ . '/config.php';

class MailchimpAPI {
    private $apiKey;
    private $audienceId;
    private $dataCenter;
    private $baseUrl;

    public function __construct() {
        $this->apiKey = MAILCHIMP_API_KEY;
        $this->audienceId = MAILCHIMP_AUDIENCE_ID;

        if ($this->apiKey) {
            $parts = explode('-', $this->apiKey);
            $this->dataCenter = end($parts);
            $this->baseUrl = "https://{$this->dataCenter}.api.mailchimp.com/3.0";
        }
    }

    public function isConfigured(): bool {
        return !empty($this->apiKey) && !empty($this->audienceId);
    }

    private function request(string $method, string $endpoint, array $data = null): array {
        $url = $this->baseUrl . $endpoint;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_USERPWD => "anystring:{$this->apiKey}",
        ]);

        if ($method === 'PUT' || $method === 'POST' || $method === 'PATCH') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if ($data !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
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
     * Add or update a single subscriber using PUT (upsert).
     * Mailchimp uses MD5 hash of lowercase email as subscriber ID.
     */
    public function syncClient(array $client): array {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'Mailchimp not configured'];
        }

        $email = strtolower(trim($client['email']));
        if (!$email) {
            return ['success' => false, 'error' => 'No email address'];
        }

        $subscriberHash = md5($email);
        $nameParts = explode(' ', $client['full_name'], 2);

        $data = [
            'email_address' => $email,
            'status_if_new' => 'subscribed',
            'merge_fields' => [
                'FNAME' => $nameParts[0] ?? '',
                'LNAME' => $nameParts[1] ?? '',
                'PHONE' => $client['phone'] ?? '',
                'COMPANY' => $client['company_name'] ?? '',
            ],
        ];

        // Only set status to subscribed for active clients
        if (($client['status'] ?? 'active') === 'active') {
            $data['status_if_new'] = 'subscribed';
        } else {
            $data['status_if_new'] = 'unsubscribed';
        }

        $result = $this->request('PUT', "/lists/{$this->audienceId}/members/{$subscriberHash}", $data);

        return $result;
    }

    /**
     * Sync all active CRM clients to Mailchimp in bulk.
     * Returns counts of synced, failed, and skipped.
     */
    public function syncAllClients(PDO $pdo): array {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'Mailchimp not configured'];
        }

        $clients = $pdo->query('SELECT * FROM crm_clients ORDER BY full_name ASC')->fetchAll();

        $synced = 0;
        $failed = 0;
        $skipped = 0;
        $errors = [];

        foreach ($clients as $client) {
            if (empty($client['email'])) {
                $skipped++;
                continue;
            }

            $result = $this->syncClient($client);
            if ($result['success']) {
                // Update last_synced timestamp
                $stmt = $pdo->prepare('UPDATE crm_clients SET mailchimp_synced = NOW() WHERE id = ?');
                $stmt->execute([$client['id']]);
                $synced++;
            } else {
                $failed++;
                $errors[] = $client['full_name'] . ': ' . ($result['detail'] ?? $result['error'] ?? 'Unknown error');
            }
        }

        return [
            'success' => true,
            'synced' => $synced,
            'failed' => $failed,
            'skipped' => $skipped,
            'errors' => $errors,
            'total' => count($clients),
        ];
    }

    /**
     * Get the subscriber status from Mailchimp for a given email.
     */
    public function getSubscriberStatus(string $email): array {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'Mailchimp not configured'];
        }

        $subscriberHash = md5(strtolower(trim($email)));
        return $this->request('GET', "/lists/{$this->audienceId}/members/{$subscriberHash}");
    }

    /**
     * Test the API connection and return audience info.
     */
    public function testConnection(): array {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'Mailchimp API Key and Audience ID are not configured. Edit config.php on Hostinger.'];
        }

        $result = $this->request('GET', "/lists/{$this->audienceId}");
        if ($result['success']) {
            return [
                'success' => true,
                'audience_name' => $result['name'] ?? 'Unknown',
                'member_count' => $result['stats']['member_count'] ?? 0,
            ];
        }

        return $result;
    }

    /**
     * Remove a subscriber from the audience (archive, not permanent delete).
     */
    public function removeClient(string $email): array {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'Mailchimp not configured'];
        }

        $subscriberHash = md5(strtolower(trim($email)));
        return $this->request('DELETE', "/lists/{$this->audienceId}/members/{$subscriberHash}");
    }

    // ========================================================
    // Campaign (Newsletter) Methods
    // ========================================================

    /**
     * Create a new campaign (newsletter).
     */
    public function createCampaign(string $subject, string $previewText, string $fromName, string $replyTo): array {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'Mailchimp not configured'];
        }

        $data = [
            'type' => 'regular',
            'recipients' => [
                'list_id' => $this->audienceId,
            ],
            'settings' => [
                'subject_line' => $subject,
                'preview_text' => $previewText,
                'from_name' => $fromName,
                'reply_to' => $replyTo,
            ],
        ];

        return $this->request('POST', '/campaigns', $data);
    }

    /**
     * Set the HTML content for a campaign.
     */
    public function setCampaignContent(string $campaignId, string $html): array {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'Mailchimp not configured'];
        }

        return $this->request('PUT', "/campaigns/{$campaignId}/content", [
            'html' => $html,
        ]);
    }

    /**
     * Send a campaign immediately.
     */
    public function sendCampaign(string $campaignId): array {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'Mailchimp not configured'];
        }

        return $this->request('POST', "/campaigns/{$campaignId}/actions/send");
    }

    /**
     * Schedule a campaign for later.
     */
    public function scheduleCampaign(string $campaignId, string $scheduleTime): array {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'Mailchimp not configured'];
        }

        return $this->request('POST', "/campaigns/{$campaignId}/actions/schedule", [
            'schedule_time' => $scheduleTime,
        ]);
    }

    /**
     * Send a test email for a campaign.
     */
    public function sendTestEmail(string $campaignId, array $testEmails): array {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'Mailchimp not configured'];
        }

        return $this->request('POST', "/campaigns/{$campaignId}/actions/test", [
            'test_emails' => $testEmails,
            'send_type' => 'html',
        ]);
    }

    /**
     * Get a list of campaigns with stats.
     */
    public function getCampaigns(int $count = 20, int $offset = 0): array {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'Mailchimp not configured'];
        }

        return $this->request('GET', "/campaigns?count={$count}&offset={$offset}&sort_field=send_time&sort_dir=DESC");
    }

    /**
     * Get a single campaign's details.
     */
    public function getCampaign(string $campaignId): array {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'Mailchimp not configured'];
        }

        return $this->request('GET', "/campaigns/{$campaignId}");
    }

    /**
     * Get campaign report (opens, clicks, etc).
     */
    public function getCampaignReport(string $campaignId): array {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'Mailchimp not configured'];
        }

        return $this->request('GET', "/reports/{$campaignId}");
    }

    /**
     * Delete a campaign (only if not sent).
     */
    public function deleteCampaign(string $campaignId): array {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'Mailchimp not configured'];
        }

        return $this->request('DELETE', "/campaigns/{$campaignId}");
    }

    /**
     * Get audience segments/tags for targeting.
     */
    public function getSegments(): array {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'Mailchimp not configured'];
        }

        return $this->request('GET', "/lists/{$this->audienceId}/segments");
    }

    /**
     * Get audience member count.
     */
    public function getAudienceStats(): array {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'Mailchimp not configured'];
        }

        $result = $this->request('GET', "/lists/{$this->audienceId}");
        if ($result['success']) {
            return [
                'success' => true,
                'name' => $result['name'] ?? 'Unknown',
                'member_count' => $result['stats']['member_count'] ?? 0,
                'unsubscribe_count' => $result['stats']['unsubscribe_count'] ?? 0,
                'open_rate' => $result['stats']['open_rate'] ?? 0,
                'click_rate' => $result['stats']['click_rate'] ?? 0,
                'campaign_count' => $result['stats']['campaign_count'] ?? 0,
            ];
        }

        return $result;
    }
}
