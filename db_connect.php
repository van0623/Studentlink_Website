
<?php
$host = "localhost";
$username = "root";
$password = "";
$database = "studentlink_db";

$conn = new mysqli(
    $host,
    $username,
    $password,
    $database
);

if ($conn->connect_error) {
    error_log($conn->connect_error);
    exit("Database connection failed. Check your MySQL settings.");
}

$conn->set_charset("utf8mb4");
?>
