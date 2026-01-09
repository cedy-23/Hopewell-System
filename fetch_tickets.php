<?php
session_start();
$conn = new mysqli("localhost", "u248040635_cedy", "(April_23_2004)!!!!", "u248040635_ticketsystem");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'staff') {
    exit('Access denied');
}


$user_id = intval($_SESSION['user_id']);
$status_filter = $_GET['status'] ?? 'all';

// Build base WHERE clause
$where = "t.assigned_staff_id = ?";
$params = [$user_id];
$types = "i";

// Add filtering condition
switch ($status_filter) {
    case 'pending':
        $where .= " AND t.status = 'pending' AND t.assigned_staff_id IS NOT NULL";
        break;
    case 'reopened':
        // Correct reopened classification: pending tickets with original staff but no active assignment
        $where .= " AND t.status = 'pending' 
                    AND t.original_assigned_staff_id = ? 
                    AND t.assigned_staff_id IS NULL";
        $params[] = $user_id;
        $types .= "i";
        break;
    case 'in_progress':
    case 'resolved':
    case 'closed':
    case 'declined':
        $where .= " AND t.status = ?";
        $params[] = $status_filter;
        $types .= "s";
        break;
    default:
        // Show all active tickets assigned to the staff
        $where .= " AND NOT (t.status = 'pending' AND t.assigned_staff_id IS NULL)";
        break;
}

// Query with computed status and joins
$query = "
    SELECT 
        t.ticket_id, t.control_number, t.title, t.issue, t.status, t.created_at, t.updated_at,
        d.department_name, u.name AS submitted_by,
        tf.rating, tf.reason,
        CASE 
            WHEN t.status = 'pending'
                 AND t.original_assigned_staff_id IS NOT NULL
                 AND t.assigned_staff_id IS NULL
            THEN 'reopened'
            ELSE t.status
        END AS computed_status
    FROM tickets t
    JOIN departments d ON t.department_id = d.department_id
    JOIN users u ON t.user_id = u.user_id
    LEFT JOIN ticket_feedback tf ON t.ticket_id = tf.ticket_id
    WHERE $where
    ORDER BY t.created_at DESC
";

$stmt = $conn->prepare($query);
if (!$stmt) {
    die("Database error: " . $conn->error);
}
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

// Function for duration display
function formatDuration($start, $end = null) {
    if (!$end) $end = date('Y-m-d H:i:s');
    $startTime = strtotime($start);
    $endTime = strtotime($end);
    $diff = $endTime - $startTime;
    $hours = floor($diff / 3600);
    $minutes = floor(($diff % 3600) / 60);
    $duration = ($hours > 0 ? $hours . 'h ' : '') . $minutes . 'm';
    $tooltip = 'Started: ' . date('M d, Y H:i', $startTime) . ' | Ended: ' . date('M d, Y H:i', $endTime);
    return "<span class='duration-btn' title='$tooltip'>$duration</span>";
}

// Generate HTML
if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $status_class = "status-" . str_replace("_", "-", strtolower($row['computed_status']));
        $duration = formatDuration($row['created_at'], $row['updated_at'] ?? null);

        $ratingDisplay = 'N/A';
        if (!empty($row['rating'])) {
            $ratingClass = "rating-" . strtolower($row['rating']);
            $ratingDisplay = "<span class='rating-badge {$ratingClass}'>{$row['rating']}</span>";
            if (!empty($row['reason'])) {
                $ratingDisplay .= "<div class='reason-title'>{$row['reason']}</div>";
            }
        }

        echo "<tr>";
        echo "<td>" . htmlspecialchars($row['control_number']) . "</td>";
        echo "<td>" . htmlspecialchars($row['department_name']) . "</td>";
        echo "<td>" . htmlspecialchars($row['submitted_by']) . "</td>";
        echo "<td><details><summary>" . htmlspecialchars($row['title']) . "</summary>
              <div class='issue-box'>" . nl2br(htmlspecialchars($row['issue'])) . "</div></details></td>";
        echo "<td><span class='status-pill {$status_class}'>" . ucfirst(htmlspecialchars($row['computed_status'])) . "</span></td>";
        echo "<td>{$duration}</td>";
        echo "<td>{$ratingDisplay}</td>";
        echo "<td>";

        // Corrected button display logic
        if ($row['computed_status'] === 'pending' && isset($row['assigned_staff_id']) && $row['assigned_staff_id'] == $user_id) {
            echo "<form method='POST' style='display:inline;'>
                    <input type='hidden' name='ticket_id' value='{$row['ticket_id']}'>
                    <button type='submit' name='ticket_action' value='accept' class='confirm-btn'>Accept</button>
                    <button type='submit' name='ticket_action' value='cancel' class='cancel-btn'>Cancel</button>
                  </form>";
        } elseif ($row['computed_status'] === 'reopened') {
            echo "<button type='button' class='reopen-btn' data-ticket-id='{$row['ticket_id']}'>Reopen</button>";
        } else {
            echo "N/A";
        }

        echo "</td>";
        echo "</tr>";
    }
} else {
    echo "<tr><td colspan='8'>No tickets found.</td></tr>";
}

$stmt->close();
$conn->close();
?>
