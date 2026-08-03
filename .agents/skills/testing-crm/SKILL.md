---
name: testing-crm-admin
description: Test the IGNYTE CRM admin panel (clients, projects, tools/licenses) end-to-end. Use when verifying CRM UI, form submissions, or admin panel changes.
---

# Testing the CRM Admin Panel

## Overview

The IGNYTE admin panel is a PHP/MySQL application hosted on Hostinger. Since there's no MySQL database available locally, testing requires a SQLite mock database.

## Prerequisites

- PHP 8.1+ with SQLite extension (`php-sqlite3`)
- Install if missing: `sudo apt-get install -y php php-sqlite3 php-cli`

## Local Test Environment Setup

### 1. Create SQLite Mock Config

The production `admin/config.php` uses MySQL. For local testing, temporarily replace it with a SQLite-based config:

```php
// Save original: cp public_html/admin/config.php public_html/admin/config.php.bak
// Create test config at public_html/admin/config.php
<?php
define('DB_HOST', 'localhost');
define('DB_NAME', 'test');
define('DB_USER', 'test');
define('DB_PASS', 'test');
define('SITE_URL', 'http://localhost:8080');
define('ADMIN_URL', SITE_URL . '/admin');

function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        $dbPath = __DIR__ . '/test.db';
        $pdo = new PDO('sqlite:' . $dbPath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        // Create all required tables
        $pdo->exec("CREATE TABLE IF NOT EXISTS admin_users (id INTEGER PRIMARY KEY, username TEXT, password TEXT, created_at TEXT)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS blog_posts (id INTEGER PRIMARY KEY, title TEXT, content TEXT, excerpt TEXT, category TEXT, status TEXT, created_at TEXT, updated_at TEXT)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS clients (id INTEGER PRIMARY KEY, email TEXT, password TEXT, full_name TEXT, company TEXT, status TEXT, created_at TEXT)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS crm_clients (id INTEGER PRIMARY KEY, full_name TEXT, email TEXT, phone TEXT, company_name TEXT, industry TEXT, client_status TEXT, address TEXT, notes TEXT, created_at TEXT, updated_at TEXT)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS crm_projects (id INTEGER PRIMARY KEY, project_name TEXT, client_id INTEGER, project_status TEXT, priority TEXT, start_date TEXT, end_date TEXT, budget TEXT, description TEXT, notes TEXT, created_at TEXT, updated_at TEXT)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS crm_tools (id INTEGER PRIMARY KEY, tool_name TEXT, vendor TEXT, client_id INTEGER, license_key TEXT, cost TEXT, billing_cycle TEXT, start_date TEXT, expiry_date TEXT, tool_status TEXT, notes TEXT, created_at TEXT, updated_at TEXT)");
        // Insert test admin
        $pdo->exec("INSERT OR IGNORE INTO admin_users (id, username, password) VALUES (1, 'admin', '" . password_hash('test', PASSWORD_DEFAULT) . "')");
    }
    return $pdo;
}
```

### 2. Create Test Login Helper

CRM pages require an admin session. Create `public_html/admin/test-login.php`:

```php
<?php
session_start();
$_SESSION['admin_id'] = 1;
$_SESSION['admin_name'] = 'Test Admin';
header('Location: crm.php');
```

### 3. Start PHP Server

```bash
cd /home/ubuntu/repos/ignyte/public_html
php -S localhost:8080 &
```

### 4. Initialize Session

Visit `http://localhost:8080/admin/test-login.php` in the browser to set the admin session.

## What to Test

### CRM Clients (`/admin/crm.php`)
- Form fields: full_name, email (required), phone, company_name, industry, client_status (select), address, notes
- Add client → verify success banner, stats update, client in table
- Filter buttons: All, Active, Prospects, Inactive → URL updates, table re-filters
- Mailchimp export link: `crm.php?export=mailchimp`

### Projects (`/admin/projects.php`)
- Form fields: project_name (required), client_id (dropdown from CRM), project_status, priority, start_date, end_date, budget, description, notes
- Client dropdown should be populated from CRM clients
- Add project → verify in table with client assignment

### Tools/Licenses (`/admin/tools.php`)
- Form fields: tool_name (required), vendor, client_id, license_key, cost, billing_cycle (select), start_date, expiry_date, tool_status, notes
- **Expiry warnings**: Add tool with expiry_date ≤30 days from today → orange warning banner + "Expiring Soon" stat card increments + date shown in danger color with "(Xd)" countdown
- Filter buttons: All, Active, Expiring (90d), Expired

### Navigation
- Dashboard (`/admin/dashboard.php`) has links to: Blog, CRM, Projects, Tools, View Site, Password, Logout
- CRM pages have links to: Blog, Clients, Projects, Tools/Licenses, View Site, Logout
- Active page is highlighted in nav

### Mobile Responsiveness
- Use Chrome DevTools device toolbar at 375-400px width
- Verify: nav wraps, form fields stack vertically, stat cards in 2x2 grid, no horizontal overflow

## Cleanup After Testing

```bash
cd /home/ubuntu/repos/ignyte/public_html/admin
cp config.php.bak config.php
rm -f test-config.php test-login.php test.db config.php.bak
```

**Important**: Never commit test files (test.db, test-login.php, config.php.bak) to the repo.

## SQLite Limitations

- MySQL ENUM types store as TEXT in SQLite — validation may differ
- Foreign key cascades might not behave identically
- Mailchimp CSV export requires actual client data to generate a downloadable file
- Production-specific behavior (Hostinger MySQL) cannot be fully replicated locally

## Devin Secrets Needed

No secrets needed for local testing. Production testing on Hostinger would require:
- `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` (MySQL credentials in admin/config.php on server)
- Admin login: `/admin/login.php` (default credentials set during setup)
