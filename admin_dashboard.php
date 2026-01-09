<?php
session_start();


$conn = new mysqli("localhost", "u248040635_cedy", "(April_23_2004)!!!!", "u248040635_ticketsystem");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit();
}

$success = '';
$error   = '';
$warning = '';

require 'send_mail.php';
require 'send_credentials.php';


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
if (!empty($_SESSION['success'])) {
    $success = $_SESSION['success'];
    unset($_SESSION['success']);
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['delete_user_id'])) {
    $user_id = intval($_POST['delete_user_id']);

    $stmt = $conn->prepare("DELETE FROM users WHERE user_id = ?");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $stmt->close();

    $_SESSION['success'] = "User deleted successfully.";

    header("Location: admin_dashboard.php?page=users");
    exit();
}



// -------------------- Handle user role and position update --------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['edit_user_id'])
    && !isset($_POST['delete_user_id'])) {

    $user_id = intval($_POST['edit_user_id']);
    $name    = trim($_POST['new_name']);
    $email   = trim($_POST['new_email']);
    $role    = $_POST['new_role'];
    $pos     = trim($_POST['new_position']);
    $dept    = intval($_POST['new_department_id']);

    if ($role === 'user') {
        $role = 'staff';
    }

    if (in_array($role, ['admin','manager_head','support_staff','staff'])) {
        $stmt = $conn->prepare("
            UPDATE users
            SET name=?, email=?, role=?, position=?, department_id=?
            WHERE user_id=?
        ");
        $stmt->bind_param(
            "ssssii",
            $name, $email, $role, $pos, $dept, $user_id
        );
        $stmt->execute();
        $stmt->close();
    }
    
    $_SESSION['success'] = "User Updated successfully.";

    header("Location: admin_dashboard.php?page=users");
    exit();
}


// -------------------- Handle add department --------------------
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['add_department'])) {
    $dept_name = trim($_POST['department_name'] ?? '');

    if ($dept_name !== '') {

        // 1. Kunin ang last department_code
        $result = $conn->query("SELECT department_code FROM departments ORDER BY department_code DESC LIMIT 1");
        if ($result && $row = $result->fetch_assoc()) {
            $last_code = (int)$row['department_code'];
            $new_code = str_pad($last_code + 1, 3, '0', STR_PAD_LEFT);
        } else {
            // kung wala pang department
            $new_code = '001';
        }

        // 2. Insert with department_code
        $stmt = $conn->prepare(
            "INSERT INTO departments (department_code, department_name) VALUES (?, ?)"
        );

        if (!$stmt) {
            die("Prepare failed: " . $conn->error);
        }

        $stmt->bind_param('ss', $new_code, $dept_name);

        if (!$stmt->execute()) {
            die("Execute failed: " . $stmt->error);
        }

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

// -------------------- Handle search --------------------
$search = "";
if (isset($_GET['search'])) {
    $search = $conn->real_escape_string($_GET['search']);
}

// Pagination variables for ticket history
$page = isset($_GET['page_num']) ? intval($_GET['page_num']) : 1;
$limit = 10; // Tickets per page
$offset = ($page - 1) * $limit;

// Fetch departments for dropdowns
$departments = $conn->query("SELECT * FROM departments");

//--------------------- Excel File Add Employee --------------------------------

require __DIR__ . '/vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['upload_excel']) &&
    isset($_FILES['excel']) &&
    $_FILES['excel']['error'] === UPLOAD_ERR_OK
) {

    $error     = '';
    $added     = 0;
    $skipped   = 0;
    $emailSent = 0;

    $fileTmp = $_FILES['excel']['tmp_name'];

    $spreadsheet = IOFactory::load($fileTmp);
    $sheet = $spreadsheet->getActiveSheet();
    $rows = $sheet->toArray();

    unset($rows[0]); // REMOVE HEADER ROW

    foreach ($rows as $row) {

        /* ===============================
           GET ROW DATA
        =============================== */
        $employee_id   = trim($row[0] ?? '');
        $name          = trim($row[1] ?? '');
        $position      = trim($row[2] ?? '');
        $department_id = (int) ($row[3] ?? 0);
        $email         = trim($row[4] ?? '');
        $passwordRaw   = trim($row[5] ?? '');
        $role          = trim($row[6] ?? 'staff');

        if ($employee_id === '' || $name === '') {
            continue;
        }

        /* ===============================
           VALIDATE DEPARTMENT
        =============================== */
        if ($department_id <= 0) {
            $error = "❌ Invalid Department ID for Employee <b>$employee_id</b>";
            break;
        }

        $dept = $conn->prepare(
            "SELECT department_id FROM departments WHERE department_id=?"
        );
        $dept->bind_param("i", $department_id);
        $dept->execute();

        if ($dept->get_result()->num_rows === 0) {
            $error = "❌ Department ID <b>$department_id</b> does not exist";
            break;
        }

        /* ===============================
           CHECK EMPLOYEE ID
        =============================== */
        $check = $conn->prepare(
            "SELECT name, department_id FROM users WHERE employee_id=?"
        );
        $check->bind_param("s", $employee_id);
        $check->execute();
        $res = $check->get_result();

        if ($res->num_rows > 0) {

            $existing = $res->fetch_assoc();

            if (
                strtolower($existing['name']) === strtolower($name) &&
                (int) $existing['department_id'] === $department_id
            ) {
                $skipped++;
                continue; // ⏭ SKIP DUPLICATE
            }

            $error = "❌ Employee ID <b>$employee_id</b> conflict detected";
            break;
        }

        /* ===============================
           CHECK EMAIL DUPLICATE
        =============================== */
        if ($email !== '') {
            $emailCheck = $conn->prepare(
                "SELECT email FROM users WHERE email=?"
            );
            $emailCheck->bind_param("s", $email);
            $emailCheck->execute();

            if ($emailCheck->get_result()->num_rows > 0) {
                $error = "❌ Email <b>$email</b> already exists";
                break;
            }
        }

        /* ===============================
           PASSWORD HANDLING
        =============================== */
        if ($passwordRaw === '') {
            $passwordRaw = 'default123';
        }

        $passwordHash = password_hash($passwordRaw, PASSWORD_DEFAULT);

        /* ===============================
           INSERT EMPLOYEE
        =============================== */
        $stmt = $conn->prepare(
            "INSERT INTO users
            (employee_id, name, position, department_id, email, password, role, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'active')"
        );

        $stmt->bind_param(
            "sssisss",
            $employee_id,
            $name,
            $position,
            $department_id,
            $email,
            $passwordHash,
            $role
        );

        if (!$stmt->execute()) {
            $error = "❌ Insert failed: {$stmt->error}";
            break;
        }

        /* ===============================
           SEND EMAIL (ONLY FOR ADDED)
        =============================== */
        if ($email !== '') {
            if (sendEmployeeCredentials(
                $email,
                $employee_id,
                $passwordRaw // ✅ RAW PASSWORD
            )) {
                $emailSent++;
            }
        }

        $added++;
    }

    /* ===============================
       FINAL MESSAGE
    =============================== */
    if ($error === '') {
        $success = "✅ Upload completed<br>
                    Added: <b>$added</b><br>
                    Skipped: <b>$skipped</b><br>
                    Emails Sent: <b>$emailSent</b>";
    }
}


//-------------------------- Manual Add Employee ----------------------------------

if (isset($_POST['add_employee'])) {

    $employee_id = trim($_POST['employee_id'] ?? '');
    $name        = trim($_POST['name'] ?? '');
    $position    = trim($_POST['position'] ?? '');
    $department  = intval($_POST['department_id'] ?? 0);
    $email       = trim($_POST['email'] ?? '');
    $role        = trim($_POST['role'] ?? '');
    $passwordRaw = $_POST['password'] ?? '';

    /* ===============================
       REQUIRED FIELDS CHECK
    =============================== */
    if (
        $employee_id === '' ||
        $name === '' ||
        $email === '' ||
        $passwordRaw === '' ||
        $department === 0
    ) {
        $error = "❌ Please fill in all required fields.";
        goto message;
    }

    /* ===============================
       PASSWORD HANDLING
    =============================== */
    $passwordHash = password_hash($passwordRaw, PASSWORD_DEFAULT);

    /* ===============================
       CHECK EMPLOYEE ID
    =============================== */
    $check = $conn->prepare(
        "SELECT name, department_id FROM users WHERE employee_id = ?"
    );
    $check->bind_param("s", $employee_id);
    $check->execute();
    $res = $check->get_result();

    if ($res->num_rows > 0) {

        $existing = $res->fetch_assoc();

        if (
            strtolower($existing['name']) === strtolower($name) &&
            intval($existing['department_id']) === $department
        ) {
            $warning = "⚠ Employee already exists. Skipped.";
            goto message;
        }

        $error = "❌ Employee ID already exists with different name or department.";
        goto message;
    }

    /* ===============================
       CHECK EMAIL DUPLICATE
    =============================== */
    $checkEmail = $conn->prepare(
        "SELECT email FROM users WHERE email = ?"
    );
    $checkEmail->bind_param("s", $email);
    $checkEmail->execute();
    $emailRes = $checkEmail->get_result();

    if ($emailRes->num_rows > 0) {
        $error = "❌ Email address already exists.";
        goto message;
    }

    /* ===============================
       INSERT EMPLOYEE
    =============================== */
    $stmt = $conn->prepare(
        "INSERT INTO users
        (employee_id, name, position, department_id, email, password, role, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'active')"
    );

    $stmt->bind_param(
        "sssisss",
        $employee_id,
        $name,
        $position,
        $department,
        $email,
        $passwordHash, // ✅ HASHED PASSWORD
        $role
    );

    if ($stmt->execute()) {

        // ✅ SEND EMAIL USING RAW PASSWORD
        sendEmployeeCredentials(
            $email,
            $employee_id,
            $passwordRaw
        );

        $success = "✅ Employee added and email sent!";

    } else {
        $error = "❌ Database error";
    }

    message:
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Dashboard</title>
    <style>
        body { margin: 0; font-family: Arial, sans-serif; display: flex; height: 100vh; }
        .sidebar { width: 25%; background: #2c2c2c; color: white; display: flex; flex-direction: column; justify-content: space-between; padding: 20px; }
        .sidebar h2 { margin-bottom: 10px; text-align: center; }
        .pending-count { text-align: center; font-size: 18px; color: #ffd700; margin-bottom: 30px; }
        .menu { flex-grow: 1; }
        .menu button { width: 100%; padding: 15px; margin: 10px 0; background: #444; border: none; color: white; border-radius: 6px; cursor: pointer; font-size: 16px; }
        .menu button:hover { background: #666; }
        .logout-btn { width: 100%; padding: 15px; background: #ff4d4d; border: none; color: white; border-radius: 6px; cursor: pointer; font-size: 16px; font-weight: bold; margin-bottom: 22px;}
        .logout-btn:hover { background: #cc0000; }
        .summary-cards { display: flex; gap: 20px; margin-bottom: 20px; }
        .summary-card { flex: 1; background: white; padding: 20px; border-radius: 8px; text-align: center; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .summary-card h3 { margin: 0 0 10px 0; color: #333; }
        .summary-card p { font-size: 24px; font-weight: bold; color: #007bff; margin: 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; background: white; }
        th, td { padding: 12px; border: 1px solid #ddd; text-align: left; vertical-align: top; }
        th { background: #333; color: white; }
.search-bar form {
    display: flex;
    align-items: center;
    gap: 8px;
}

.search-bar input[type="text"] {
    padding: 8px;
    width: 305px;
}

.search-bar button {
    padding: 8px 14px;
    width: auto;          /* ⭐ important */
    flex: 0 0 auto;       /* ⭐ prevent stretching */
    white-space: nowrap; /* prevent text wrap */
}


        .action-btn { padding: 6px 12px; border: none; border-radius: 4px; cursor: pointer; }
        .approve { background: #28a745; color: white; }
        .declined { background: #dc3545; color: white; }
        .delete-btn { background: #dc3545; color: white; }
        .edit-btn { background: #007bff; color: white; }
        select, input[type=text] { padding: 6px; border-radius: 4px; border: 1px solid #ccc; width: 100%; box-sizing: border-box; }
        form.inline-form { display: inline-block; margin: 0; }
        .duration-cell { white-space: nowrap; }
        .success-msg { color: green; background: #d4edda; padding: 10px; border-radius: 4px; margin-bottom: 20px; }
        .pagination { text-align: center; margin-top: 20px; }
        .pagination a, .pagination span { padding: 8px 12px; margin: 0 2px; border: 1px solid #ddd; text-decoration: none; color: #007bff; }
        .pagination a:hover { background: #f0f0f0; }
        .pagination .current { background: #007bff; color: white; }
        
        .content {
    flex-grow: 1;
    padding: 70px;
    background: #f4f4f4;
    overflow-y: auto;

    /* default: border lang */
    margin-left: 8px;
    transition: margin-left 0.35s ease;
}
.sidebar:hover ~ .content {
    margin-left: 260px;
}

.sidebar {
    position: fixed;
    top: 0;
    left: 0;

    width: 260px;
    height: 100vh;
    background: #1e293b;
    color: white;

    display: flex;
    flex-direction: column;
    justify-content: space-between;

    margin-left: -280px;
    padding-right: 40px; /* 👈 SPACE FOR BORDER */
    padding-top: 15px; 
    padding-left: 15px; 

    transition: margin-left 0.35s ease;
    z-index: 1000;
}

/* BLACK BORDER */
.sidebar::before {
    content: "";
    position: absolute;
    top: 0;
    right: -8px;
    width: 15px;
    height: 100%;
    background: #000;
}

/* HOVER SLIDE */
.sidebar:hover {
    margin-left: 0;
}

/* ARROW INDICATOR */
.sidebar::after {
    content: "❯";           /* arrow icon */
    position: absolute;
    top: 50%;
    right: -20px;
    transform: translateY(-50%);
    
    color: white;
    font-size: 18px;
    font-weight: bold;

    background: #000;
    width: 16px;
    height: 40px;

    display: flex;
    align-items: center;
    justify-content: center;

    border-radius: 0 6px 6px 0;
    pointer-events: none;   /* important */
}

/* Rotate arrow when open */
.sidebar:hover::after {
    transform: translateY(-50%) rotate(180deg);
}

.sidebar::after {
    transition: transform 0.3s ease;
}


@media (max-width: 768px) {
    .sidebar {
        display: none;
    }

    .content {
        margin-left: 0;
    }
}

        /* Modal styles */
        #editUserModal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0; top: 0; width: 100%; height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.5);
        }
        #editUserModal .modal-content {
            background-color: #fff;
            margin: 10% auto;
            padding: 20px;
            border-radius: 8px;
            width: 400px;
            box-shadow: 0 0 10px rgba(0,0,0,0.25);
            position: relative;
        }
        #editUserModal h3 {
            margin-top: 0;
            margin-bottom: 15px;
            text-align: center;
        }
        #editUserModal label {
            display: block;
            margin-top: 10px;
            font-weight: bold;
        }
        #editUserModal select, #editUserModal input[type=text] {
                width: 100%;
                padding: 8px;
                margin-top: 5px;
                border-radius: 6px;
                border: 1px solid #ccc;
                box-sizing: border-box;
            }
            #editUserModal .modal-buttons {
                margin-top: 20px;
                text-align: center;
            }
            #editUserModal button {
                padding: 10px 20px;
                border: none;
                border-radius: 6px;
                cursor: pointer;
                font-size: 14px;
                margin: 0 8px;
            }
            #editUserModal .save-btn {
                background-color: #28a745;
                color: white;
            }
            #editUserModal .save-btn:hover {
                background-color: #218838;
            }
            #editUserModal .cancel-btn {
                background-color: #6c757d;
                color: white;
            }
            #editUserModal .cancel-btn:hover {
                background-color: #5a6268;
            }
            #editUserModal .delete-btn {
                background-color: #dc3545;
                color: white;
            }
            #editUserModal .delete-btn:hover {
                background-color: #c82333;
            }
.modal {
  display:none;
  position:fixed;
  inset:0;
  background:rgba(0,0,0,.5);
}
.modal-content {
  background:#fff;
  width:440px;
  margin:5% auto;
  padding:20px;
  border-radius:8px;
}
.close {
  float:right;
  cursor:pointer;
  font-size:22px;
}
 select, button {
  width:100%;
  margin:8px 0;
  padding:8px;
}
input {
      width:95%;
  margin:8px 0;
  padding:8px;
}

}
.tab-buttons button {
  width:50%;
}
.active {
  background:#007bff;
  color:#fff;
}
.template-link {
  display:block;
  margin:10px 0;
}
.employee-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
}
.employee-header h2 {
    margin-bottom: 8px;
}
.add-btn {
    width: auto !important;   /* ⬅️ pinaka-importante */
    display: inline-flex;     /* ⬅️ para di mag full width */
    align-items: center;
    gap: 4px;

    padding: 6px 12px;
    font-size: 13px;

    background: #2c7be5;
    color: #fff;
    border: none;
    border-radius: 4px;
    cursor: pointer;
}
.btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    font-size: 12px;
    border-radius: 4px;
    text-decoration: none;
    cursor: pointer;
    border: none;
}

.btn-primary {
    background: #2c7be5;
    color: #fff;
}

.btn-primary:hover {
    background: #1a68d1;
}

.btn-secondary {
    background: #e9eefb;
    color: #2c7be5;
    border: 1px solid #c6d4f5;
}

.btn-secondary:hover {
    background: #dfe7ff;
}

.toast {
    position: fixed;
    top: 20px;
    right: 20px;
    min-width: 260px;
    padding: 14px 18px;
    border-radius: 8px;
    color: #fff;
    font-size: 14px;
    z-index: 9999;
    box-shadow: 0 8px 20px rgba(0,0,0,.15);
    animation: slideIn .4s ease, fadeOut .4s ease 3.5s forwards;
}

.toast-success { background: #2ecc71; }
.toast-error   { background: #e74c3c; }
.toast-warning { background: #f39c12; }

@keyframes slideIn {
    from { transform: translateX(120%); opacity: 0; }
    to { transform: translateX(0); opacity: 1; }
}
@keyframes fadeOut {
    to { opacity: 0; transform: translateX(120%); }
}

        </style>

        <script>
function openModal() {
  document.getElementById('employeeModal').style.display = 'block';
  showTab('manual');
}

function closeModal() {
  document.getElementById('employeeModal').style.display = 'none';
}

function showTab(tab) {
  document.querySelectorAll('form').forEach(f => f.style.display = 'none');
  document.getElementById(tab + 'Btn').classList.add('active');
  document.getElementById(tab === 'manual' ? 'excelBtn' : 'manualBtn').classList.remove('active');

  document.querySelectorAll('form')[tab === 'manual' ? 0 : 1].style.display = 'block';
}
document.querySelector('.excel-upload-form input[type="file"]')
  .addEventListener('change', function() {
      document.querySelector('.btn-primary').disabled = !this.files.length;
  });

</script>

    </head>
    <body>

<?php if ($success || $error || $warning): ?>
<div id="floatingMessage" style="
position: fixed;
top: 25px;
left: 50%;
transform: translateX(-50%);
max-width: 500px;
padding: 18px 30px;
border-radius: 10px;
font-size: 18px;
font-weight: bold;
box-shadow: 0 6px 20px rgba(0,0,0,0.25);
z-index: 9999;
text-align: center;
background-color: <?= $success ? '#d4edda' : ($warning ? '#fff3cd' : '#f8d7da') ?>;
color: <?= $success ? '#155724' : ($warning ? '#856404' : '#721c24') ?>;
">
<?= $success ?: ($warning ?: $error) ?>
</div>

<script>
setTimeout(() => {
    const msg = document.getElementById("floatingMessage");
    if (msg) {
        msg.style.opacity = "0";
        setTimeout(() => msg.remove(), 500);
    }
}, 2500);
</script>
<?php endif; ?>


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
            <button class="logout-btn" onclick="return confirm('Are you sure you want to logout?') ? window.location.href='logout.php' : false;">
                Logout
            </button>
        </div>

    <?php if (!isset($_GET['page']) || $_GET['page'] === ''): ?>
        <div class="content" id="content-area">
            <h2>Ticket History</h2>

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

            <div class="search-bar">
                <form method="GET">
                    <input type="text" name="search" placeholder="Search by Department, Staff, or Control Number" value="<?php echo htmlspecialchars($search); ?>">
                    <input type="hidden" name="page_num" value="1"> <!-- Reset to page 1 on search -->
                    <button type="submit">Search</button>
                    <button type="button" onclick="window.location.href='admin_dashboard.php'">Refresh</button>
                </form>
            </div>

            <?php
            // Fetch tickets with duration and pagination
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
                LIMIT $limit OFFSET $offset
            ";
            $tickets = $conn->query($tickets_sql);

            // Get total tickets for pagination
            $total_tickets_sql = "
                SELECT COUNT(*) as total
                FROM tickets t
                JOIN users u ON t.user_id = u.user_id
                JOIN departments d ON t.department_id = d.department_id
                $search_sql
            ";
            $total_tickets_result = $conn->query($total_tickets_sql);
            $total_tickets_count = $total_tickets_result->fetch_assoc()['total'];
            $total_pages = ceil($total_tickets_count / $limit);
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
                            <td><?php echo $row ['control_number']; ?></td>
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

            <!-- Pagination -->
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?page_num=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>">&laquo; Previous</a>
                <?php endif; ?>

                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <?php if ($i == $page): ?>
                        <span class="current"><?php echo $i; ?></span>
                    <?php else: ?>
                        <a href="?page_num=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>"><?php echo $i; ?></a>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($page < $total_pages): ?>
                    <a href="?page_num=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>">Next &raquo;</a>
                <?php endif; ?>
            </div>
        </div>

    <?php elseif ($_GET['page'] === 'users'): ?>
        <div class="content">
            <?php 
            // Display success message if set
            if (isset($_SESSION['success'])) {
                echo '<div class="success-msg">' . htmlspecialchars($_SESSION['success']) . '</div>';
                unset($_SESSION['success']);
            }
            ?>
            
<div class="employee-header">
    <h2>Add Employees</h2>
    </div>
    <div class = "btn">
    <button class="add-btn" onclick="openModal()">➕ Add Employee</button>
</div>

<div id="employeeModal" class="modal">
  <div class="modal-content">

    <span class="close" onclick="closeModal()">&times;</span>
    <h2>Add Employee</h2>

    <!-- Tabs -->
    <div class="tab-buttons">
      <button onclick="showTab('manual')" id="manualBtn" class="active">Manual Add</button>
      <button onclick="showTab('excel')" id="excelBtn">Upload Excel</button>
    </div>
    
<form id="add_employee" method="POST">

  <input type="text" name="employee_id" placeholder="Employee ID" required>
    <input
    type="text"
    name="name"
    placeholder="Full Name"
    required
    oninput="this.value = this.value.replace(/[^A-Za-z\s]/g, '')"
>
  <input
    type="text"
    name="position"
    placeholder="Position"
    required
    oninput="this.value = this.value.replace(/[^A-Za-z\s]/g, '')"
>


  <select name="department_id" required>
    <option value="">Select Department</option>
     <?php $deps = $conn->query("SELECT department_id, department_name FROM departments"); while ($d = $deps->fetch_assoc()) { echo "<option value='{$d['department_id']}'>{$d['department_name']}</option>"; } ?>
  </select>

  <input type="email" name="email" placeholder="Email" required>
  <input type="password" name="password" placeholder="Password" required>

  <select name="role" required>
    <option value="staff">Staff</option>
    <option value="support_staff">Suport Staff</option>
    <option value="manager_head">Manager Head</option>
    <option value="admin">Admin</option>
  </select>

  <button type="submit" name="add_employee">Save Employee</button>
</form>

<form method="POST" enctype="multipart/form-data" class="excel-upload-form">

    <h3>Upload Employees via Excel</h3>


        <div class="excel-info">
        <code>
            Don't have template?
        </code>
    </div>
    <div class="excel-actions">
        <a href="download_employee_template.php" class="btn btn-secondary">
            Download Template
        </a>

        
            <div class="file-input-wrapper">
        <input type="file" name="excel" accept=".xlsx,.xls" required>
    </div>
        <button type="submit" name="upload_excel" class="btn btn-primary">
            📤 Upload Excel
        </button>
        
    </div>

</form>

</div>
</div>

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
                            'user_id' => $row['user_id'],
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

<!-- Edit User Modal -->
<div id="editUserModal">
    <div class="modal-content">
        <h3>Edit Staff</h3>

        <form method="POST">
            <input type="hidden" name="edit_user_id" id="edit_user_id">

            <label>Name</label>
            <input
    type="text"
    name="new_name"
    id="new_name"
    required
    oninput="this.value = this.value.replace(/[^A-Za-z\s]/g, '')"
>


            <label>Email</label>
            <input type="email" name="new_email" id="new_email" required>

            <label>Role</label>
            <select name="new_role" id="new_role" required>
                <option value="staff">Staff</option>
                <option value="support_staff">Support Staff</option>
                <option value="manager_head">Manager Head</option>
                <option value="admin">Admin</option>
            </select>

            <label>Position</label>
            <input
    type="text"
    name="new_position"
    id="new_position"
    required
    oninput="this.value = this.value.replace(/[^A-Za-z\s]/g, '')"
>

            <label>Department</label>
            <select name="new_department_id" id="new_department_id" required>
                <option value="">-- Select Department --</option>
                <?php
                $deps = $conn->query("SELECT * FROM departments ORDER BY department_name");
                while ($d = $deps->fetch_assoc()):
                ?>
                    <option value="<?= $d['department_id'] ?>">
                        <?= htmlspecialchars($d['department_name']) ?>
                    </option>
                <?php endwhile; ?>
            </select>

            <div class="modal-buttons">
                <button type="submit" class="save-btn">Save</button>
                <button type="button" class="cancel-btn" onclick="hideEditModal()">Cancel</button>
                <button type="button" class="delete-btn" onclick="confirmDeleteUser()">Delete</button>
            </div>
        </form>
    </div>
</div>


<script>
    // Store users data for modal population
const usersData = <?= json_encode($users_js_data) ?>;

function showEditModal(userId) {
    const user = usersData[userId];
    if (!user) return;

    document.getElementById('edit_user_id').value = user.user_id;
    document.getElementById('new_name').value = user.name;
    document.getElementById('new_email').value = user.email;
    document.getElementById('new_role').value =
        user.role === 'user' ? 'staff' : user.role;
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
    if (!confirm('Delete this user?')) return;

    const form = document.createElement('form');
    form.method = 'POST';

    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'delete_user_id';
    input.value = document.getElementById('edit_user_id').value;

    form.appendChild(input);
    document.body.appendChild(form);
    form.submit();
}
</script>


<!-- Tooltip styles and duration button styles -->
<style>
 /* ================================
   MODAL OVERLAY
================================ */
#editUserModal {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.5);
    z-index: 1000;
    overflow-y: auto;
}

/* ================================
   MODAL CONTENT
================================ */
#editUserModal .modal-content {
    background: #ffffff;
    width: 420px;
    max-width: 95%;
    margin: 8% auto;
    padding: 25px 30px;
    border-radius: 12px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.25);
}

/* ================================
   TITLE
================================ */
#editUserModal h3 {
    margin: 0 0 20px;
    text-align: center;
    font-size: 20px;
    font-weight: 700;
    color: #333;
}

/* ================================
   LABELS
================================ */
#editUserModal label {
    display: block;
    margin-bottom: 6px;
    font-size: 14px;
    font-weight: 600;
    color: #444;
}

/* ================================
   INPUTS & SELECT
================================ */
#editUserModal input[type="text"],
#editUserModal input[type="email"],
#editUserModal select {
    width: 100%;
    padding: 10px 12px;
    font-size: 14px;
    border-radius: 8px;
    border: 1px solid #ccc;
    box-sizing: border-box;
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
}

/* ================================
   FOCUS EFFECT
================================ */
#editUserModal input:focus,
#editUserModal select:focus {
    border-color: #007BFF;
    outline: none;
    box-shadow: 0 0 0 2px rgba(0,123,255,0.15);
}

/* ================================
   FIELD SPACING
================================ */
#editUserModal label + input,
#editUserModal label + select {
    margin-bottom: 14px;
}

/* ================================
   BUTTON CONTAINER
================================ */
#editUserModal .modal-buttons {
    display: flex;
    gap: 10px;
    margin-top: 20px;
}

/* ================================
   BUTTONS
================================ */
#editUserModal .save-btn,
#editUserModal .cancel-btn,
#editUserModal .delete-btn {
    flex: 1;
    padding: 10px;
    border-radius: 8px;
    border: none;
    cursor: pointer;
    font-size: 14px;
    font-weight: 600;
}

/* ================================
   BUTTON COLORS
================================ */
#editUserModal .save-btn {
    background: #007BFF;
    color: #fff;
}

#editUserModal .cancel-btn {
    background: #6c757d;
    color: #fff;
}

#editUserModal .delete-btn {
    background: #dc3545;
    color: #fff;
}

/* ================================
   HOVER EFFECTS
================================ */
#editUserModal .save-btn:hover {
    background: #0069d9;
}

#editUserModal .cancel-btn:hover {
    background: #5a6268;
}

#editUserModal .delete-btn:hover {
    background: #c82333;
}

/* ================================
   RESPONSIVE
================================ */
@media (max-width: 480px) {
    #editUserModal .modal-content {
        margin: 15% auto;
        padding: 20px;
    }
}

            </style>

        </body>
    </html>
<?php $conn->close(); ?>