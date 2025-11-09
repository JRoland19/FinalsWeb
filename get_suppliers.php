<?php
session_start();
include("config.php");

// Admin access check
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    exit('Forbidden');
}

// Fetch all companies that act as suppliers
$result = $conn->query("SELECT name FROM company_names ORDER BY name ASC");

$suppliers = [];
while($row = $result->fetch_assoc()) {
    $suppliers[] = $row['name'];
}

header('Content-Type: application/json');
echo json_encode($suppliers);
