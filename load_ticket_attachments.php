<?php
session_start();

// AUTH CHECK
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['support_staff', 'manager_head'])) {
    exit('Access denied');
}

$conn = new mysqli("localhost", "u248040635_cedy", "(April_23_2004)!!!!", "u248040635_ticketsystem");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

$ticket_id = isset($_GET['ticket_id']) ? intval($_GET['ticket_id']) : 0;

if ($ticket_id > 0) {
    $stmt = $conn->prepare("SELECT attachment FROM tickets WHERE ticket_id = ?");
    $stmt->bind_param('i', $ticket_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $fileHtml = '-';
    $viewHtml = '-';

    if ($row = $result->fetch_assoc()) {
        if (!empty($row['attachment'])) {
            $fileHtml = "<a href='uploads/{$row['attachment']}' download>Download</a>";
            $viewHtml = "<a href='uploads/{$row['attachment']}' target='_blank'>View File</a>";
        }
    }

    echo json_encode([
        'file' => $fileHtml,
        'view' => $viewHtml
    ]);
}

$stmt->close();
$conn->close();
