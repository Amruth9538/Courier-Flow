<?php
/**
 * Database configuration template.
 *
 * Copy this file to database.php and fill in your local MySQL credentials:
 *
 *   copy database.example.php database.php
 *
 * database.php is ignored by git so your password is not published.
 */

$db_host = '127.0.0.1';
$db_user = 'root';
$db_pass = '';
$db_name = 'parcel_delivery_system';

// Optional OpenRouteService key for Malaysia live road routing.
// Prefer setting ORS_API_KEY as a server environment variable.
$ors_api_key = getenv('ORS_API_KEY') ?: '';
