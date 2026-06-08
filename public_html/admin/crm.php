<?php
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mailchimp.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$pdo = getDB();
$adminName = htmlspecialchars($_SESSION['admin_name']);
$mailchimp = new MailchimpAPI();
$mcConfigured = $mailchimp->isConfigured();

// Handle add/edit CRM client
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crm_action'])) {
    $action = $_POST['crm_action'];
    $fullName = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $company = trim($_POST['company_name'] ?? '');
    $industry = trim($_POST['industry'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $clientStatus = $_POST['client_status'] ?? 'active';

    if ($fullName && $email) {
        if ($action === 'add') {
            $stmt = $pdo->prepare('INSERT INTO crm_clients (full_name, email, phone, company_name, industry, address, notes, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$fullName, $email, $phone, $company, $industry, $address, $notes, $clientStatus]);
            $newId = $pdo->lastInsertId();
            // Auto-sync to Mailchimp
            if ($mcConfigured) {
                $syncResult = $mailchimp->syncClient(['full_name' => $fullName, 'email' => $email, 'phone' => $phone, 'company_name' => $company, 'status' => $clientStatus]);
                if ($syncResult['success']) {
                    $pdo->prepare('UPDATE crm_clients SET mailchimp_synced = NOW() WHERE id = ?')->execute([$newId]);
                }
            }
            header('Location: crm.php?added=1');
            exit;
        } elseif ($action === 'update' && isset($_POST['client_id'])) {
            $stmt = $pdo->prepare('UPDATE crm_clients SET full_name=?, email=?, phone=?, company_name=?, industry=?, address=?, notes=?, status=? WHERE id=?');
            $stmt->execute([$fullName, $email, $phone, $company, $industry, $address, $notes, $clientStatus, $_POST['client_id']]);
            // Auto-sync to Mailchimp
            if ($mcConfigured) {
                $syncResult = $mailchimp->syncClient(['full_name' => $fullName, 'email' => $email, 'phone' => $phone, 'company_name' => $company, 'status' => $clientStatus]);
                if ($syncResult['success']) {
                    $pdo->prepare('UPDATE crm_clients SET mailchimp_synced = NOW() WHERE id = ?')->execute([$_POST['client_id']]);
                }
            }
            header('Location: crm.php?updated=1');
            exit;
        }
    }
}

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_client'])) {
    // Remove from Mailchimp too
    if ($mcConfigured) {
        $delClient = $pdo->prepare('SELECT email FROM crm_clients WHERE id = ?');
        $delClient->execute([$_POST['delete_client']]);
        $delEmail = $delClient->fetchColumn();
        if ($delEmail) $mailchimp->removeClient($delEmail);
    }
    $pdo->prepare('DELETE FROM crm_clients WHERE id = ?')->execute([$_POST['delete_client']]);
    header('Location: crm.php?deleted=1');
    exit;
}

// Handle promote contact to client
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['promote_to_client'])) {
    $contactId = (int)$_POST['promote_to_client'];
    $contact = $pdo->prepare('SELECT * FROM crm_clients WHERE id = ?');
    $contact->execute([$contactId]);
    $c = $contact->fetch();
    if ($c) {
        // Check if a client with this company already exists
        $existingClient = null;
        if ($c['company_name']) {
            $check = $pdo->prepare('SELECT id FROM crm_clients WHERE company_name = ? AND is_client = 1 AND id != ? LIMIT 1');
            $check->execute([$c['company_name'], $contactId]);
            $existingClient = $check->fetch();
        }
        if ($existingClient) {
            // Link contact to existing client
            $pdo->prepare('UPDATE crm_clients SET linked_client_id = ? WHERE id = ?')->execute([$existingClient['id'], $contactId]);
            header('Location: crm.php?linked=1');
        } else {
            // Promote this contact record to a client
            $pdo->prepare('UPDATE crm_clients SET is_client = 1 WHERE id = ?')->execute([$contactId]);
            header('Location: crm.php?promoted=1');
        }
        exit;
    }
}

// Handle link contact to existing client
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['link_to_client'])) {
    $contactId = (int)$_POST['link_contact_id'];
    $clientId = (int)$_POST['link_to_client'];
    if ($contactId && $clientId) {
        $pdo->prepare('UPDATE crm_clients SET linked_client_id = ? WHERE id = ?')->execute([$clientId, $contactId]);
        header('Location: crm.php?linked=1');
        exit;
    }
}

// Handle unlink contact from client
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unlink_contact'])) {
    $pdo->prepare('UPDATE crm_clients SET linked_client_id = NULL WHERE id = ?')->execute([$_POST['unlink_contact']]);
    header('Location: crm.php?unlinked=1');
    exit;
}

// Handle bulk Mailchimp sync
$syncMessage = '';
$syncError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mailchimp_bulk_sync'])) {
    if ($mcConfigured) {
        $result = $mailchimp->syncAllClients($pdo);
        if ($result['success']) {
            $syncMessage = "Mailchimp sync complete: {$result['synced']} synced, {$result['failed']} failed, {$result['skipped']} skipped.";
            if (!empty($result['errors'])) {
                $syncError = implode('; ', array_slice($result['errors'], 0, 5));
            }
        } else {
            $syncError = $result['error'] ?? 'Sync failed';
        }
        // Re-fetch clients to show updated sync times
        $allClients = $pdo->query('SELECT * FROM crm_clients ORDER BY full_name ASC')->fetchAll();
        $activeClients = array_filter($allClients, function($c) { return $c['status'] === 'active'; });
    } else {
        $syncError = 'Mailchimp is not configured. Edit config.php on Hostinger with your API Key and Audience ID.';
    }
}

// Handle single client sync
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sync_client_id'])) {
    if ($mcConfigured) {
        $stmt = $pdo->prepare('SELECT * FROM crm_clients WHERE id = ?');
        $stmt->execute([$_POST['sync_client_id']]);
        $syncClient = $stmt->fetch();
        if ($syncClient) {
            $result = $mailchimp->syncClient($syncClient);
            if ($result['success']) {
                $pdo->prepare('UPDATE crm_clients SET mailchimp_synced = NOW() WHERE id = ?')->execute([$syncClient['id']]);
                $syncMessage = htmlspecialchars($syncClient['full_name']) . ' synced to Mailchimp.';
            } else {
                $syncError = 'Failed to sync ' . htmlspecialchars($syncClient['full_name']) . ': ' . ($result['detail'] ?? $result['error'] ?? 'Unknown error');
            }
        }
    }
}

