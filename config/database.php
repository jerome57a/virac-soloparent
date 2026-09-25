<?php
/**
 * PDO connection. Default credentials match a stock XAMPP install
 * (root / no password). Update if your MySQL is configured differently.
 */
require_once __DIR__ . '/config.php';

const DB_HOST = 'localhost';
const DB_NAME = 'virac_solo_parent';
const DB_USER = 'root';
const DB_PASS = '';

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
    http_response_code(500);
    die('Database connection failed. Make sure MySQL is running and the "virac_solo_parent" '
        . 'database has been imported from database/schema.sql. (' . htmlspecialchars($e->getMessage()) . ')');
}
