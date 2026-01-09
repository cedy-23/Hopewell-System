<?php
session_start();
$conn = new mysqli("localhost", "u248040635_cedy", "(April_23_2004)!!!!", "u248040635_ticketsystem");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit();
}


// Pagination
$limit = 10;
$page  = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $limit;

// Total tickets
$total_result = $conn->query("SELECT COUNT(*) AS total FROM tickets");
$total_tickets = $total_result->fetch_assoc()['total'];
$total_pages = ceil($total_tickets / $limit);

// Fetch tickets for current page
$sql = "SELECT t.ticket_id, t.control_number, t.title, t.description, t.priority, t.status, t.created_at, 
               u.name AS created_by, d.department_name 
        FROM tickets t
        JOIN users u ON t.user_id = u.user_id
        JOIN departments d ON t.department_id = d.department_id
        ORDER BY t.created_at DESC
        LIMIT $limit OFFSET $offset";
$result = $conn->query($sql);

// If AJAX request, return only the table
if (isset($_GET['ajax']) && $_GET['ajax'] == 1) {
    ?>
    <table>
        <tr>
            <th>Control Number</th><th>Title</th><th>Description</th><th>Priority</th>
            <th>Status</th><th>Department</th><th>Created By</th><th>Created At</th>
        </tr>
        <?php if ($result->num_rows > 0): ?>
            <?php while ($row = $result->fetch_assoc()): ?>
                <tr>
                    <td><?php echo $row['control_number']; ?></td>
                    <td><?php echo htmlspecialchars($row['title']); ?></td>
                    <td><?php echo htmlspecialchars($row['description']); ?></td>
                    <td><?php echo ucfirst($row['priority']); ?></td>
                    <td><?php echo ucfirst($row['status']); ?></td>
                    <td><?php echo htmlspecialchars($row['department_name']); ?></td>
                    <td><?php echo htmlspecialchars($row['created_by']); ?></td>
                    <td><?php echo $row['created_at']; ?></td>
                </tr>
            <?php endwhile; ?>
        <?php else: ?>
            <tr><td colspan="8">No tickets found.</td></tr>
        <?php endif; ?>
    </table>
    <?php
    exit;
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>All Tickets - Admin</title>
    <style>
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #333; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        .pagination { margin-top:10px; display:flex; gap:6px; flex-wrap:wrap; }
        .pagination a { padding:6px 10px; text-decoration:none; border-radius:4px; }
        .pagination a.active { background:#666; color:white; }
        .pagination a:hover { background:#007bff; color:white; }
    </style>
</head>
<body>
    <h2>All Tickets</h2>
    <a href="admin_dashboard.php">⬅ Back to Dashboard</a>
    <br><br>

    <div id="tickets-container">
        <?php include('view_tickets.php?ajax=1'); ?>
    </div>

    <div class="pagination">
        <?php for($p=1; $p<=$total_pages; $p++): ?>
            <a href="#" class="<?php echo ($p==$page) ? 'active' : ''; ?>" onclick="loadPage(<?php echo $p; ?>);return false;"><?php echo $p; ?></a>
        <?php endfor; ?>
    </div>

    <script>
        function loadPage(page) {
            fetch('view_tickets.php?ajax=1&page=' + page)
                .then(res => res.text())
                .then(html => {
                    document.getElementById('tickets-container').innerHTML = html;
                    // Update active class
                    document.querySelectorAll('.pagination a').forEach(a => a.classList.remove('active'));
                    document.querySelector('.pagination a:nth-child(' + page + ')').classList.add('active');
                })
                .catch(err => console.error(err));
        }
    </script>
</body>
</html>