// Handle CSV import
$importMessage = '';
$importError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_csv'])) {
    if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
        $file = fopen($_FILES['csv_file']['tmp_name'], 'r');
        $header = fgetcsv($file);
        if (!$header) {
            $importError = 'CSV file is empty or invalid.';
        } else {
            // Normalize headers (lowercase, trim)
            $header = array_map(function($h) { return strtolower(trim($h)); }, $header);

            // Map common CSV column names to our DB fields
            // Includes Google Contacts, Outlook, Mailchimp, and generic formats
            $colMap = [
                'full_name' => ['full_name', 'full name', 'name', 'contact name', 'client name'],
                'email' => ['email', 'email address', 'e-mail', 'mail', 'e-mail 1 - value', 'e-mail 2 - value', 'email 1 - value'],
                'phone' => ['phone', 'phone number', 'telephone', 'mobile', 'cell', 'phone 1 - value', 'phone 2 - value'],
                'company_name' => ['company', 'company_name', 'company name', 'organization', 'org', 'organization name'],
                'industry' => ['industry', 'sector', 'field', 'organization department'],
                'address' => ['address', 'location', 'city', 'full address'],
                'notes' => ['notes', 'note', 'comments', 'description'],
                'first_name' => ['first name', 'first_name', 'firstname', 'given name', 'phonetic first name'],
                'last_name' => ['last name', 'last_name', 'lastname', 'surname', 'family name', 'phonetic last name'],
            ];

            $indexes = [];
            foreach ($colMap as $field => $aliases) {
                foreach ($aliases as $alias) {
                    $idx = array_search($alias, $header);
                    if ($idx !== false) {
                        $indexes[$field] = $idx;
                        break;
                    }
                }
            }

            if (!isset($indexes['email']) && !isset($indexes['full_name'])) {
                $importError = 'CSV must have at least an "Email" or "Name" column. Found columns: ' . implode(', ', $header);
            } else {
                $imported = 0;
                $skipped = 0;
                $cleaned = 0;
                $defaultStatus = $_POST['import_status'] ?? 'active';
                $seenEmails = []; // track duplicates within this import batch

                $stmt = $pdo->prepare('INSERT INTO crm_clients (full_name, email, phone, company_name, industry, address, notes, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');

                while (($row = fgetcsv($file)) !== false) {
                    if (empty(array_filter($row))) continue; // skip empty rows

                    // Build full name from first+last if no full_name column
                    $fullName = '';
                    if (isset($indexes['full_name'])) {
                        $fullName = trim($row[$indexes['full_name']] ?? '');
                    }
                    if (!$fullName && (isset($indexes['first_name']) || isset($indexes['last_name']))) {
                        $first = trim($row[$indexes['first_name'] ?? -1] ?? '');
                        $last = trim($row[$indexes['last_name'] ?? -1] ?? '');
                        $fullName = trim("$first $last");
                    }

                    $email = strtolower(trim($row[$indexes['email'] ?? -1] ?? ''));

                    // Validate name
                    if ($fullName && !isLegitimateName($fullName)) {
                        $fullName = '';
                    }

                    // Validate email
                    if ($email && !isLegitimateEmail($email)) {
                        $cleaned++;
                        $email = '';
                    }

                    if (!$fullName && !$email) {
                        $skipped++;
                        continue;
                    }
                    if (!$fullName) $fullName = explode('@', $email)[0];

                    // Skip duplicates within this batch
                    if ($email) {
                        $emailLower = strtolower($email);
                        if (isset($seenEmails[$emailLower])) {
                            $cleaned++;
                            continue;
                        }
                        $seenEmails[$emailLower] = true;
                    }

                    // Skip duplicates already in database
                    if ($email) {
                        $check = $pdo->prepare('SELECT id FROM crm_clients WHERE LOWER(email) = ?');
                        $check->execute([strtolower($email)]);
                        if ($check->fetch()) {
                            $skipped++;
                            continue;
                        }
                    }

                    $phone = trim($row[$indexes['phone'] ?? -1] ?? '');
                    if ($phone && !isLegitimatePhone($phone)) $phone = '';

                    $company = trim($row[$indexes['company_name'] ?? -1] ?? '');
                    $industry = trim($row[$indexes['industry'] ?? -1] ?? '');
                    $address = trim($row[$indexes['address'] ?? -1] ?? '');
                    $notes = trim($row[$indexes['notes'] ?? -1] ?? '');

                    $stmt->execute([$fullName, $email, $phone, $company, $industry, $address, $notes, $defaultStatus]);
                    $imported++;
                }

                $importMessage = "Imported $imported client(s).";
                if ($skipped > 0) $importMessage .= " Skipped $skipped duplicate(s).";
                if ($cleaned > 0) $importMessage .= " Cleaned $cleaned invalid/junk entries.";
            }
        }
        fclose($file);
    } else {
        $importError = 'Please select a CSV file to import.';
    }
    // Re-fetch clients after import
    $allClients = $pdo->query('SELECT * FROM crm_clients ORDER BY full_name ASC')->fetchAll();
    $activeClients = array_filter($allClients, function($c) { return $c['status'] === 'active'; });
}

