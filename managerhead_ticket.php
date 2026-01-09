<?php
session_start();

// 🔒 AUTHENTICATION CHECK
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'manager_head') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Unauthorized access. Please log in as Manager Head.']);
    exit();
}

$user_id = intval($_SESSION['user_id']);

// 🧩 DATABASE CONNECTION
$conn = new mysqli("localhost", "u248040635_cedy", "(April_23_2004)!!!!", "u248040635_ticketsystem");
if ($conn->connect_error) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Database connection failed: ' . $conn->connect_error]);
    exit();
}

// 🏢 FETCH MANAGER HEAD'S DEPARTMENT
$stmt_dept = $conn->prepare("SELECT department_id FROM users WHERE user_id = ?");
if ($stmt_dept === false) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Database query error: ' . $conn->error]);
    exit();
}
$stmt_dept->bind_param('i', $user_id);
$stmt_dept->execute();
$result_dept = $stmt_dept->get_result();
if ($result_dept->num_rows === 0) {
    $stmt_dept->close();
    $conn->close();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'User department not found.']);
    exit();
}
$row_dept = $result_dept->fetch_assoc();
$department_id = intval($row_dept['department_id'] ?? 0);
$stmt_dept->close();

if ($department_id === 0) {
    $conn->close();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'No department assigned to user.']);
    exit();
}

// 📋 PARAMETERS
$status_filter = $_GET['status'] ?? 'all';
$page = max(1, intval($_GET['page'] ?? 1));
$limit = max(1, min(50, intval($_GET['limit'] ?? 10))); // Between 1–50
$offset = ($page - 1) * $limit;

// 🧠 WHERE CONDITIONS
$where_conditions = ["t.department_id = ?"];
$params = [$department_id];
$types = 'i';

if ($status_filter !== 'all') {
    $where_conditions[] = "t.status = ?";
    $params[] = $status_filter;
    $types .= 's';
}

$where_clause = implode(' AND ', $where_conditions);

// 🧮 COUNT QUERY
$count_query = "SELECT COUNT(*) as total FROM tickets t WHERE $where_clause";
$count_stmt = $conn->prepare($count_query);
if ($count_stmt === false) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Count query preparation failed: ' . $conn->error]);
    exit();
}
$count_stmt->bind_param($types, ...$params);
$count_stmt->execute();
$count_result = $count_stmt->get_result();
$total_tickets = $count_result->fetch_assoc()['total'] ?? 0;
$count_stmt->close();

$pages = ceil($total_tickets / $limit);

// 🧾 MAIN QUERY (NOW INCLUDES REOPENED RECOGNITION)
$main_query = "
    SELECT 
        t.ticket_id, 
        t.control_number, 
        t.title, 
        t.description, 
        t.issue, 
        t.status, 
        t.manager_id, 
        t.assigned_staff_id,
        t.original_assigned_staff_id,
        t.user_id, 
        t.created_at,
        t.updated_at,
        COALESCE(u.name, 'Unknown') AS submitted_by,
        m.name AS assigned_to,
        CASE 
    WHEN t.status = 'pending'
         AND t.original_assigned_staff_id IS NOT NULL
         AND t.assigned_staff_id IS NULL
    THEN 'reopened'
    ELSE t.status
END AS computed_status
    FROM tickets t 
    LEFT JOIN users u ON t.user_id = u.user_id
    LEFT JOIN users m ON t.manager_id = m.user_id
    WHERE $where_clause
    ORDER BY t.created_at DESC
    LIMIT ? OFFSET ?
";

$params[] = $limit;
$params[] = $offset;
$types .= 'ii';

$stmt = $conn->prepare($main_query);
if ($stmt === false) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Main query preparation failed: ' . $conn->error]);
    exit();
}

$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

// 📦 BUILD RESPONSE DATA
$tickets = [];
while ($row = $result->fetch_assoc()) {
    $tickets[] = [
        'ticket_id' => intval($row['ticket_id']),
        'control_number' => $row['control_number'],
        'title' => $row['title'],
        'description' => $row['description'],
        'issue' => $row['issue'],
        'status' => $row['status'],
        'computed_status' => $row['computed_status'],
        'manager_id' => intval($row['manager_id'] ?? 0),
        'assigned_staff_id' => intval($row['assigned_staff_id'] ?? 0),
        'original_assigned_staff_id' => intval($row['original_assigned_staff_id'] ?? 0),
        'user_id' => intval($row['user_id']),
        'created_at' => $row['created_at'],
        'updated_at' => $row['updated_at'] ?? null,
        'submitted_by' => $row['submitted_by'],
        'assigned_to' => $row['assigned_to'] ?? null,
        'reopen_flag' => ($row['computed_status'] === 'reopened')
    ];
}

$stmt->close();
$conn->close();

// 📨 JSON RESPONSE
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'tickets' => $tickets,
    'total' => intval($total_tickets),
    'pages' => intval($pages),
    'current_page' => $page,
    'limit' => $limit,
    'status_filter' => $status_filter
]);
?>
