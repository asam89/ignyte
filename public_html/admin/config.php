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

// Mailchimp API integration (legacy — used by CRM sync only)
define('MAILCHIMP_API_KEY', '');  // Set on Hostinger if using Mailchimp CRM sync
define('MAILCHIMP_AUDIENCE_ID', '');  // Set on Hostinger if using Mailchimp CRM sync

// Resend API integration (newsletter sending)
// Get your API key at: https://resend.com/api-keys
// Verify ignyteconsulting.com in Resend domains first
define('RESEND_API_KEY', '');  // Set on Hostinger: your Resend API key (e.g. 're_xxxxxxxxxxxx')
define('RESEND_FROM_EMAIL', 'newsletter@ignyteconsulting.com');  // Must be from a verified domain
define('RESEND_FROM_NAME', 'IGNYTE Consulting');

define('SITE_URL', 'https://www.ignyteconsulting.com');
define('ADMIN_URL', SITE_URL . '/admin');

// Site Manager embed integration
// Set these to enable the "Website Updates" feature in the client portal
define('SITE_MANAGER_URL', '');  // e.g. 'https://site-manager-ebon.vercel.app'
define('SITE_MANAGER_API_SECRET', '');  // matches INTERNAL_API_SECRET in Site Manager .env

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
            throw new Exception('Database connection failed. Please check your config.php credentials.');
        }
    }
    return $pdo;
}
