<?php
// Copy this file to config.php and fill in production credentials.

define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'punchlist');
define('DB_USER', 'punchlist');
define('DB_PASS', 'secret');
define('DB_CHARSET', 'utf8mb4');

define('BASE_URL', 'http://localhost'); // no trailing slash

// Primary (apps) DSN + optional core governance DSN (users/roles)
define('APPS_DSN', sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET));
define('APPS_DB_USER', DB_USER);
define('APPS_DB_PASS', DB_PASS);

define('CORE_DSN', 'mysql:host=127.0.0.1;dbname=core_db;charset=utf8mb4');
define('CORE_DB_USER', 'core_user');
define('CORE_DB_PASS', 'core_secret');

// S3-compatible storage credentials
// Example for MinIO running locally: http://127.0.0.1:9000

define('S3_ENDPOINT', 'https://your-s3-endpoint');
define('S3_KEY', 'your-access-key');
define('S3_SECRET', 'your-secret-key');
define('S3_BUCKET', 'punchlist');
define('S3_REGION', 'us-east-1');
define('S3_USE_PATH_STYLE', true); // set false if using virtual-hosted-style endpoints

// Optional: override the URL base used to serve files.
define('S3_URL_BASE', ''); // leave blank to derive from endpoint + bucket.

// Security

define('SESSION_NAME', 'punchlist_session');
define('CSRF_TOKEN_NAME', 'csrf_token');

define('APP_TIMEZONE', 'UTC');

define('APP_TITLE', 'Punch List Manager');

// Security hardening
define('APP_FORCE_HTTPS', false); // set true when site is behind HTTPS
define('APP_HSTS_MAX_AGE', 31536000); // one year
define('APP_CONTENT_SECURITY_POLICY', "default-src 'self'; img-src 'self' data: blob:; style-src 'self' 'unsafe-inline'; script-src 'self'; connect-src 'self' https://" . parse_url(S3_ENDPOINT, PHP_URL_HOST));

// Mail / notifications
define('MAIL_FROM_ADDRESS', 'no-reply@example.com');
define('MAIL_FROM_NAME', 'Punch List');
define('MAIL_RETURN_PATH', ''); // optional bounce/return address
define('MAIL_TRANSPORT', 'mail'); // mail or ses
define('MAIL_SES_REGION', 'us-east-1'); // used when MAIL_TRANSPORT === 'ses'
define('MAIL_SES_KEY', '');
define('MAIL_SES_SECRET', '');