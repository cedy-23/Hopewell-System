<?php
session_start();

// Check if user is logged in and is a manager_head
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'manager_head') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit();
}

$conn = new mysqli("localhost", "u248040635_cedy", "(April_23_2004)!!!!", "u248040635_ticketsystem");
if ($conn->connect_error) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Database connection failed.']);
    exit();
}

$department_id = 0;
$user_id = intval($_SESSION['user_id']);

// Fetch department_id from users table
$stmt_dept = $conn->prepare("SELECT department_id FROM users WHERE user_id = ?");
if ($stmt_dept === false) {
    echo json_encode(['success' => false, 'message' => 'Database error.']);
    exit();
}
$stmt_dept->bind_param('i', $user_id);
$stmt_dept->execute();
$result_dept = $stmt_dept->get_result();
if ($result_dept->num_rows === 0) {
    $stmt_dept->close();
    echo json_encode(['success' => false, 'message' => 'User  not found.']);
    exit();
}
$row_dept = $result_dept->fetch_assoc();
$department_id = intval($row_dept['department_id'] ?? 0);
$stmt_dept->close();

if ($department_id === 0) {
    echo json_encode(['success' => false, 'message' => 'No department assigned to user.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

$ticket_id = intval($_POST['ticket_id'] ?? 0);
$manager_id = intval($_POST['manager_id'] ?? 0);

if ($ticket_id <= 0 || $manager_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid input.']);
    exit();
}

// Verify ticket exists, is pending, unassigned, and in the department
$stmt = $conn->prepare("
    SELECT t.ticket_id, t.control_number, t.status, t.department_id, t.manager_id 
    FROM tickets t 
    WHERE t.ticket_id = ? AND t.department_id = ? AND t.status = 'pending' AND t.manager_id IS NULL
");
if ($stmt === false) {
    echo json_encode(['success' => false, 'message' => 'Database error.']);
    exit();
}
$stmt->bind_param('ii', $ticket_id, $department_id);
$stmt->execute();
$res = $stmt->get_result();
if ($res->num_rows === 0) {
    $stmt->close();
    echo json_encode(['success' => false, 'message' => 'Ticket not found, already assigned, or not pending.']);
    exit();
}
$ticket = $res->fetch_assoc();
$stmt->close();

// Verify manager is valid (role=manager, same department, active)
$mstmt = $conn->prepare("
    SELECT user_id, name, role 
    FROM users 
    WHERE user_id = ? AND department_id = ? AND status = 'active' AND role = 'support_staff'
");
if ($mstmt === false) {
    echo json_encode(['success' => false, 'message' => 'Database error.']);
    exit();
}
$mstmt->bind_param('ii', $manager_id, $department_id);
$mstmt->execute();
$mres = $mstmt->get_result();
if ($mres->num_rows === 0) {
    $mstmt->close();
    echo json_encode(['success' => false, 'message' => 'Invalid support selected.']);
    exit();
}
$support = $mres->fetch_assoc();
$mstmt->close();

// Update ticket: assign manager, set status to 'in_progress'
$ustmt = $conn->prepare("
    UPDATE tickets 
    SET manager_id = ?, status = 'in_progress', accepted_at = NOW() 
    WHERE ticket_id = ?
");
if ($ustmt === false) {
    echo json_encode(['success' => false, 'message' => 'Database error.']);
    exit();
}
$ustmt->bind_param('ii', $manager_id, $ticket_id);
if (!$ustmt->execute()) {
    $ustmt->close();
    echo json_encode(['success' => false, 'message' => 'Failed to assign ticket to Support Staff.']);
    exit();
}
$affected_rows = $ustmt->affected_rows;
$ustmt->close();

if ($affected_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'No changes made to ticket.']);
    exit();
}

// Log action in audit_log (if audit_log table exists)
$action = "Assigned ticket " . $ticket['control_number'] . " to Support Staff " . $support['name'];
$lstmt = $conn->prepare("
    INSERT INTO audit_log (user_id, action, log_time) 
    VALUES (?, ?, NOW())
");
if ($lstmt !== false) {
    $lstmt->bind_param('is', $user_id, $action);
    $lstmt->execute();
    $lstmt->close();
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['success' => true, 'message' => 'Ticket assigned to ' . $support['name'] . ' successfully!']);

$conn->close();
?>