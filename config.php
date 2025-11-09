<?php
$host = "localhost";
$user = "root";     // Default XAMPP username
$pass = "";         // Default XAMPP password is empty
$db   = "db_warehouse";  // Your database name

$conn = new mysqli($host, $user, $pass, $db);

// Check connection
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}
?>
