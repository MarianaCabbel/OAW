<?php
$host = 'localhost';
$user = 'root';
$password = '';
$database = 'test';
$port = 3306;

$connection = new mysqli($host, $user, $password, $database, $port);

$dbConnected = !$connection->connect_error;
$dbError = $connection->connect_error;

if ($dbConnected) {
    $connection->set_charset('utf8mb4');
}
