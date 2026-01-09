<?php
session_start();
header('Content-Type: application/json');

// 🔒 Access control
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['manager_head', 'support_staff'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit();
}

// 🧩 Database connection
$conn = new mysqli("localhost", "u248040635_cedy", "(April_23_2004)!!!!", "u248040635_ticketsystem");
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed.']);
    exit();
}

$user_id = intval($_SESSION['user_id']);
$ticket_id = intval($_POST['ticket_id'] ?? 0);

if ($ticket_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid or missing ticket ID.']);
    exit();
}

// 🧾 Fetch current ticket data
$stmt = $conn->prepare("
    SELECT 
        ticket_id, 
        status, 
        manager_id, 
        assigned_staff_id, 
        original_assigned_staff_id, 
        department_id 
    FROM tickets 
    WHERE ticket_id = ?
");
$stmt->bind_param('i', $ticket_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Ticket not found.']);
    $stmt->close();
    $conn->close();
    exit();
}

$ticket = $result->fetch_assoc();
$stmt->close();

// 🔍 Preserve department and manager linkage
$manager_id = intval($ticket['manager_id']);
$assigned_staff_id = intval($ticket['assigned_staff_id']);
$original_staff_id = intval($ticket['original_assigned_staff_id']);
$department_id = intval($ticket['department_id']);

// ✅ Ensure original staff record is saved (for reopened classification)
if (empty($original_staff_id) && !empty($assigned_staff_id)) {
    $save_original = $conn->prepare("
        UPDATE tickets 
        SET original_assigned_staff_id = ? 
        WHERE ticket_id = ?
    ");
    $save_original->bind_param('ii', $assigned_staff_id, $ticket_id);
    $save_original->execute();
    $save_original->close();
}

// ✅ Update the ticket status to pending (reopened)
$update = $conn->prepare("
    UPDATE tickets 
    SET status = 'pending', 
        assigned_staff_id = NULL, 
        reopened_by = ?, 
        reopened_at = NOW() 
    WHERE ticket_id = ?
");
$update->bind_param('ii', $user_id, $ticket_id);

if ($update->execute()) {
    $update->close();

    // 🗒️ Log the action in ticket_status_history
    $log = $conn->prepare("
        INSERT INTO ticket_status_history (ticket_id, changed_by, old_status, new_status, change_date, remarks)
        VALUES (?, ?, ?, 'pending', NOW(), 'Ticket reopened by Manager Head')
    ");
    $old_status = $ticket['status'];
    $log->bind_param('iis', $ticket_id, $user_id, $old_status);
    $log->execute();
    $log->close();

    // 📨 Return success
    echo json_encode([
        'success' => true,
        'message' => 'Ticket successfully reopened and ready for reassignment.'
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to reopen the ticket.']);
    $update->close();
}

$conn->close();
?>