// Handle smart paste import
$pasteMessage = '';
$pasteError = '';
$parsedContacts = [];
$pastePreview = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['smart_paste'])) {
    $rawText = trim($_POST['paste_data'] ?? '');
    $pasteAction = $_POST['paste_action'] ?? 'preview';

    if (!$rawText && $pasteAction === 'preview') {
        $pasteError = 'Please paste some contact data.';
    } else {
        // Parse contacts from raw text
        if ($pasteAction === 'preview') {
            $rawParsed = parseContactsFromText($rawText);
            $cleanedCount = 0;

            // Auto-clean: validate emails, names, phones + deduplicate
            $seenEmails = [];
            $parsedContacts = [];
            foreach ($rawParsed as $c) {
                $email = strtolower(trim($c['email'] ?? ''));
                $name = trim($c['name'] ?? '');
                $phone = trim($c['phone'] ?? '');

                // Validate email
                if ($email && !isLegitimateEmail($email)) {
                    $email = '';
                    $cleanedCount++;
                }
                // Validate name
                if ($name && !isLegitimateName($name)) $name = '';
                // Validate phone
                if ($phone && !isLegitimatePhone($phone)) $phone = '';

                // Must have at least a name or email
                if (!$name && !$email) { $cleanedCount++; continue; }
                if (!$name && $email) $name = explode('@', $email)[0];

                // Deduplicate within batch
                if ($email) {
                    if (isset($seenEmails[$email])) { $cleanedCount++; continue; }
                    $seenEmails[$email] = true;
                }

                $c['email'] = $email;
                $c['name'] = $name;
                $c['phone'] = $phone;
                $parsedContacts[] = $c;
            }

            if (empty($parsedContacts)) {
                $pasteError = 'Could not detect any valid contacts. Try pasting data with real emails, phone numbers, or names.';
                if ($cleanedCount > 0) $pasteError .= " ($cleanedCount entries were filtered as invalid/duplicate.)";
            } else {
                $pastePreview = true;
                if ($cleanedCount > 0) {
                    $pasteError = "Auto-cleaned: $cleanedCount invalid/duplicate entries were removed.";
                }
            }
        } elseif ($pasteAction === 'confirm') {
            // Import confirmed contacts from hidden JSON
            $contacts = json_decode($_POST['parsed_contacts'] ?? '[]', true);
            $defaultStatus = $_POST['paste_status'] ?? 'prospect';
            $imported = 0;
            $skipped = 0;
            $cleaned = 0;
            $seenEmails = [];

            $stmt = $pdo->prepare('INSERT INTO crm_clients (full_name, email, phone, company_name, industry, address, notes, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');

            foreach ($contacts as $contact) {
                $email = strtolower(trim($contact['email'] ?? ''));
                $fullName = trim($contact['name'] ?? '');

                // Re-validate on confirm (defense in depth)
                if ($email && !isLegitimateEmail($email)) { $cleaned++; $email = ''; }
                if ($fullName && !isLegitimateName($fullName)) $fullName = '';

                if (!$fullName && !$email) { $skipped++; continue; }
                if (!$fullName && $email) $fullName = explode('@', $email)[0];

                // Skip duplicates within batch
                if ($email) {
                    if (isset($seenEmails[$email])) { $cleaned++; continue; }
                    $seenEmails[$email] = true;
                }

                // Skip duplicates already in database (case-insensitive)
                if ($email) {
                    $check = $pdo->prepare('SELECT id FROM crm_clients WHERE LOWER(email) = ?');
                    $check->execute([strtolower($email)]);
                    if ($check->fetch()) { $skipped++; continue; }
                }

                $phone = trim($contact['phone'] ?? '');
                if ($phone && !isLegitimatePhone($phone)) $phone = '';

                $stmt->execute([
                    $fullName, $email, $phone,
                    trim($contact['company'] ?? ''),
                    '', '', '', $defaultStatus
                ]);
                $imported++;
            }

            $pasteMessage = "Imported $imported contact(s).";
            if ($skipped > 0) $pasteMessage .= " Skipped $skipped duplicate(s).";
            if ($cleaned > 0) $pasteMessage .= " Cleaned $cleaned invalid entries.";

            // Re-fetch
            $allClients = $pdo->query('SELECT * FROM crm_clients ORDER BY full_name ASC')->fetchAll();
            $activeClients = array_filter($allClients, function($c) { return $c['status'] === 'active'; });
        }
    }
}

/**
 * Smart contact parser — extracts contacts from unstructured text.
 * Handles: pasted spreadsheet rows, vCard data, email signatures,
 * comma/newline-separated lists, business card text, LinkedIn exports, etc.
 */
