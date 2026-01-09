<?php
session_start();
$conn = new mysqli("localhost", "u248040635_cedy", "(April_23_2004)!!!!", "u248040635_ticketsystem");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'support_staff') {
    exit('Access denied');
}

$manager_id = intval($_SESSION['user_id']);
$limit = 10;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $limit;

function formatDuration($start, $end) {
    if (!$end) return '-';
    $start_ts = strtotime($start);
    $end_ts = strtotime($end);
    if ($end_ts < $start_ts) return '-';
    $diff = $end_ts - $start_ts;
    $hours = floor($diff / 3600);
    $minutes = floor(($diff % 3600) / 60);
    if ($hours > 0) {
        return "{$hours}h {$minutes}m";
    } else {
        return "{$minutes}m";
    }
}

// Fetch tickets for this page
$my_tickets_stmt = $conn->prepare("
    SELECT t.ticket_id, t.control_number, d.department_name, u.name AS manager_name, t.title, t.issue, t.status, t.created_at, t.ended_at,
           tf.rating, tf.comments, tf.reason_title
    FROM tickets t
    JOIN departments d ON t.department_id = d.department_id
    JOIN users u ON t.manager_id = u.user_id
    LEFT JOIN ticket_feedback tf ON tf.ticket_id = t.ticket_id
    WHERE t.user_id = ?
    ORDER BY t.created_at DESC
    LIMIT ? OFFSET ?
");
$my_tickets_stmt->bind_param('iii', $manager_id, $limit, $offset);
$my_tickets_stmt->execute();
$my_tickets = $my_tickets_stmt->get_result();

// Generate table rows
if ($my_tickets->num_rows > 0) {
    while ($row = $my_tickets->fetch_assoc()) {
        $statusClass = "status-" . str_replace(" ", "_", strtolower($row['status']));
        $durationText = formatDuration($row['created_at'], $row['ended_at']);
        $start = htmlspecialchars($row['created_at']);
        $end = htmlspecialchars($row['ended_at'] ?? 'N/A');
        echo "<tr>";
        echo "<td>" . htmlspecialchars($row['control_number']) . "</td>";
        echo "<td>" . htmlspecialchars($row['department_name']) . "</td>";
        echo "<td>" . htmlspecialchars($row['manager_name']) . "</td>";
        echo "<td><details><summary>" . htmlspecialchars($row['title']) . "</summary>
              <div class='issue-box'>" . nl2br(htmlspecialchars($row['issue'])) . "</div></details></td>";
        echo "<td><span class='status-pill {$statusClass}'>" . ucfirst(htmlspecialchars($row['status'])) . "</span></td>";
        echo "<td>" . htmlspecialchars($row['created_at']) . "</td>";
        echo "<td>";
        echo "<div class='tooltip'>{$durationText}";
        if ($durationText !== '-') {
            echo "<button class='duration-btn' tabindex='0'>⏱️<span class='tooltiptext'>Start: {$start}<br>End: {$end}</span></button>";
        }
        echo "</div></td>";
        echo "<td>";
        if (!is_null($row['rating'])) {
            echo "<details><summary><span class='rating-badge'>" . intval($row['rating']) . " <span class='rating-stars'>★</span></span></summary>";
            if (!empty($row['reason_title'])) {
                echo "<div class='reason-title'><strong>" . htmlspecialchars($row['reason_title']) . "</strong></div>";
            }
            if (!empty($row['comments'])) {
                echo "<div class='issue-box'>" . nl2br(htmlspecialchars($row['comments'])) . "</div>";
            }
            echo "</details>";
        } else {
            echo "-";
        }
        echo "</td>";
        echo "<td>";
        if ($row['status'] === 'in_progress') {
            echo "<form method='POST'>
                    <input type='hidden' name='ticket_id' value='" . intval($row['ticket_id']) . "'>
                    <button type='submit' name='close_ticket' class='confirm-btn'>Close Ticket</button>
                  </form>";
        } else {
            echo "-";
        }
        echo "</td>";
        echo "</tr>";
    }
} else {
    echo "<tr><td colspan='9'>No tickets submitted.</td></tr>";
}

// Fetch total pages
$total_my_stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM tickets WHERE user_id = ?");
$total_my_stmt->bind_param('i', $manager_id);
$total_my_stmt->execute();
$total_my_count = $total_my_stmt->get_result()->fetch_assoc()['cnt'];
$total_my_stmt->close();
$total_pages = ceil($total_my_count / $limit);

// Pagination links (as a footer row)
if ($total_pages > 1) {
    echo "<tr><td colspan='9' style='text-align:center; padding:10px; background:#f9f9f9;'>";
    if ($page > 1) echo "<button class='page-btn my' data-page='" . ($page-1) . "'>Prev</button> ";
    for ($p = 1; $p <= $total_pages; $p++) {
        $active = ($p == $page) ? "font-weight:bold; background:#ddd;" : "";
        echo "<button class='page-btn my' data-page='$p' style='margin:2px; padding:5px 10px; border:1px solid #ccc; background:white; cursor:pointer; {$active}'>$p</button> ";
    }
    if ($page < $total_pages) echo "<button class='page-btn my' data-page='" . ($page+1) . "'>Next</button>";
    echo "</td></tr>";
}

$my_tickets_stmt->close();
?>
