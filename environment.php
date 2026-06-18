<?php
if(!defined('APPDATA_PATH')) define('APPDATA_PATH', dirname(__FILE__) . '/www.appdata');
if(!defined('HOME_PATH')) define('HOME_PATH', dirname(__FILE__) . '/www');
if(!defined('ABS_PATH')) define('ABS_PATH', dirname(__DIR__)));

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
