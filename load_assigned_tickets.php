<?php
session_start();

// 🔒 AUTH CHECK
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['support_staff', 'manager_head'])) {
    exit('Access denied');
}

// 🧩 DB CONNECTION
$conn = new mysqli("localhost", "u248040635_cedy", "(April_23_2004)!!!!", "u248040635_ticketsystem");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

$manager_id = intval($_SESSION['user_id']);
$limit = 10;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $limit;

// ⏱️ FUNCTION: FORMAT DURATION
function formatDuration($start, $end) {
    if (!$end) return '-';
    $start_ts = strtotime($start);
    $end_ts = strtotime($end);
    if ($end_ts < $start_ts) return '-';
    $diff = $end_ts - $start_ts;
    $hours = floor($diff / 3600);
    $minutes = floor(($diff % 3600) / 60);
    return $hours > 0 ? "{$hours}h {$minutes}m" : "{$minutes}m";
}

// ------------------------------
// 🎫 FETCH ASSIGNED TICKETS (with reopen tracking)
// ------------------------------
$query = "
    SELECT 
        t.ticket_id, 
        t.control_number, 
        d.department_name, 
        us.name AS sender_name, 
        t.title, 
        t.issue, 
        t.status,
        t.created_at, 
        t.ended_at,
        t.accepted_at,
        t.original_assigned_staff_id,
        t.assigned_staff_id,
        tf.rating, 
        tf.comments, 
        tf.reason_title,
        CASE 
    WHEN t.status = 'pending'
         AND t.original_assigned_staff_id IS NOT NULL
    THEN 'reopened'
    ELSE t.status
END AS computed_status
    FROM tickets t
    JOIN departments d ON t.department_id = d.department_id
    JOIN users us ON t.user_id = us.user_id
    LEFT JOIN ticket_feedback tf ON tf.ticket_id = t.ticket_id
    WHERE t.manager_id = ?
    ORDER BY t.created_at DESC
    LIMIT ? OFFSET ?
";

$stmt = $conn->prepare($query);
$stmt->bind_param('iii', $manager_id, $limit, $offset);
$stmt->execute();
$result = $stmt->get_result();

// ------------------------------
// 🧾 RENDER TICKETS
// ------------------------------
if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $status = $row['computed_status'];
        $statusClass = "status-" . str_replace(" ", "_", strtolower($status));
        $durationText = formatDuration($row['created_at'], $row['ended_at']);
        $start = htmlspecialchars($row['created_at']);
        $end = htmlspecialchars($row['ended_at'] ?? 'N/A');
        $display_id = !empty($row['control_number']) ? $row['control_number'] : intval($row['ticket_id']);

        echo "<tr>";
        echo "<td>" . htmlspecialchars($display_id) . "</td>";
        echo "<td>" . htmlspecialchars($row['department_name']) . "</td>";
        echo "<td>" . htmlspecialchars($row['sender_name']) . "</td>";
        echo "<td><details><summary>" . htmlspecialchars($row['title']) . "</summary>
              <div class='issue-box'>" . nl2br(htmlspecialchars($row['issue'])) . "</div></details></td>";
        echo "<td><span class='status-pill {$statusClass}'>" . ucfirst(htmlspecialchars($status)) . "</span></td>";

        echo "<td>";
        echo "<div class='tooltip'>{$durationText}";
        if ($durationText !== '-') {
            echo "<button class='duration-btn' tabindex='0'>⏱️<span class='tooltiptext'>Start: {$start}<br>End: {$end}</span></button>";
        }
        echo "</div></td>";

        // ⭐ FEEDBACK SECTION
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

        // 🎯 ACTION BUTTONS (depends on computed status)
        echo "<td>";
        if ($status === 'pending') {
                        echo "<form method='POST' style='display:inline;' onsubmit='return confirmAccept(event,this);'>
                    <input type='hidden' name='ticket_id' value='" . intval($row['ticket_id']) . "'>
                    <button type='submit' name='ticket_action' value='accept' class='action-btn accept-btn'>Accept</button>
                  </form>";
            echo "<form method='POST' style='display:inline;' onsubmit='return confirmCancel(event,this);'>
                    <input type='hidden' name='ticket_id' value='" . intval($row['ticket_id']) . "'>
                    <button type='submit' name='ticket_action' value='cancel' class='action-btn cancel-btn'>Decline</button>
                  </form>";

        } elseif ($status === 'reopened') {
            echo "<form method='POST' style='display:inline;' onsubmit='return confirmReassign(event,this);'>
                    <input type='hidden' name='ticket_id' value='" . intval($row['ticket_id']) . "'>
                    <button type='submit' name='ticket_action' value='reassign_same_staff' class='action-btn reassign-btn'>Reassign to Original Staff</button>
                    <button type='submit' name='ticket_action' value='reopen_direct' class='action-btn reopen-btn'>Reopen Directly</button>
                  </form>";
        } else {
            echo "-";
        }
        echo "</td>";

        echo "</tr>";
    }
} else {
    echo "<tr><td colspan='9'>No assigned tickets.</td></tr>";
}

// ------------------------------
// 📄 PAGINATION
// ------------------------------
$total_stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM tickets WHERE manager_id = ?");
$total_stmt->bind_param('i', $manager_id);
$total_stmt->execute();
$total_count = $total_stmt->get_result()->fetch_assoc()['cnt'];
$total_stmt->close();

$total_pages = ceil($total_count / $limit);

if ($total_pages > 1) {
    echo "<tr><td colspan='8' style='text-align:center; padding:10px; background:#f9f9f9;'>";
    if ($page > 1) echo "<button class='page-btn assigned' data-page='" . ($page - 1) . "'>Prev</button> ";
    for ($p = 1; $p <= $total_pages; $p++) {
        $active = ($p == $page) ? "font-weight:bold; background:#ddd;" : "";
        echo "<button class='page-btn assigned' data-page='$p' style='margin:2px; padding:5px 10px; border:1px solid #ccc; background:white; cursor:pointer; {$active}'>$p</button> ";
    }
    if ($page < $total_pages) echo "<button class='page-btn assigned' data-page='" . ($page + 1) . "'>Next</button>";
    echo "</td></tr>";
}

$stmt->close();
$conn->close();
?>
