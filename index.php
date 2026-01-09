<?php
session_start();
$conn = new mysqli("localhost", "u248040635_cedy", "(April_23_2004)!!!!", "u248040635_ticketsystem");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);
$error = "";

// Handle login
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $employee_id = trim($_POST['employee_id'] ?? '');
    $password    = trim($_POST['password'] ?? '');
    $remember    = isset($_POST['remember']);

    if ($employee_id !== '' && $password !== '') {

$stmt = $conn->prepare("
    SELECT 
        u.user_id,
        u.name,
        u.email,
        u.position,
        u.profile_picture,
        u.role,
        u.password,
        u.status,
        u.department_id,              -- ✅ ADD THIS
        d.department_name
    FROM users u
    LEFT JOIN departments d 
        ON u.department_id = d.department_id
    WHERE u.employee_id = ?
    LIMIT 1
");

        $stmt->bind_param("s", $employee_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {

            $user = $result->fetch_assoc();

            if (password_verify($password, $user['password'])) {

                if ($user['status'] === 'active') {

$_SESSION['user_id']         = $user['user_id'];
$_SESSION['role']            = $user['role'];
$_SESSION['name']            = $user['name'];
$_SESSION['email']           = $user['email'];
$_SESSION['position']        = $user['position'];
$_SESSION['department_name'] = $user['department_name'];
$_SESSION['department_id'] = $user['department_id'];
$_SESSION['profile_picture'] = $user['profile_picture'];


                    // REMEMBER ME (30 DAYS)
                    if ($remember) {
                        setcookie('user_id', $user['user_id'], time() + (30 * 24 * 60 * 60), "/");
                        setcookie('role', $user['role'], time() + (30 * 24 * 60 * 60), "/");
                        setcookie('user_name', $user['name'], time() + (30 * 24 * 60 * 60), "/");
                    }

                    // ROLE-BASED REDIRECT
                    if ($user['role'] === 'admin') {
                        header("Location: admin_dashboard.php");
                    } elseif ($user['role'] === 'support_staff') {
                        header("Location: manager_dashboard.php");
                    } elseif ($user['role'] === 'manager_head') {
                        header("Location: manager_head_dashboard.php");
                    } else {
                        header("Location: staff_dashboard.php");
                    }
                    exit();

                } elseif ($user['status'] === 'pending') {
                    $error = "⏳ Your account is awaiting admin approval.";
                } elseif ($user['status'] === 'declined') {
                    $error = "❌ Your account was declined. Please contact MIS.";
                } else {
                    $error = "⚠️ Account inactive. Contact MIS.";
                }

            } else {
                $error = "❌ Invalid Employee ID or Password.";
            }

        } else {
            $error = "❌ Invalid Employee ID or Password.";
        }

        $stmt->close();

    } else {
        $error = "❌ Please enter both Employee ID and Password.";
    }
}
?>
    
<?php if (!empty($error)): ?>
<div id="floatingMessage"><?= $error ?></div>
<script>
    setTimeout(() => {
        const msg = document.getElementById('floatingMessage');
        msg.style.opacity = "0";
        msg.style.transition = "opacity 0.5s ease";
        setTimeout(() => msg.remove(), 500);
    }, 1500);
</script>
<?php endif; ?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Login - Ticketing System</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="style.css">
  <style>
  body {
    background: url('background.png') no-repeat center center/cover;
}
</style>
</head>
<body>

<div class="login-container">
    <img src="logo.png" alt="HSC Logo">
    <h2>Ticketing System</h2>

    <form method="POST" action="">
        <input type="text" name="employee_id" placeholder="Employee ID" required>

        <div class="password-container">
            <input type="password" name="password" id="password" placeholder="Password" required>
            <span class="toggle-password" onclick="togglePassword()">
                <i id="eyeIcon" class="fa fa-eye"></i>
            </span>
        </div>

        <div id="caps-warning">⚠️ Caps Lock is ON</div>

        <button type="submit">Log in</button>
    </form>

    <a href="forgot_password.php">Forgot password?</a>
</div>

<!-- Font Awesome for the eye icon -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<script>
    const passwordField = document.getElementById('password');
    const capsWarning = document.getElementById('caps-warning');
    const eyeIcon = document.getElementById('eyeIcon');

    passwordField.addEventListener('keyup', (event) => {
        const caps = event.getModifierState && event.getModifierState('CapsLock');
        capsWarning.style.display = caps ? 'block' : 'none';
    });

    function togglePassword() {
        const isPassword = passwordField.type === 'password';
        passwordField.type = isPassword ? 'text' : 'password';
        eyeIcon.classList.toggle('fa-eye');
        eyeIcon.classList.toggle('fa-eye-slash');
    }
</script>
</body>
</html>