function parseContactsFromText(string $text): array {
    $contacts = [];
    $lines = preg_split('/\r?\n/', $text);

    // Detect if it looks like tab/comma-separated tabular data
    $firstLine = trim($lines[0] ?? '');
    $isTabular = (substr_count($firstLine, "\t") >= 2) || (substr_count($firstLine, ',') >= 2 && preg_match('/(name|email|phone|company)/i', $firstLine));

    if ($isTabular) {
        // Parse as tabular data (spreadsheet paste or CSV-like)
        $delimiter = substr_count($firstLine, "\t") >= 2 ? "\t" : ",";
        $headers = array_map(function($h) { return strtolower(trim($h, " \t\n\r\0\x0B\"")); }, explode($delimiter, $firstLine));

        // Map headers to fields
        $emailIdx = findHeaderIndex($headers, ['email', 'email address', 'e-mail', 'mail', 'e-mail 1 - value', 'email 1 - value']);
        $nameIdx = findHeaderIndex($headers, ['name', 'full name', 'full_name', 'contact name', 'client name']);
        $firstIdx = findHeaderIndex($headers, ['first name', 'first_name', 'firstname', 'given name']);
        $lastIdx = findHeaderIndex($headers, ['last name', 'last_name', 'lastname', 'surname', 'family name']);
        $phoneIdx = findHeaderIndex($headers, ['phone', 'phone number', 'telephone', 'mobile', 'cell', 'phone 1 - value']);
        $companyIdx = findHeaderIndex($headers, ['company', 'company name', 'organization', 'organization name', 'org']);

        $hasAnyHeader = ($emailIdx !== false || $nameIdx !== false || $firstIdx !== false);

        if ($hasAnyHeader) {
            for ($i = 1; $i < count($lines); $i++) {
                $line = trim($lines[$i]);
                if (empty($line)) continue;
                $cols = explode($delimiter, $line);
                $cols = array_map(function($c) { return trim($c, " \t\n\r\0\x0B\""); }, $cols);

                $name = '';
                if ($nameIdx !== false) $name = $cols[$nameIdx] ?? '';
                if (!$name && ($firstIdx !== false || $lastIdx !== false)) {
                    $first = ($firstIdx !== false) ? ($cols[$firstIdx] ?? '') : '';
                    $last = ($lastIdx !== false) ? ($cols[$lastIdx] ?? '') : '';
                    $name = trim("$first $last");
                }

                $email = ($emailIdx !== false) ? ($cols[$emailIdx] ?? '') : '';
                $phone = ($phoneIdx !== false) ? ($cols[$phoneIdx] ?? '') : '';
                $company = ($companyIdx !== false) ? ($cols[$companyIdx] ?? '') : '';

                if ($name || $email) {
                    $contacts[] = ['name' => $name, 'email' => $email, 'phone' => $phone, 'company' => $company];
                }
            }
            return $contacts;
        }
    }

    // Non-tabular: scan text for contact patterns
    // Strategy: group lines into contact blocks (separated by blank lines or detect per-line contacts)

    // First, extract all emails from the entire text
    preg_match_all('/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/', $text, $emailMatches);
    $allEmails = array_unique($emailMatches[0]);

    // Extract all phone numbers
    preg_match_all('/(?:\+?1[-.\s]?)?(?:\(?\d{3}\)?[-.\s]?)?\d{3}[-.\s]?\d{4}/', $text, $phoneMatches);
    $allPhones = array_unique($phoneMatches[0]);

    if (!empty($allEmails)) {
        // Try to associate names with emails
        foreach ($allEmails as $email) {
            $contact = ['name' => '', 'email' => $email, 'phone' => '', 'company' => ''];

            // Look for a name near this email (on the same line or the line before)
            foreach ($lines as $li => $line) {
                if (stripos($line, $email) !== false) {
                    // Check if there's a name on the same line before the email
                    $beforeEmail = trim(preg_replace('/[<(]?' . preg_quote($email, '/') . '[>)]?/', '', $line));
                    $beforeEmail = trim($beforeEmail, " \t,-;:|");
                    if ($beforeEmail && !preg_match('/^(email|e-mail|mail|to|from|cc|bcc)\s*:?\s*$/i', $beforeEmail)) {
                        // Check it looks like a name (2+ chars, not all digits)
                        if (strlen($beforeEmail) >= 2 && !preg_match('/^\d+$/', $beforeEmail)) {
                            $contact['name'] = $beforeEmail;
                        }
                    }

                    // Check line above for a name
                    if (!$contact['name'] && $li > 0) {
                        $prevLine = trim($lines[$li - 1]);
                        if ($prevLine && strlen($prevLine) < 80 && !preg_match('/[@\d{4,}]/', $prevLine)) {
                            $contact['name'] = $prevLine;
                        }
                    }

                    // Try to find a phone on nearby lines
                    for ($j = max(0, $li - 2); $j <= min(count($lines) - 1, $li + 2); $j++) {
                        if (preg_match('/(?:\+?1[-.\s]?)?(?:\(?\d{3}\)?[-.\s]?)?\d{3}[-.\s]?\d{4}/', $lines[$j], $pm)) {
                            $contact['phone'] = $pm[0];
                            break;
                        }
                    }

                    // Try to extract company from email domain
                    $domain = substr($email, strpos($email, '@') + 1);
                    $domainParts = explode('.', $domain);
                    if (count($domainParts) >= 2 && !in_array($domainParts[0], ['gmail', 'yahoo', 'hotmail', 'outlook', 'aol', 'icloud', 'mail', 'live', 'msn', 'proton', 'protonmail'])) {
                        $contact['company'] = ucfirst($domainParts[0]);
                    }

                    break;
                }
            }

            if (!$contact['name']) {
                $contact['name'] = explode('@', $email)[0];
            }

            $contacts[] = $contact;
        }
    } elseif (!empty($allPhones)) {
        // No emails found, try phone-based contacts
        foreach ($allPhones as $phone) {
            $contact = ['name' => '', 'email' => '', 'phone' => $phone, 'company' => ''];
            foreach ($lines as $li => $line) {
                if (strpos($line, $phone) !== false) {
                    $beforePhone = trim(preg_replace('/' . preg_quote($phone, '/') . '/', '', $line));
                    $beforePhone = trim($beforePhone, " \t,-;:|");
                    if ($beforePhone && strlen($beforePhone) >= 2) {
                        $contact['name'] = $beforePhone;
                    }
                    if (!$contact['name'] && $li > 0) {
                        $prevLine = trim($lines[$li - 1]);
                        if ($prevLine && strlen($prevLine) < 80) $contact['name'] = $prevLine;
                    }
                    break;
                }
            }
            $contacts[] = $contact;
        }
    } else {
        // No emails or phones — try line-by-line for "Name - Company" or similar patterns
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || strlen($line) < 3) continue;
            // Skip obvious headers/labels
            if (preg_match('/^(name|email|phone|company|contact|#|---)/i', $line)) continue;

            $contact = ['name' => $line, 'email' => '', 'phone' => '', 'company' => ''];

            // Check for "Name - Company" or "Name | Company" or "Name, Company" patterns
            if (preg_match('/^(.+?)\s*[-|]\s*(.+)$/', $line, $m)) {
                $contact['name'] = trim($m[1]);
                $contact['company'] = trim($m[2]);
            } elseif (preg_match('/^(.+?),\s*(.+)$/', $line, $m) && !preg_match('/@/', $line)) {
                $contact['name'] = trim($m[1]);
                $contact['company'] = trim($m[2]);
            }

            if (strlen($contact['name']) >= 2 && strlen($contact['name']) <= 100) {
                $contacts[] = $contact;
            }
        }
    }

    return $contacts;
}

function findHeaderIndex(array $headers, array $aliases) {
    foreach ($aliases as $alias) {
        $idx = array_search($alias, $headers);
        if ($idx !== false) return $idx;
    }
    return false;
}

/**
 * Validate an email address — checks format + filters junk/disposable/placeholder emails.
 * Returns true if the email looks legitimate.
 */
function isLegitimateEmail(string $email): bool {
    $email = strtolower(trim($email));
    if (!$email) return false;

    // Basic format check
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return false;

    // Must have a real TLD (at least 2 chars after last dot)
    $parts = explode('.', $email);
    if (strlen(end($parts)) < 2) return false;

    // Reject common placeholder/test patterns
    $junkPatterns = [
        '/^(test|example|sample|demo|fake|none|noemail|no[-_.]?email|no[-_.]?reply|noreply|donotreply|null|void|xxx|yyy|zzz|asdf|qwerty|temp|tmp|delete|removed)@/i',
        '/^[a-z]@/',  // single char before @
        '/@(example|test|fake|invalid|placeholder|localhost|none)\./i',
        '/@.*\.(test|example|invalid|localhost)$/i',
        '/^[\d]+@/',  // all digits before @
    ];
    foreach ($junkPatterns as $pattern) {
        if (preg_match($pattern, $email)) return false;
    }

    // Reject disposable/throwaway email domains
    $disposableDomains = [
        'mailinator.com', 'guerrillamail.com', 'tempmail.com', 'throwaway.email',
        'yopmail.com', 'sharklasers.com', 'guerrillamailblock.com', 'grr.la',
        'dispostable.com', 'trashmail.com', 'mailnesia.com', 'maildrop.cc',
        'temp-mail.org', 'fakeinbox.com', 'tempail.com', 'emailondeck.com',
        'getnada.com', 'mohmal.com', '10minutemail.com', 'minutemail.com',
        'tempr.email', 'discard.email', 'mailsac.com',
    ];
    $domain = substr($email, strpos($email, '@') + 1);
    if (in_array($domain, $disposableDomains)) return false;

    // Reject if local part is too short or too long
    $local = substr($email, 0, strpos($email, '@'));
    if (strlen($local) < 2 || strlen($local) > 64) return false;

    // Reject gibberish: local part has no vowels and is longer than 4 chars
    if (strlen($local) > 4 && !preg_match('/[aeiouy]/i', $local)) return false;

    return true;
}

/**
 * Clean a phone number — returns empty string if it doesn't look real.
 */
