<?php
session_start();
$conn = new mysqli("localhost", "u248040635_cedy", "(April_23_2004)!!!!", "u248040635_ticketsystem");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'staff') {
    exit('Access denied');
}


$user_id = intval($_SESSION['user_id']);
$limit = 10;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $limit;

// Helper: format ticket duration
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

// Fetch tickets for current staff
$query = "
    SELECT 
        t.ticket_id, t.control_number, d.department_name, u.name AS sender_name, 
        t.title, t.issue, t.status, t.created_at, t.updated_at, 
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
    WHERE t.assigned_staff_id = ?
      AND NOT (t.status = 'pending' AND t.assigned_staff_id IS NULL)
    ORDER BY t.created_at DESC
    LIMIT ? OFFSET ?
";

$stmt = $conn->prepare($query);
$stmt->bind_param('iii', $user_id, $limit, $offset);
$stmt->execute();
$result = $stmt->get_result();

// Table rows
if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $statusClass = "status-" . str_replace(" ", "_", strtolower($row['computed_status']));
        $end_time = isset($row['updated_at']) ? $row['updated_at'] : null;
        $duration = formatDuration($row['created_at'], $end_time);

        // Rating Display
        if (!empty($row['rating'])) {
            $ratingClass = "rating-" . strtolower($row['rating']);
            $ratingDisplay = "<span class='rating-badge {$ratingClass}'>{$row['rating']}</span>";
            if (!empty($row['reason'])) {
                $ratingDisplay .= "<div class='reason-title'>{$row['reason']}</div>";
            }
        } else {
            $ratingDisplay = "N/A";
        }

        echo "<tr>";
        echo "<td>" . htmlspecialchars($row['control_number']) . "</td>";
        echo "<td>" . htmlspecialchars($row['department_name']) . "</td>";
        echo "<td>" . htmlspecialchars($row['sender_name']) . "</td>";
        echo "<td><details><summary>" . htmlspecialchars($row['title']) . "</summary>
                <div class='issue-box'>" . nl2br(htmlspecialchars($row['issue'])) . "</div>
              </details></td>";
        echo "<td><span class='status-pill {$statusClass}'>" . ucfirst(htmlspecialchars($row['computed_status'])) . "</span></td>";
        echo "<td>{$duration}</td>";
        echo "<td>{$ratingDisplay}</td>";
        echo "<td>";

        // Only show Accept/Cancel for pending tickets assigned to this staff
        if ($row['computed_status'] === 'pending' && isset($row['assigned_staff_id']) && $row['assigned_staff_id'] == $user_id) {
            echo "<form method='POST' style='display:inline;'>
                    <input type='hidden' name='ticket_id' value='{$row['ticket_id']}'>
                    <button type='submit' name='ticket_action' value='accept' class='confirm-btn'>Accept</button>
                    <button type='submit' name='ticket_action' value='cancel' class='cancel-btn'>Cancel</button>
                  </form>";
        } else {
            echo "N/A";
        }

        echo "</td>";
        echo "</tr>";
    }
} else {
    echo "<tr><td colspan='8'>No assigned tickets.</td></tr>";
}

// Count total tickets for pagination
$query_total = "
    SELECT COUNT(*) as total 
    FROM tickets t
    WHERE t.assigned_staff_id = ?
      AND NOT (t.status = 'pending' AND t.assigned_staff_id IS NULL)
";
$stmt_total = $conn->prepare($query_total);
$stmt_total->bind_param('i', $user_id);
$stmt_total->execute();
$total_result = $stmt_total->get_result()->fetch_assoc();
$total_pages = ceil($total_result['total'] / $limit);

// Pagination
if ($total_pages > 1) {
    echo "<tr><td colspan='8' style='text-align:center; padding:10px;'>";
    if ($page > 1) echo "<button class='page-btn' data-page='" . ($page-1) . "'>Prev</button>";
    for ($p = 1; $p <= $total_pages; $p++) {
        $active = ($p == $page) ? "font-weight:bold;" : "";
        echo "<button class='page-btn' data-page='$p' style='margin:2px; $active'>$p</button>";
    }
    if ($page < $total_pages) echo "<button class='page-btn' data-page='" . ($page+1) . "'>Next</button>";
    echo "</td></tr>";
}

$stmt->close();
$stmt_total->close();
$conn->close();
?>
