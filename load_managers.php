<?php
session_start();
$conn = new mysqli("localhost", "u248040635_cedy", "(April_23_2004)!!!!", "u248040635_ticketsystem");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

$department_id = intval($_GET['department_id']);
$current_manager_id = $_SESSION['user_id'] ?? 0;
$exclude_user_id = intval($_GET['exclude_id'] ?? 0);

// Only fetch active managers in the selected department excluding the logged-in manager and optionally another user (e.g., ticket submitter)
$stmt = $conn->prepare("SELECT user_id, name 
                        FROM users 
                        WHERE role = 'support_staff' 
                          AND department_id = ? 
                          AND status = 'active' 
                          AND user_id != ? 
                          AND user_id != ?");
if ($stmt === false) {
    die("Database error: " . $conn->error);
}
$stmt->bind_param("iii", $department_id, $current_manager_id, $exclude_user_id);
$stmt->execute();
$result = $stmt->get_result();

echo '<option value="">-- Select Support Staff --</option>';
while ($row = $result->fetch_assoc()) {
    echo "<option value='" . htmlspecialchars($row['user_id']) . "'>" . htmlspecialchars($row['name']) . "</option>";
}

$stmt->close();
$conn->close();
?>
