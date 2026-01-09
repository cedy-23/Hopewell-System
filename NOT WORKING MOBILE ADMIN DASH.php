<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit();
}

$conn = new mysqli("localhost", "u248040635_cedy", "(April_23_2004)!!!!", "u248040635_ticketsystem");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

require 'send_mail.php';

// Fetch pending count for sidebar
$pending_count = $conn->query("SELECT COUNT(*) AS total FROM users WHERE status = 'pending'")->fetch_assoc()['total'];

// Helper function to format duration like manager dashboard
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

// -------------------- Handle ticket deletion --------------------
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['delete_ticket_id'])) {
    $ticket_id = intval($_POST['delete_ticket_id']);
    $stmt = $conn->prepare("DELETE FROM tickets WHERE ticket_id = ?");
    $stmt->bind_param('i', $ticket_id);
    $stmt->execute();
    $stmt->close();
    header("Location: admin_dashboard.php");
    exit();
}

// -------------------- Handle user deletion --------------------
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['delete_user_id'])) {
    $user_id = intval($_POST['delete_user_id']);
    $stmt = $conn->prepare("DELETE FROM users WHERE user_id = ?");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $stmt->close();
    header("Location: admin_dashboard.php?page=users");
    exit();
}

// -------------------- Handle user role and position update --------------------
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['edit_user_id']) && !isset($_POST['delete_user_id'])) {
    $user_id = intval($_POST['edit_user_id']);
    $new_role = $_POST['new_role'] ?? '';
    $new_position = $_POST['new_position'] ?? '';
    $new_department_id = isset($_POST['new_department_id']) ? intval($_POST['new_department_id']) : null;

    // Change 'user' role to 'staff'
    if ($new_role === 'user') {
        $new_role = 'staff';
    }

    if (in_array($new_role, ['admin', 'manager_head', 'manager', 'staff'])) {
        $stmt = $conn->prepare("UPDATE users SET role = ?, position = ?, department_id = ? WHERE user_id = ?");
        $stmt->bind_param('ssii', $new_role, $new_position, $new_department_id, $user_id);
        $stmt->execute();
        $stmt->close();
    }
    header("Location: admin_dashboard.php?page=users");
    exit();
}

// -------------------- Handle add department --------------------
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['add_department'])) {
    $dept_name = trim($_POST['department_name'] ?? '');
    if ($dept_name !== '') {
        $stmt = $conn->prepare("INSERT INTO departments (department_name) VALUES (?)");
        $stmt->bind_param('s', $dept_name);
        $stmt->execute();
        $stmt->close();
    }
    header("Location: admin_dashboard.php?page=departments");
    exit();
}

