<?php
/**
 * IGNYTE Consulting - Database Configuration
 * 
 * IMPORTANT: Update these values with your Hostinger MySQL credentials.
 * Find them in hPanel -> Databases -> MySQL Databases.
 * 
 * For security, after deployment you can edit this file directly
 * on Hostinger via File Manager so credentials aren't in Git.
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'your_database_name');
define('DB_USER', 'your_database_user');
define('DB_PASS', 'your_database_password');

// Mailchimp API integration
// Get your API key: Mailchimp -> Account -> Extras -> API Keys
// Get your Audience ID: Mailchimp -> Audience -> Settings -> Audience name and defaults
define('MAILCHIMP_API_KEY', '');  // e.g. 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx-us21'
define('MAILCHIMP_AUDIENCE_ID', '');  // e.g. 'a1b2c3d4e5'

// Atlassian (Confluence + Jira) integration
// Get your API token: https://id.atlassian.com/manage-profile/security/api-tokens
define('ATLASSIAN_EMAIL', '');       // e.g. 'you@company.com'
define('ATLASSIAN_API_TOKEN', '');   // e.g. 'xxxxxxxxxxxxxxxxxxxxxxxx'
define('ATLASSIAN_DOMAIN', '');      // e.g. 'yourcompany.atlassian.net'

define('SITE_URL', 'https://www.ignyteconsulting.com');
define('ADMIN_URL', SITE_URL . '/admin');

// Session configuration
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);

function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        } catch (PDOException $e) {
            die('Database connection failed. Please check your config.php credentials.');
        }
    }
    return $pdo;
}