function isLegitimatePhone(string $phone): bool {
    $digits = preg_replace('/\D/', '', $phone);
    return strlen($digits) >= 7 && strlen($digits) <= 15;
}

/**
 * Check if a name looks legitimate (not gibberish or placeholder).
 */
function isLegitimateName(string $name): bool {
    $name = trim($name);
    if (strlen($name) < 2 || strlen($name) > 150) return false;
    // Reject all-digit names
    if (preg_match('/^\d+$/', $name)) return false;
    // Reject obvious placeholders
    if (preg_match('/^(test|example|sample|demo|n\/a|na|none|null|unknown|---|-|\.+)$/i', $name)) return false;
    return true;
}

// Handle CSV export for Mailchimp
if (isset($_GET['export']) && $_GET['export'] === 'mailchimp') {
    $clients = $pdo->query('SELECT full_name, email, phone, company_name, industry, address FROM crm_clients WHERE status = "active" ORDER BY full_name ASC')->fetchAll();
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="ignyte-clients-mailchimp-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Email Address', 'First Name', 'Last Name', 'Phone', 'Company', 'Industry', 'Address']);
    foreach ($clients as $c) {
        $nameParts = explode(' ', $c['full_name'], 2);
        $firstName = $nameParts[0];
        $lastName = $nameParts[1] ?? '';
        fputcsv($out, [$c['email'], $firstName, $lastName, $c['phone'], $c['company_name'], $c['industry'], $c['address']]);
    }
    fclose($out);
    exit;
}

// Fetch all CRM clients
$allClients = $pdo->query('SELECT * FROM crm_clients ORDER BY full_name ASC')->fetchAll();
$activeClients = array_filter($allClients, function($c) { return $c['status'] === 'active'; });

// Fetch all clients (is_client=1) for the "Link to Client" dropdown
$clientsList = array_filter($allClients, function($c) { return !empty($c['is_client']); });

// If editing
$editClient = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM crm_clients WHERE id = ?');
    $stmt->execute([$_GET['edit']]);
    $editClient = $stmt->fetch();
}