// -------------------- Handle delete department --------------------
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['delete_department_id'])) {
    $dept_id = intval($_POST['delete_department_id']);
    // Check if any users belong to this department
    $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM users WHERE department_id = ?");
    $stmt->bind_param('i', $dept_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $count = $res->fetch_assoc()['cnt'] ?? 0;
    $stmt->close();

    if ($count > 0) {
        $error = "Cannot delete department because there are still employees assigned to it.";
    } else {
        $stmt = $conn->prepare("DELETE FROM departments WHERE department_id = ?");
        $stmt->bind_param('i', $dept_id);
        $stmt->execute();
        $stmt->close();
        header("Location: admin_dashboard.php?page=departments");
        exit();
    }
}

// -------------------- Handle approve/reject actions for pending users --------------------
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['action'], $_POST['user_id']) && !isset($_POST['edit_user_id']) && !isset($_POST['delete_user_id'])) {
    $user_id = intval($_POST['user_id']);
    $action = $_POST['action'];

    // Fetch user details for email
    $stmt = $conn->prepare("SELECT name, email FROM users WHERE user_id = ? AND status = 'pending'");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $name = $row['name'];
        $email = $row['email'];

        if ($action === "approve") {
            // Update status to active
            $stmt_update = $conn->prepare("UPDATE users SET status = 'active' WHERE user_id = ?");
            $stmt_update->bind_param('i', $user_id);
            $success = $stmt_update->execute();
            $stmt_update->close();

            if ($success) {
                // Send approval email
                sendMail($email, "Account Approved - MIS Ticketing System",
                    "<h2>Account Approved</h2>
                    <p>Hello <b>$name</b>,</p>
                    <p>Your account has been approved by the admin. You can now log in to the MIS Ticketing System.</p>
                    <p><a href='http://localhost/ticketing_system/index.php'>Go to Login</a></p>
                    <br><p>– MIS Helpdesk</p>");
                $_SESSION['success'] = "User approved successfully. Email notification sent.";
            }
        } elseif ($action === "declined") {
            // Send rejection email first
            sendMail($email, "Account Rejected - MIS Ticketing System",
                "<h2>Account Declined</h2>
                <p>Hello <b>$name</b>,</p>
                <p>Your registration was not approved by the admin. Please contact MIS if you believe this is a mistake.</p>
                <br><p>– MIS Helpdesk</p>");

            // Then delete user
            $stmt_delete = $conn->prepare("DELETE FROM users WHERE user_id = ?");
            $stmt_delete->bind_param('i', $user_id);
            $stmt_delete->execute();
            $stmt_delete->close();
            $_SESSION['success'] = "User declined and email notification sent.";
        }
    }
    $stmt->close();

    header("Location: admin_dashboard.php?page=users");
    exit();
}

// -------------------- Handle search --------------------
$search = "";
if (isset($_GET['search'])) {
    $search = $conn->real_escape_string($_GET['search']);
}

// Fetch departments for dropdowns
$departments = $conn->query("SELECT * FROM departments");

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Dashboard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        /* ----------------- Base / Desktop Layout (updated colors) ----------------- */
        body { margin: 0; font-family: Arial, sans-serif; display: flex; height: 100vh; }
        .sidebar { width: 25%; background: #034c07; color: white; display: flex; flex-direction: column; justify-content: space-between; padding: 20px; box-sizing: border-box; }
        .sidebar h2 { margin-bottom: 30px; text-align: center; }
        .pending-count { text-align: center; font-size: 18px; color: #ffd700; margin-bottom: 30px; }
        .menu { flex-grow: 1; }
        .menu button { width: 100%; padding: 15px; margin: 10px 0; background: #048c28; border: none; color: white; border-radius: 6px; cursor: pointer; font-size: 16px; }
        .menu button:hover, .menu button.active { background: #177931ff; }
        .summary-cards { display: flex; gap: 20px; margin-bottom: 20px; }
        .summary-card { flex: 1; background: white; padding: 20px; border-radius: 8px; text-align: center; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .summary-card h3 { margin: 0 0 10px 0; color: #333; }
        .summary-card p { font-size: 24px; font-weight: bold; color: #007bff; margin: 0; }
        .logout-btn { width: 100%; padding: 15px; background: #ff4d4d; border: none; color: white; border-radius: 6px; cursor: pointer; font-size: 16px; font-weight: bold; }
        .logout-btn:hover { background: #cc0000; }
        .content { flex-grow: 1; padding: 20px; background: #f4f4f4; overflow-y: auto; box-sizing: border-box; }

        table { width: 100%; border-collapse: collapse; margin-top: 20px; background: white; }
        th, td { padding: 12px; border: 1px solid #ddd; text-align: left; vertical-align: top; word-wrap: break-word; }
        th { background: #034c07; color: white; }

        .search-bar { margin-bottom: 15px; }
        .search-bar input[type="text"] { padding: 8px; width: 305px; }
        .search-bar button { padding: 8px 12px; margin-left: 5px; }
        .action-btn { padding: 6px 12px; border: none; border-radius: 4px; cursor: pointer; }
        .approve { background: #28a745; color: white; }
        .declined { background: #dc3545; color: white; }
        .delete-btn { background: #dc3545; color: white; }
        .edit-btn { background: #007bff; color: white; }
        select, input[type=text] { padding: 6px; border-radius: 4px; border: 1px solid #ccc; width: 100%; box-sizing: border-box; }
        form.inline-form { display: inline-block; margin: 0; }
        .duration-cell { white-space: nowrap; }

        /* Tooltip and modal styles (updated colors) */
        .duration-btn {
            font-size: 10px;
            margin-left: 6px;
            padding: 2px 6px;
            cursor: pointer;
            border-radius: 4px;
            border: 1px solid #007BFF;
            background: #e7f1ff;
            color: #007BFF;
            transition: background-color 0.3s ease;
        }
        .duration-btn:hover { background-color: #cce4ff; }

        .tooltip { position: relative; display: inline-block; }
        .tooltip .tooltiptext {
            visibility: hidden;
            width: 180px;
            background-color: #034c07;
            color: #fff;
            text-align: left;
            border-radius: 6px;
            padding: 8px;
            position: absolute;
            z-index: 1;
            bottom: 125%;
            left: 50%;
            margin-left: -90px;
            opacity: 0;
            transition: opacity 0.3s;
            font-size: 12px;
            white-space: nowrap;
        }
        .tooltip:hover .tooltiptext { visibility: visible; opacity: 1; }

        /* ----------------- Mobile: slide-in sidebar & header additions ----------------- */
        .mobile-header {
            display: none;
            position: fixed;
            top: 8px;
            left: 8px;
            z-index: 1101;
            background: transparent;
            border: none;
            padding: 6px;
        }
        .toggle-btn {
            width: 34px;
            height: 26px;
            display: inline-flex;
            flex-direction: column;
            justify-content: center;
            gap: 6px;
            background: transparent;
            border: none;
            cursor: pointer;
        }
        .toggle-btn .line {
            height: 3px;
            background: #034c07;
            border-radius: 3px;
            display: block;
            width: 100%;
        }

        .mobile-sidebar {
            position: fixed;
            top: 0;
            left: -280px;
            width: 260px;
            height: 100%;
            background: #034c07;
            color: white;
            display: flex;
            flex-direction: column;
            padding: 20px;
            box-sizing: border-box;
            transition: left 320ms cubic-bezier(.2,.9,.2,1);
            z-index: 1100;
            overflow-y: auto;
        }
        .mobile-sidebar.open { left: 0; }

        .mobile-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.45);
            display: none;
            z-index: 1050;
        }
        .mobile-overlay.show { display: block; }

        .mobile-sidebar .menu { display:flex; flex-direction:column; gap:10px; margin-top: 6px; }
        .mobile-sidebar .menu button {
            background: #048c28;
            border: none;
            color: #fff;
            padding: 12px;
            border-radius: 8px;
            text-align: left;
            font-size: 15px;
            cursor: pointer;
            transform: translateX(-12px);
            opacity: 0;
        }
        .mobile-sidebar .logout-btn { background: #ff4d4d; border-radius:8px; padding:12px; margin-top: auto; }

        .mobile-sidebar.open .menu button {
            transform: translateX(0);
            opacity: 1;
            transition: transform 320ms ease, opacity 320ms ease;
        }
        .mobile-sidebar.open .menu button:nth-child(1) { transition-delay: 0ms; }
        .mobile-sidebar.open .menu button:nth-child(2) { transition-delay: 60ms; }
        .mobile-sidebar.open .menu button:nth-child(3) { transition-delay: 120ms; }
        .mobile-sidebar.open .menu button:nth-child(4) { transition-delay: 180ms; }

        @media (max-width: 768px) {
            .mobile-header { display: block; }
            .sidebar { display: none; }
            .content { padding: 18px 12px; }
            table, th, td { font-size: 13px; }
            .toggle-btn .line { background: #fff; }
            html, body { overflow-x: hidden; }
        }
    </style>
</head>
<body>

    <div class="mobile-header">
        <button class="toggle-btn" aria-label="Open menu" id="mobileToggle" onclick="toggleMobileSidebar()">
            <span class="line"></span>
            <span class="line"></span>
        </button>
    </div>

    <div class="sidebar">
        <div>
            <h2>Admin Dashboard</h2>
            <div class="pending-count">Pending Users: <?php echo $pending_count; ?></div>
            <div class="menu">
                <button onclick="window.location.href='admin_dashboard.php'">Ticket History</button>
                <button onclick="window.location.href='admin_dashboard.php?page=users'">Staff Information</button>
                <button onclick="window.location.href='admin_dashboard.php?page=departments'">Manage Departments</button>
            </div>
        </div>
        <button class="logout-btn" onclick="return confirm('Are you sure you want to logout?') ? window.location.href='logout.php' : false;">Logout</button>
    </div>

    <div class="mobile-sidebar" id="mobileSidebar">
        <div style="display:flex; align-items:center; gap:10px; margin-bottom:6px;">
            <div style="width:44px; height:44px; background:#fff; border-radius:8px;"></div>
            <div>
                <div style="font-weight:bold; font-size:16px;">Admin Dashboard</div>
                <div style="font-size:12px; color:rgba(255,255,255,0.8); margin-top:2px;">Menu</div>
            </div>
        </div>
        <div class="menu">
    <button onclick="window.location.href='admin_dashboard.php'">Ticket History</button>
    <button onclick="window.location.href='admin_dashboard.php?page=users'">Staff Information</button>
    <button onclick="window.location.href='admin_dashboard.php?page=departments'">Manage Departments</button>
</div>
<button class="logout-btn" onclick="if(confirm('Are you sure you want to logout?')) window.location.href='logout.php';">Logout</button>
</div>

<div class="mobile-overlay" id="mobileOverlay" onclick="toggleMobileSidebar()"></div>

<div class="content" id="content-area">
    <?php if (!isset($_GET['page']) || $_GET['page'] === ''): ?>

         <?php
            // Ticket Summary Cards
            $total_tickets = $conn->query("SELECT COUNT(*) as total FROM tickets")->fetch_assoc()['total'];
            $open_tickets = $conn->query("SELECT COUNT(*) as total FROM tickets WHERE status = 'open'")->fetch_assoc()['total'];
            $pending_tickets = $conn->query("SELECT COUNT(*) as total FROM tickets WHERE status = 'pending'")->fetch_assoc()['total'];
            ?>
            <div class="summary-cards">
                <div class="summary-card">
                    <h3>Total Tickets</h3>
                    <p><?php echo $total_tickets; ?></p>
                </div>
                <div class="summary-card">
                    <h3>Open</h3>
                    <p><?php echo $open_tickets; ?></p>
                </div>
                <div class="summary-card">
                    <h3>Pending</h3>
                    <p><?php echo $pending_tickets; ?></p>
                </div>
            </div>

            <h2>Ticket History</h2>

            <div class="search-bar">
                <form method="GET">
                    <input type="text" name="search" placeholder="Search by Department, Staff, or Control Number" value="<?php echo htmlspecialchars($search); ?>">
                    <button type="submit">Search</button>
                    <button type="button" onclick="window.location.href='admin_dashboard.php'">Refresh</button>
                </form>
            </div>

        <?php
        // Fetch tickets with duration
        $search_sql = "";
        if ($search !== "") {
            $search_esc = $conn->real_escape_string($search);
            $search_sql = "WHERE d.department_name LIKE '%$search_esc%' OR u.name LIKE '%$search_esc%' OR t.control_number LIKE '%$search_esc%'";
        }
        $tickets_sql = "
            SELECT t.ticket_id, t.control_number, u.name AS user_name, d.department_name, t.title, t.priority, t.status, t.created_at, t.ended_at
            FROM tickets t
            JOIN users u ON t.user_id = u.user_id
            JOIN departments d ON t.department_id = d.department_id
            $search_sql
            ORDER BY t.created_at DESC
        ";
        $tickets = $conn->query($tickets_sql);
        ?>

        <table>
            <tr>
                <th>Control No.</th>
                <th>Sender</th>
                <th>Department</th>
                <th>Title</th>
                <th>Status</th>
                <th>Duration</th>
                <th>Actions</th>
            </tr>
            <?php if ($tickets && $tickets->num_rows > 0): ?>
                <?php while($row = $tickets->fetch_assoc()): 
                    $durationText = formatDuration($row['created_at'], $row['ended_at']);
                    $start = htmlspecialchars($row['created_at']);
                    $end = htmlspecialchars($row['ended_at'] ?? 'N/A');
                ?>
                    <tr>
                        <td><?php echo $row['control_number']; ?></td>
                        <td><?php echo htmlspecialchars($row['user_name']); ?></td>
                        <td><?php echo htmlspecialchars($row['department_name']); ?></td>
                        <td><?php echo htmlspecialchars($row['title']); ?></td>
                        <td><?php echo ucfirst(htmlspecialchars($row['status'])); ?></td>
                        <td class="duration-cell">
                            <div class="tooltip">
                                <?php echo $durationText; ?>
                                <?php if ($durationText !== '-'): ?>
                                <button class="duration-btn" tabindex="0">⏱️
                                    <span class="tooltiptext">
                                        Start: <?php echo $start; ?><br>
                                        End: <?php echo $end; ?>
                                    </span>
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <form method="POST" class="inline-form" onsubmit="return confirm('Are you sure you want to delete this ticket?');">
                                <input type="hidden" name="delete_ticket_id" value="<?php echo $row['ticket_id']; ?>">
                                <button type="submit" class="action-btn delete-btn">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="7">No tickets found.</td></tr>
            <?php endif; ?>
        </table>
    <?php elseif ($_GET['page'] === 'users'): ?>
        <div class="content">
            <?php 
            // Display success message if set
            if (isset($_SESSION['success'])) {
                echo '<div class="success-msg">' . htmlspecialchars($_SESSION['success']) . '</div>';
                unset($_SESSION['success']);
            }
            ?>

            <h2>Pending Accounts (<?php echo $pending_count; ?>)</h2>
            <table>
                <tr><th>Name</th><th>Position</th><th>Email</th><th>Role</th><th>Department</th><th>Actions</th></tr>
                <?php 
                $pending_users = $conn->query("SELECT u.user_id, u.name, u.email, u.position, u.role, d.department_name 
                                            FROM users u 
                                            LEFT JOIN departments d ON u.department_id = d.department_id 
                                            WHERE u.status='pending'
                                            ORDER BY u.user_id DESC");
                if ($pending_users && $pending_users->num_rows > 0) {
                    while($row = $pending_users->fetch_assoc()) { ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['name']); ?></td>
                            <td><?php echo htmlspecialchars($row['position']); ?></td>
                            <td><?php echo htmlspecialchars($row['email']); ?></td>
                            <td><?php echo ucfirst(htmlspecialchars($row['role'])); ?></td>
                            <td><?php echo htmlspecialchars($row['department_name'] ?? 'N/A'); ?></td>
                            <td>
                                <form method="POST" class="inline-form" style="display: inline;">
                                    <input type="hidden" name="user_id" value="<?php echo $row['user_id']; ?>">
                                    <button type="submit" name="action" value="approve" class="action-btn approve" onclick="return confirm('Approve this user?')">Approve</button>
                                    <button type="submit" name="action" value="reject" class="action-btn reject" onclick="return confirm('Decline this user?')">Decline</button>
                                </form>
                            </td>
                        </tr>
                    <?php } 
                } else { ?>
                    <tr><td colspan="6">No pending accounts.</td></tr>
                <?php } ?>
            </table>

            <h2>Active Accounts</h2>
            <table>
                <tr><th>Name</th><th>Email</th><th>Role</th><th>Position</th><th>Department</th><th>Edit</th></tr>
                <?php 
                $active_users = $conn->query("SELECT u.*, d.department_name, d.department_id
                                            FROM users u 
                                            LEFT JOIN departments d ON u.department_id = d.department_id 
                                            WHERE u.status='active'");
                // Prepare users data for JS
                $users_js_data = [];
                if ($active_users && $active_users->num_rows > 0) {
                    while($row = $active_users->fetch_assoc()) {
                        $users_js_data[$row['user_id']] = [
                            'role' => $row['role'],
                            'position' => $row['position'],
                            'department_id' => $row['department_id'] ?? '',
                            'name' => $row['name'],
                            'email' => $row['email'],
                        ];
                    }
                    $active_users->data_seek(0); // Reset pointer for display
                }
                while($row = $active_users->fetch_assoc()) { ?>
                    <tr>
                        <td><?php echo htmlspecialchars($row['name']); ?></td>
                        <td><?php echo htmlspecialchars($row['email']); ?></td>
                        <td><?php echo ucfirst(htmlspecialchars($row['role'])); ?></td>
                        <td><?php echo htmlspecialchars($row['position']); ?></td>
                        <td><?php echo htmlspecialchars($row['department_name'] ?? 'N/A'); ?></td>
                        <td>
                            <button onclick="showEditModal(<?php echo $row['user_id']; ?>)" class="action-btn edit-btn">Edit</button>
                        </td>
                    </tr>
                <?php } 
                if (empty($users_js_data)) { ?>
                    <tr><td colspan="7">No active accounts.</td></tr>
                <?php } ?>
            </table>
        </div>
    <?php elseif ($_GET['page'] === 'departments'): ?>
        <div class="content">
            <h2>Manage Departments</h2>
            <?php if (!empty($error)): ?>
                <p style="color:red; font-weight:bold;"><?php echo htmlspecialchars($error); ?></p>
            <?php endif; ?>
            <form method="POST" style="margin-bottom:20px; max-width:400px;">
                <label for="department_name">Add New Department:</label><br>
                <input type="text" name="department_name" id="department_name" required placeholder="Department Name" style="width:100%; padding:8px; margin-top:5px; margin-bottom:10px;">
                <button type="submit" name="add_department" class="action-btn approve">Add Department</button>
            </form>

            <table>
                <tr><th>Department Name</th><th>Action</th></tr>
                <?php
                $departments_list = $conn->query("SELECT * FROM departments ORDER BY department_name ASC");
                if ($departments_list && $departments_list->num_rows > 0) {
                    while($dept = $departments_list->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($dept['department_name']); ?></td>
                            <td>
                                <form method="POST" class="inline-form" onsubmit="return confirm('Are you sure you want to delete this department?');">
                                    <input type="hidden" name="delete_department_id" value="<?php echo $dept['department_id']; ?>">
                                    <button type="submit" class="action-btn delete-btn">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endwhile; 
                } else { ?>
                    <tr><td colspan="2">No departments found.</td></tr>
                <?php } ?>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Edit User Modal -->
    <div id="editUserModal" style="display:none; position:fixed; z-index:1000; left:0; top:0; width:100%; height:100%; overflow:auto; background-color:rgba(0,0,0,0.5);">
        <div class="modal-content" style="background:#fff; margin:10% auto; padding:20px; border-radius:8px; width:400px; box-shadow:0 0 10px rgba(0,0,0,0.25); position:relative;">
            <h3>Edit Staff</h3>
        <form method="POST" id="userEditForm">
            <input type="hidden" name="edit_user_id" id="edit_user_id" value="">
            <label for="new_role">Role:</label>
            <select name="new_role" id="new_role" required>
                <option value="staff">Staff</option>
                <option value="manager">Support</option>
                <option value="manager_head">Manager Head</option>
                <option value="admin">Admin</option>
            </select>
            <br><br>
            <label for="new_position">Position:</label>
            <input type="text" name="new_position" id="new_position" placeholder="Position">
            <br><br>
            <label for="new_department_id">Department:</label>
            <select name="new_department_id" id="new_department_id" required>
                <option value="">-- Select Department --</option>
                <?php
                // Re-fetch departments for modal dropdown
                $departments_modal = $conn->query("SELECT * FROM departments ORDER BY department_name ASC");
                while($dept_modal = $departments_modal->fetch_assoc()):
                ?>
                    <option value="<?php echo $dept_modal['department_id']; ?>"><?php echo htmlspecialchars($dept_modal['department_name']); ?></option>
                <?php endwhile; ?>
            </select>
            <br><br>
            <div class="modal-buttons" style="margin-top:20px;text-align:center;">
                    <button type="submit" class="save-btn" style="background:#28a745;color:#fff;border:none;padding:10px 20px;border-radius:6px;cursor:pointer;">Save</button>
                    <button type="button" class="cancel-btn" style="background:#6c757d;color:#fff;border:none;padding:10px 20px;border-radius:6px;cursor:pointer;" onclick="hideEditModal()">Cancel</button>
                    <button type="button" class="delete-btn" style="background:#dc3545;color:#fff;border:none;padding:10px 20px;border-radius:6px;cursor:pointer;" onclick="confirmDeleteUser()">Delete</button>
                </div>
        </form>
    </div>
</div>

<script>
    const usersData = <?php echo json_encode($users_js_data); ?>;

    function showEditModal(userId) {
        const user = usersData[userId];
        if (!user) return;
        document.getElementById('edit_user_id').value = user.user_id;
        let role = user.role === 'user' ? 'staff' : user.role;
        document.getElementById('new_role').value = role;
        document.getElementById('new_position').value = user.position || '';
        document.getElementById('new_department_id').value = user.department_id || '';
        document.getElementById('editUserModal').style.display = 'block';
        document.body.style.overflow = 'hidden';
    }

    function hideEditModal() {
        document.getElementById('editUserModal').style.display = 'none';
        document.body.style.overflow = 'auto';
    }

    function confirmDeleteUser() {
        if (confirm('Are you sure you want to delete this staff member? This action cannot be undone.')) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'delete_user_id';
            input.value = document.getElementById('edit_user_id').value;
            form.appendChild(input);
            document.body.appendChild(form);
            form.submit();
        }
    }

    window.onclick = function(event) {
        const modal = document.getElementById('editUserModal');
        if (event.target === modal) hideEditModal();
    };

    function toggleMobileSidebar() {
        const sidebar = document.getElementById('mobileSidebar');
        const overlay = document.getElementById('mobileOverlay');
        if (sidebar.classList.contains('open')) {
            sidebar.classList.remove('open');
            overlay.classList.remove('show');
            sidebar.setAttribute('aria-hidden', 'true');
        } else {
            sidebar.classList.add('open');
            overlay.classList.add('show');
            sidebar.setAttribute('aria-hidden', 'false');
            setTimeout(() => {
                const firstBtn = sidebar.querySelector('.menu button');
                if (firstBtn) firstBtn.focus();
            }, 320);
        }
    }

    document.addEventListener('keydown', function(ev) {
        if (ev.key === 'Escape') {
            const sidebar = document.getElementById('mobileSidebar');
            const overlay = document.getElementById('mobileOverlay');
            if (sidebar && sidebar.classList.contains('open')) {
                sidebar.classList.remove('open');
                overlay.classList.remove('show');
                sidebar.setAttribute('aria-hidden', 'true');
            }
        }
    });
</script>

</body>
</html>
<?php $conn->close(); ?>