# PHP Coding Rules - AI Instructions

## PROJECT STRUCTURE

### environment.php
Every project starts with environment.php in project root (outside webroot):

```php
<?php
// environment.php - Project bootstrap file
define('ABS_PATH', dirname(__DIR__));              // Project root: /var/www/myproject
define('PUBLIC_PATH', ABS_PATH . '/www');          // Web accessible: http://video-wall.local/
define('APPDATA_PATH', ABS_PATH . '/www.appdata'); // Application files (not web accessible)
define('LOGS_PATH', ABS_PATH . '/logs');           // Application logs

require_once ABS_PATH . '/vendor/autoload.php';
require_once ABS_PATH . '/credentials.php';        // Database, API keys, etc.
```

All PHP files should start by requiring the `environment.php` file, this will make sure the file have all the libraries available.

### credentials.php

All sensitive data goes in credentials.php (same level as environment.php):

## DATABASE RULES

- Always wrap column names and table names in backticks (`) to avoid reserved word conflicts
- Use 0/1 for BOOLEAN fields in MySQL, never true/false

- Ved bruk av PDO skal ALLTID posisjonelle parametere brukes - ALDRI navngitte parametere.

## CURL SYNTAX

- Executable one-liners uten backslashes/linjeskift

```
WINDOWS TERMINAL CURL SYNTAX:
curl.exe -X POST -H "Content-Type: application/json" -d "{\"phone\":\"+4712345678\"}" URL
```