// Filter
$filterStatus = $_GET['status'] ?? 'all';
$displayClients = $allClients;
if ($filterStatus !== 'all') {
    $displayClients = array_filter($allClients, function($c) use ($filterStatus) { return $c['status'] === $filterStatus; });
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CRM - Contacts | IGNYTE Consulting</title>
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
        .form-group textarea { min-height: 100px; resize: vertical; line-height: 1.7; }

        .form-actions { display: flex; gap: 12px; margin-top: 8px; }
        .btn {
            padding: 10px 24px; border: none; border-radius: 8px;
            font-size: 0.9rem; font-weight: 700; font-family: 'DM Sans', sans-serif;
            cursor: pointer; transition: all 0.2s; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;
        }
        .btn-save { background: var(--flame-orange); color: white; box-shadow: 0 2px 12px rgba(238,90,36,0.3); }
        .btn-save:hover { background: var(--navy); }
        .btn-cancel { background: transparent; color: var(--slate); }
        .btn-cancel:hover { color: var(--navy); }
        .btn-export { background: var(--brand-blue); color: white; }
        .btn-export:hover { background: var(--navy); }
        .btn-small { padding: 6px 14px; font-size: 0.82rem; }

        .filter-bar { display: flex; gap: 8px; flex-wrap: wrap; }
        .filter-btn {
            padding: 6px 16px; border-radius: 50px; font-size: 0.82rem;
            font-weight: 600; border: 1.5px solid rgba(0,0,0,0.1);
            background: white; color: var(--slate); cursor: pointer;
            text-decoration: none; transition: all 0.2s;
        }
        .filter-btn:hover { border-color: var(--brand-blue); color: var(--brand-blue); }
        .filter-btn.active { background: var(--navy); color: white; border-color: var(--navy); }

        .clients-table { width: 100%; border-collapse: collapse; }
        .clients-table th {
            text-align: left; font-size: 0.78rem; text-transform: uppercase;
            letter-spacing: 0.06em; color: var(--slate); padding: 12px 14px;
            border-bottom: 2px solid var(--light-grey);
        }
        .clients-table td {
            padding: 14px; border-bottom: 1px solid rgba(0,0,0,0.04);
            font-size: 0.88rem; vertical-align: middle;
        }
        .clients-table tr:hover td { background: rgba(0,71,187,0.02); }
        .client-name { font-weight: 600; color: var(--navy); }
        .client-company { font-size: 0.8rem; color: var(--slate); }
        .badge {
            display: inline-block; padding: 3px 10px; border-radius: 50px;
            font-size: 0.72rem; font-weight: 700; text-transform: uppercase;
        }
        .badge-active { background: rgba(34,197,94,0.1); color: #16a34a; }
        .badge-inactive { background: rgba(220,38,38,0.08); color: #dc2626; }
        .badge-prospect { background: rgba(0,71,187,0.1); color: var(--brand-blue); }

        .action-btns { display: flex; gap: 6px; }
        .action-btns a, .action-btns button {
            padding: 5px 12px; border-radius: 6px; font-size: 0.8rem;
            font-weight: 600; text-decoration: none; cursor: pointer;
            border: none; font-family: 'DM Sans', sans-serif; transition: all 0.2s;
        }
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
            .clients-table th:nth-child(3), .clients-table td:nth-child(3),
            .clients-table th:nth-child(5), .clients-table td:nth-child(5) { display: none; }
        }
    </style>
</head>
<body>

<div class="topbar">
    <div class="topbar-left">
        <img src="../logo.png" alt="IGNYTE">
        <h2>CRM - Contacts</h2>
    </div>
    <div class="topbar-right">
        <span>Welcome, <?php echo $adminName; ?></span>
        <a href="dashboard.php">Blog</a>
        <a href="clients.php">Clients</a>
        <a href="crm.php" class="active-nav">Contacts</a>
        <a href="projects.php">Projects</a>
        <a href="tools.php">Tools/Licenses</a>
        <a href="../index.html">View Site</a>
        <a href="logout.php" class="logout-btn">Logout</a>
    </div>
</div>

<div class="dashboard">

    <?php if (isset($_GET['added'])): ?>
        <div class="alert alert-success">Contact added successfully!</div>
    <?php elseif (isset($_GET['updated'])): ?>
        <div class="alert alert-success">Contact updated successfully!</div>
    <?php elseif (isset($_GET['deleted'])): ?>
        <div class="alert alert-success">Contact deleted.</div>
    <?php elseif (isset($_GET['promoted'])): ?>
        <div class="alert alert-success">Contact promoted to Client! <a href="clients.php" style="color:inherit;font-weight:700;">View Clients &rarr;</a></div>
    <?php elseif (isset($_GET['linked'])): ?>
        <div class="alert alert-success">Contact linked to Client! <a href="clients.php" style="color:inherit;font-weight:700;">View Clients &rarr;</a></div>
    <?php elseif (isset($_GET['unlinked'])): ?>
        <div class="alert alert-success">Contact unlinked from Client.</div>
    <?php endif; ?>
    <?php if ($importMessage): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($importMessage); ?></div>
    <?php endif; ?>
    <?php if ($importError): ?>
        <div class="alert" style="background:rgba(220,38,38,0.08);color:#dc2626;border:1px solid rgba(220,38,38,0.2);"><?php echo htmlspecialchars($importError); ?></div>
    <?php endif; ?>
    <?php if ($syncMessage): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($syncMessage); ?></div>
    <?php endif; ?>
    <?php if ($syncError): ?>
        <div class="alert" style="background:rgba(220,38,38,0.08);color:#dc2626;border:1px solid rgba(220,38,38,0.2);"><?php echo htmlspecialchars($syncError); ?></div>
    <?php endif; ?>

    <!-- Stats -->
    <?php
    $totalCRM = count($allClients);
    $activeCRM = count($activeClients);
    $inactiveCRM = count(array_filter($allClients, function($c) { return $c['status'] === 'inactive'; }));
    $prospectCRM = count(array_filter($allClients, function($c) { return $c['status'] === 'prospect'; }));
    $syncedCount = count(array_filter($allClients, function($c) { return !empty($c['mailchimp_synced']); }));
    ?>
    <div class="stats-row">
        <div class="stat-box"><div class="num"><?php echo $totalCRM; ?></div><div class="label">Total Contacts</div></div>
        <div class="stat-box"><div class="num"><?php echo $activeCRM; ?></div><div class="label">Active</div></div>
        <div class="stat-box"><div class="num"><?php echo $prospectCRM; ?></div><div class="label">Prospects</div></div>
        <div class="stat-box">
            <div class="num" style="<?php echo $mcConfigured ? 'color:#16a34a' : 'color:var(--slate)'; ?>"><?php echo $syncedCount; ?>/<?php echo $totalCRM; ?></div>
            <div class="label">Synced to Mailchimp</div>
        </div>
    </div>

    <!-- Mailchimp Integration -->
    <div class="card" style="border-left:4px solid <?php echo $mcConfigured ? '#16a34a' : '#f59e0b'; ?>;">
        <div class="card-header">
            <h3>Mailchimp Integration</h3>
            <?php if ($mcConfigured): ?>
                <span class="badge badge-active">Connected</span>
            <?php else: ?>
                <span class="badge" style="background:rgba(245,158,11,0.1);color:#f59e0b;">Not Configured</span>
            <?php endif; ?>
        </div>
        <?php if ($mcConfigured): ?>
            <p style="font-size:0.88rem;color:var(--slate);margin-bottom:16px;">
                Contacts are auto-synced to Mailchimp when you add or edit them. Use "Sync All" to push everyone, or sync individually from the table below.
            </p>
            <div style="display:flex;gap:12px;flex-wrap:wrap;">
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="mailchimp_bulk_sync" value="1">
                    <button type="submit" class="btn btn-save" onclick="this.disabled=true;this.innerText='Syncing...';this.form.submit();">Sync All Contacts to Mailchimp</button>
                </form>
                <a href="crm.php?export=mailchimp" class="btn btn-export">Export CSV for Mailchimp</a>
            </div>
        <?php else: ?>
            <p style="font-size:0.88rem;color:var(--slate);margin-bottom:12px;">
                To enable direct Mailchimp sync, add your API Key and Audience ID to <code>config.php</code> on Hostinger:
            </p>
            <pre style="background:var(--light-grey);padding:14px;border-radius:8px;font-size:0.82rem;overflow-x:auto;">define('MAILCHIMP_API_KEY', 'your-api-key-here');
define('MAILCHIMP_AUDIENCE_ID', 'your-audience-id');</pre>
            <p style="font-size:0.82rem;color:var(--slate);margin-top:12px;">
                Get your API key: Mailchimp &rarr; Account &rarr; Extras &rarr; API Keys<br>
                Get your Audience ID: Mailchimp &rarr; Audience &rarr; Settings &rarr; Audience name and defaults
            </p>
            <a href="crm.php?export=mailchimp" class="btn btn-export" style="margin-top:12px;">Export CSV for Mailchimp (manual)</a>
        <?php endif; ?>
    </div>

    <!-- Add / Edit Client -->
    <div class="card">
        <h3><?php echo $editClient ? 'Edit Contact' : 'Add New Contact'; ?></h3>
        <form method="POST">
            <input type="hidden" name="crm_action" value="<?php echo $editClient ? 'update' : 'add'; ?>">
            <?php if ($editClient): ?>
                <input type="hidden" name="client_id" value="<?php echo $editClient['id']; ?>">
            <?php endif; ?>

            <div class="form-grid">
                <div class="form-group">
                    <label>Full Name *</label>
                    <input type="text" name="full_name" required value="<?php echo htmlspecialchars($editClient['full_name'] ?? ''); ?>" placeholder="John Smith">
                </div>
                <div class="form-group">
                    <label>Email *</label>
                    <input type="email" name="email" required value="<?php echo htmlspecialchars($editClient['email'] ?? ''); ?>" placeholder="john@company.com">
                </div>
                <div class="form-group">
                    <label>Phone</label>
                    <input type="text" name="phone" value="<?php echo htmlspecialchars($editClient['phone'] ?? ''); ?>" placeholder="+1 (555) 000-0000">
                </div>
                <div class="form-group">
                    <label>Company</label>
                    <input type="text" name="company_name" value="<?php echo htmlspecialchars($editClient['company_name'] ?? ''); ?>" placeholder="Acme Corp">
                </div>
                <div class="form-group">
                    <label>Industry</label>
                    <input type="text" name="industry" value="<?php echo htmlspecialchars($editClient['industry'] ?? ''); ?>" placeholder="Technology, Healthcare, Finance...">
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select name="client_status">
                        <option value="active" <?php echo (($editClient['status'] ?? 'active') === 'active') ? 'selected' : ''; ?>>Active</option>
                        <option value="prospect" <?php echo (($editClient['status'] ?? '') === 'prospect') ? 'selected' : ''; ?>>Prospect</option>
                        <option value="inactive" <?php echo (($editClient['status'] ?? '') === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>
                <div class="form-group full">
                    <label>Address</label>
                    <input type="text" name="address" value="<?php echo htmlspecialchars($editClient['address'] ?? ''); ?>" placeholder="123 Main St, City, State">
                </div>
                <div class="form-group full">
                    <label>Notes</label>
                    <textarea name="notes" placeholder="Internal notes about this client..."><?php echo htmlspecialchars($editClient['notes'] ?? ''); ?></textarea>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-save"><?php echo $editClient ? 'Update Contact' : 'Add Contact'; ?></button>
                <?php if ($editClient): ?>
                    <a href="crm.php" class="btn btn-cancel">Cancel</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Import Clients -->
    <div class="card">
        <h3>Import Contacts from CSV</h3>
        <p style="font-size:0.88rem;color:var(--slate);margin-bottom:16px;">
            Upload a CSV file to bulk-import contacts. Works with exports from <strong>Gmail Contacts</strong>, <strong>Mailchimp</strong>, <strong>Outlook</strong>, or any spreadsheet.
            The importer auto-detects columns like Email, Name, First Name, Last Name, Phone, Company, etc. Duplicate emails are skipped.
        </p>
        <details style="margin-bottom:12px;">
            <summary style="font-size:0.85rem;font-weight:600;color:var(--brand-blue);cursor:pointer;">How to export contacts from Gmail</summary>
            <ol style="font-size:0.85rem;color:var(--slate);padding-left:20px;margin-top:8px;line-height:1.8;">
                <li>Go to <a href="https://contacts.google.com" target="_blank" style="color:var(--brand-blue);">contacts.google.com</a></li>
                <li>Select the contacts you want to export (or select all)</li>
                <li>Click the <strong>Export</strong> icon (or Menu → Export)</li>
                <li>Choose <strong>"Google CSV"</strong> format</li>
                <li>Click <strong>Export</strong> and save the file</li>
                <li>Upload that file here</li>
            </ol>
        </details>
        <form method="POST" enctype="multipart/form-data" style="display:flex;gap:12px;align-items:end;flex-wrap:wrap;">
            <input type="hidden" name="import_csv" value="1">
            <div class="form-group" style="margin-bottom:0;flex:1;min-width:200px;">
                <label>CSV File</label>
                <input type="file" name="csv_file" accept=".csv,.txt" required style="padding:8px;">
            </div>
            <div class="form-group" style="margin-bottom:0;min-width:140px;">
                <label>Default Status</label>
                <select name="import_status">
                    <option value="active">Active</option>
                    <option value="prospect" selected>Prospect</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
            <button type="submit" class="btn btn-save" style="margin-bottom:0;">Import CSV</button>
        </form>
    </div>

    <!-- Smart Paste Import -->
    <div class="card">
        <h3>&#9889; Smart Paste Import</h3>
        <p style="font-size:0.88rem;color:var(--slate);margin-bottom:16px;">
            Paste contact data from <strong>any source</strong> — emails, spreadsheets, LinkedIn, business cards, CRM exports, or plain text.
            The AI parser will detect names, emails, phone numbers, and companies automatically.
        </p>

        <?php if ($pasteMessage): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($pasteMessage); ?></div>
        <?php endif; ?>
        <?php if ($pasteError): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($pasteError); ?></div>
        <?php endif; ?>

        <?php if ($pastePreview && !empty($parsedContacts)): ?>
            <!-- Preview parsed results -->
            <div style="background:var(--bg-card, #f8fafc);border:2px solid var(--brand-blue, #0047BB);border-radius:12px;padding:20px;margin-bottom:16px;">
                <h4 style="margin:0 0 12px;color:var(--brand-blue, #0047BB);">&#9989; Found <?php echo count($parsedContacts); ?> contact(s) — Review before importing:</h4>
                <table class="clients-table" style="font-size:0.85rem;">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Company</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($parsedContacts as $idx => $pc): ?>
                        <tr>
                            <td><?php echo $idx + 1; ?></td>
                            <td><?php echo htmlspecialchars($pc['name'] ?: '—'); ?></td>
                            <td><?php echo htmlspecialchars($pc['email'] ?: '—'); ?></td>
                            <td><?php echo htmlspecialchars($pc['phone'] ?: '—'); ?></td>
                            <td><?php echo htmlspecialchars($pc['company'] ?: '—'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <form method="POST" style="display:flex;gap:12px;align-items:center;margin-top:16px;">
                    <input type="hidden" name="smart_paste" value="1">
                    <input type="hidden" name="paste_action" value="confirm">
                    <input type="hidden" name="parsed_contacts" value="<?php echo htmlspecialchars(json_encode($parsedContacts)); ?>">
                    <div class="form-group" style="margin-bottom:0;min-width:140px;">
                        <label>Import as</label>
                        <select name="paste_status">
                            <option value="active">Active</option>
                            <option value="prospect" selected>Prospect</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-save">Confirm Import (<?php echo count($parsedContacts); ?>)</button>
                    <a href="crm.php" class="btn btn-cancel">Cancel</a>
                </form>
            </div>
        <?php else: ?>
            <form method="POST">
                <input type="hidden" name="smart_paste" value="1">
                <input type="hidden" name="paste_action" value="preview">
                <div class="form-group">
                    <label>Paste your contacts here</label>
                    <textarea name="paste_data" rows="8" placeholder="Paste anything — examples:

John Smith, john@acme.com, 555-123-4567, Acme Corp
Jane Doe jane.doe@widgets.io (212) 555-0199

Or paste spreadsheet rows, email threads, LinkedIn exports, vCard data...
The parser will figure it out." style="font-family:'DM Sans',monospace;font-size:0.88rem;line-height:1.6;resize:vertical;"><?php echo htmlspecialchars($_POST['paste_data'] ?? ''); ?></textarea>
                </div>
                <button type="submit" class="btn btn-save">&#9889; Parse &amp; Preview Contacts</button>
            </form>
        <?php endif; ?>

        <details style="margin-top:12px;">
            <summary style="font-size:0.85rem;font-weight:600;color:var(--brand-blue);cursor:pointer;">What formats are supported?</summary>
            <ul style="font-size:0.85rem;color:var(--slate);padding-left:20px;margin-top:8px;line-height:1.8;">
                <li><strong>Spreadsheet paste</strong> — copy rows from Excel/Google Sheets (tab-separated)</li>
                <li><strong>CSV text</strong> — comma-separated with headers</li>
                <li><strong>Email lists</strong> — lines with emails (names auto-detected nearby)</li>
                <li><strong>LinkedIn exports</strong> — paste exported connection data</li>
                <li><strong>Plain text</strong> — "Name - Company" or "Name, Company" per line</li>
                <li><strong>Email signatures</strong> — paste email footers with contact details</li>
                <li><strong>Any mix</strong> — the parser extracts whatever it can find</li>
            </ul>
        </details>
    </div>

    <!-- Client List -->
    <div class="card">
        <div class="card-header">
            <h3>All Contacts (<?php echo count($displayClients); ?>)</h3>
            <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
                <div class="filter-bar">
                    <a href="crm.php?status=all" class="filter-btn <?php echo $filterStatus === 'all' ? 'active' : ''; ?>">All</a>
                    <a href="crm.php?status=active" class="filter-btn <?php echo $filterStatus === 'active' ? 'active' : ''; ?>">Active</a>
                    <a href="crm.php?status=prospect" class="filter-btn <?php echo $filterStatus === 'prospect' ? 'active' : ''; ?>">Prospects</a>
                    <a href="crm.php?status=inactive" class="filter-btn <?php echo $filterStatus === 'inactive' ? 'active' : ''; ?>">Inactive</a>
                </div>
                <a href="crm.php?export=mailchimp" class="btn btn-export btn-small">Export for Mailchimp</a>
            </div>
        </div>

        <?php if (empty($displayClients)): ?>
            <div class="empty-state">
                <span>&#128101;</span>
                <p>No contacts found</p>
                <p style="font-size:0.9rem;">Add your first contact using the form above.</p>
            </div>
        <?php else: ?>
            <table class="clients-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Company</th>
                        <th>Status</th>
                        <?php if ($mcConfigured): ?><th>Mailchimp</th><?php endif; ?>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($displayClients as $c): ?>
                    <tr>
                        <td>
                            <div class="client-name"><?php echo htmlspecialchars($c['full_name']); ?></div>
                            <?php if ($c['industry']): ?>
                                <div class="client-company"><?php echo htmlspecialchars($c['industry']); ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($c['email']); ?></td>
                        <td><?php echo htmlspecialchars($c['phone'] ?: '-'); ?></td>
                        <td><?php echo htmlspecialchars($c['company_name'] ?: '-'); ?></td>
                        <td><span class="badge badge-<?php echo $c['status']; ?>"><?php echo ucfirst($c['status']); ?></span></td>
                        <?php if ($mcConfigured): ?>
                        <td>
                            <?php if (!empty($c['mailchimp_synced'])): ?>
                                <span style="color:#16a34a;font-size:0.8rem;font-weight:600;" title="Synced <?php echo $c['mailchimp_synced']; ?>">Synced</span>
                            <?php else: ?>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="sync_client_id" value="<?php echo $c['id']; ?>">
                                    <button type="submit" style="padding:3px 10px;border-radius:6px;font-size:0.75rem;font-weight:600;border:1px solid rgba(0,71,187,0.2);background:rgba(0,71,187,0.06);color:var(--brand-blue);cursor:pointer;">Sync</button>
                                </form>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                        <td>
                            <div class="action-btns">
                                <a href="crm.php?edit=<?php echo $c['id']; ?>" class="btn-edit">Edit</a>
                                <?php if (empty($c['is_client'])): ?>
                                    <?php if (!empty($c['linked_client_id'])): ?>
                                        <span style="font-size:0.75rem;color:var(--green);font-weight:600;padding:3px 8px;">Linked</span>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="unlink_contact" value="<?php echo $c['id']; ?>">
                                            <button type="submit" style="padding:3px 8px;border-radius:6px;font-size:0.72rem;font-weight:600;border:1px solid rgba(220,38,38,0.2);background:rgba(220,38,38,0.06);color:#dc2626;cursor:pointer;">Unlink</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="promote_to_client" value="<?php echo $c['id']; ?>">
                                            <button type="submit" style="padding:3px 10px;border-radius:6px;font-size:0.75rem;font-weight:600;border:1px solid rgba(34,197,94,0.3);background:rgba(34,197,94,0.08);color:#16a34a;cursor:pointer;" title="Promote to Client or link to existing client with same company">Promote</button>
                                        </form>
                                        <button onclick="openLinkModal(<?php echo $c['id']; ?>, '<?php echo htmlspecialchars(addslashes($c['full_name'])); ?>')" style="padding:3px 10px;border-radius:6px;font-size:0.75rem;font-weight:600;border:1px solid rgba(0,71,187,0.2);background:rgba(0,71,187,0.06);color:var(--brand-blue);cursor:pointer;" title="Link to an existing client">Link</button>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="font-size:0.75rem;color:var(--brand-blue);font-weight:600;padding:3px 8px;">Client</span>
                                <?php endif; ?>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this contact?');">
                                    <input type="hidden" name="delete_client" value="<?php echo $c['id']; ?>">
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

<!-- Link to Client Modal -->
<div id="linkModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:200;align-items:center;justify-content:center;">
    <div style="background:white;border-radius:16px;padding:32px;max-width:440px;width:90%;box-shadow:0 8px 32px rgba(0,0,0,0.2);">
        <h3 style="font-family:'Inter',sans-serif;font-size:1.1rem;margin-bottom:6px;color:var(--navy);">Link Contact to Client</h3>
        <p id="linkContactName" style="font-size:0.88rem;color:var(--slate);margin-bottom:20px;"></p>
        <form method="POST">
            <input type="hidden" name="link_contact_id" id="linkContactId">
            <div style="margin-bottom:16px;">
                <label style="display:block;font-weight:600;font-size:0.85rem;color:var(--navy);margin-bottom:6px;">Select Client</label>
                <select name="link_to_client" required style="width:100%;padding:10px 14px;border:1.5px solid rgba(0,0,0,0.1);border-radius:8px;font-size:0.92rem;font-family:'DM Sans',sans-serif;">
                    <option value="">Choose a client...</option>
                    <?php foreach ($clientsList as $cl): ?>
                        <option value="<?php echo $cl['id']; ?>"><?php echo htmlspecialchars($cl['company_name'] ?: $cl['full_name']); ?> <?php echo $cl['client_code'] ? '(' . htmlspecialchars($cl['client_code']) . ')' : ''; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="display:flex;gap:12px;">
                <button type="submit" class="btn btn-save">Link Contact</button>
                <button type="button" onclick="closeLinkModal()" class="btn btn-cancel">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
function openLinkModal(contactId, contactName) {
    document.getElementById('linkContactId').value = contactId;
    document.getElementById('linkContactName').textContent = 'Linking: ' + contactName;
    document.getElementById('linkModal').style.display = 'flex';
}
function closeLinkModal() {
    document.getElementById('linkModal').style.display = 'none';
}
document.getElementById('linkModal').addEventListener('click', function(e) {
    if (e.target === this) closeLinkModal();
});
</script>

</body>
</html>
