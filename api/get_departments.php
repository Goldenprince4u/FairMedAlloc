<?php
/**
 * API Endpoint: Get Departments
 * Returns JSON list of departments based on a faculty_id.
 * Note: No auth required — this data is used on the public signup page.
 */
require_once '../db_config.php';

header('Content-Type: application/json');
header('Cache-Control: public, max-age=300'); // Cache 5 min - faculty/dept data rarely changes

if (!isset($_GET['faculty_id'])) {
    echo json_encode(['error' => 'Missing faculty_id']);
    exit;
}

$faculty_id = (int)$_GET['faculty_id'];

$stmt = $conn->prepare("SELECT department_id, name FROM departments WHERE faculty_id = ? ORDER BY name ASC");
$stmt->bind_param("i", $faculty_id);
$stmt->execute();
$result = $stmt->get_result();

$departments = [];
while ($row = $result->fetch_assoc()) {
    $departments[] = [
        'id' => $row['department_id'],
        'name' => $row['name']
    ];
}

echo json_encode($departments);
?>
