<?php
if(!defined('ABS_PATH')) define('ABS_PATH', __DIR__);
if(!defined('APPDATA_PATH')) define('APPDATA_PATH', ABS_PATH . '/www.appdata');
if(!defined('HOME_PATH')) define('HOME_PATH', ABS_PATH . '/www');
if(!defined('LOGS_PATH')) define('LOGS_PATH', ABS_PATH . '/logs');
if(!defined('STORAGE_PATH')) define('STORAGE_PATH', ABS_PATH . '/storage');

require ABS_PATH . '/credentials.php';
require ABS_PATH . '/vendor/autoload.php';
require APPDATA_PATH . '/classes/AssemblyAI.php';

// Initiate the DB connection 
Mysqli2::isDev(true);
$mysqli = Mysqli2::getInstance($mysql_host, $mysql_port, $mysql_user, $mysql_password, $mysql_database);
$mysqli->set_charset("utf8");
if ($mysqli->connect_errno) {
    echo 'Failed to connect to MySQL: (' . $mysqli->connect_errno . ') ' . $mysqli->connect_error; 
    exit();
}
