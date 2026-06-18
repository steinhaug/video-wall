# Mysqli2 Compact Documentation

## Overview
Enhanced MySQLi wrapper with simplified prepared statements and smart return values. Inherits all standard MySQLi methods.

## Installation & Setup

### Installation

    composer require steinhaug/mysqli

### Basic Initialization

```php
// Enable development mode (verbose errors)
Mysqli2::isDev(true);

// Get singleton instance with connection parameters
$mysqli = Mysqli2::getInstance($mysql_host, $mysql_port, $mysql_user, $mysql_password, $mysql_database);

// Set character encoding
$mysqli->set_charset("utf8");

// Check connection
if ($mysqli->connect_errno) {
    echo 'Failed to connect to MySQL: (' . $mysqli->connect_errno . ') ' . $mysqli->connect_error; 
    exit();
}
```

## Core Methods

### execute($sql, $types, $params)
Main method for all database operations.

**Returns:**
- **INSERT**: `last_insert_id` or `affected_rows`
- **UPDATE/DELETE**: `affected_rows` 
- **SELECT**: Array of associative arrays
- **Error**: `false`

**Examples:**
```php
// INSERT - returns new ID
$user_id = $mysqli->execute(
    "INSERT INTO users (name, email) VALUES (?, ?)",
    'ss',
    ['John Doe', 'john@example.com']
);

// SELECT - returns array of rows
$users = $mysqli->execute(
    "SELECT * FROM users WHERE age > ?",
    'i',
    [25]
);

// UPDATE - returns affected rows
$affected = $mysqli->execute(
    "UPDATE users SET email = ? WHERE id = ?",
    'si',
    ['new@email.com', 123]
);

// DELETE - returns affected rows
$deleted = $mysqli->execute(
    "DELETE FROM users WHERE status = ?",
    's',
    ['inactive']
);
```

### execute1($sql, $types, $params, $return)
For single row/value queries.

**Return modes:**
- `0`: First column value only
- `true`: Full row or `null` if empty
- `'default'`: First row (error if empty)

**Examples:**
```php
// Get single value
$count = $mysqli->execute1(
    "SELECT COUNT(*) FROM users WHERE status = ?",
    's',
    ['active'],
    0
);

// Get single row (or null)
$user = $mysqli->execute1(
    "SELECT * FROM users WHERE id = ?",
    'i',
    [123],
    true
);

// Get first row (error if empty)
$user = $mysqli->execute1(
    "SELECT * FROM users WHERE status = ?",
    's',
    ['active']
);
```

## Type Reference
- `i` - Integer
- `s` - String  
- `d` - Double/Float
- `b` - Blob

## Transactions
Uses standard MySQLi transaction methods:

```php
$mysqli->autocommit(false);
try {
    $user_id = $mysqli->execute("INSERT INTO users...", 'ss', $params);
    $mysqli->execute("INSERT INTO profiles...", 'is', [$user_id, $bio]);
    $mysqli->commit();
} catch (Exception $e) {
    $mysqli->rollback();
    throw $e;
}
$mysqli->autocommit(true);
```

## Error Handling
Throws `DatabaseException` on errors in development mode.

```php
try {
    $result = $mysqli->execute($sql, $types, $params);
} catch (DatabaseException $e) {
    // Handle error
}
```

## Common Patterns

### Authentication Check
```php
$user = $mysqli->execute1(
    "SELECT id, password_hash FROM users WHERE email = ?",
    's',
    [$email],
    true
);
```

### Existence Check
```php
$exists = $mysqli->execute1(
    "SELECT COUNT(*) FROM users WHERE email = ?",
    's',
    [$email],
    0
) > 0;
```

### Single Parameter Shortcut
```php
// Can pass single value instead of array
$user = $mysqli->execute1("SELECT * FROM users WHERE id = ?", 'i', 123, true);
```