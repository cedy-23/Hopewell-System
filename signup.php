<?php
$conn = new mysqli("localhost", "u248040635_cedy", "(April_23_2004)!!!!", "u248040635_ticketsystem");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

$message = "";
$success_message = "";
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Sanitize inputs
    $name = trim(htmlspecialchars($_POST['name']));
    $email = trim(htmlspecialchars($_POST['email']));
    $password = $_POST['password']; // Don't trim password
    $role = trim(htmlspecialchars($_POST['role']));
    $position = trim(htmlspecialchars($_POST['position']));
    $department_id = trim(htmlspecialchars($_POST['department']));

    // Validation
    $errors = [];
    if (empty($name)) $errors[] = "Name is required.";
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Valid email is required.";
    if (empty($password) || strlen($password) < 8) $errors[] = "Password must be at least 8 characters.";
    if (empty($role)) $errors[] = "Role is required.";
    if (empty($position)) $errors[] = "Position is required.";
    if (empty($department_id)) $errors[] = "Department is required.";

    if (!empty($errors)) {
        $message = implode(" ", $errors);
    } else {
        // Check if email already exists
        $check_sql = "SELECT COUNT(*) FROM users WHERE email = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("s", $email);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result()->fetch_row()[0];

        if ($check_result > 0) {
            $message = "Email already exists. Please use a different email.";
        } else {
            // Hash password
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);

            // Insert user with pending status
            $insert_sql = "INSERT INTO users (name, position, email, password, role, department_id, status) VALUES (?, ?, ?, ?, ?, ?, 'pending')";
            $insert_stmt = $conn->prepare($insert_sql);
            $insert_stmt->bind_param("sssssi", $name, $position, $email, $hashed_password, $role, $department_id);

            if ($insert_stmt->execute()) {
                // Include mailer
                require 'send_mail.php';

                // Send acknowledgment email to the user
                $subject = "Account Registration - Pending Approval";
                $body = "
                <h2>Account Registration Received</h2>
                <p>Hello <b>" . htmlspecialchars($name) . "</b>,</p>
                <p>Thank you for registering for the MIS Ticketing System.</p>
                <p>Your account is currently <b>pending approval</b> by the administrator.</p>
                <p>You’ll receive another email once your account is approved.</p>
                <br><p>– MIS Helpdesk</p>";
                sendMail($email, $subject, $body);

                // 🔔 Send notification to all active admins
                $admin_query = $conn->query("SELECT name, email FROM users WHERE role = 'admin' AND status = 'active'");
               if ($admin_query && $admin_query->num_rows > 0) {

    // 🔹 Fetch department name for email display
    $dept_name = "Unknown Department";
    $dept_query = $conn->prepare("SELECT department_name FROM departments WHERE department_id = ?");
    $dept_query->bind_param("i", $department_id);
    $dept_query->execute();
    $dept_result = $dept_query->get_result();
    if ($dept_result && $dept_row = $dept_result->fetch_assoc()) {
        $dept_name = $dept_row['department_name'];
    }
    $dept_query->close();

    // 🔹 Send email to all admins
    while ($admin = $admin_query->fetch_assoc()) {
        $admin_subject = "New User Registration - Approval Required";
        $admin_body = "
        <h3>New User Registration</h3>
        <p>A new user has registered and is awaiting your approval.</p>
        <p><b>Name:</b> " . htmlspecialchars($name) . "<br>
        <b>Position:</b> " . htmlspecialchars($position) . "<br>
        <b>Department:</b> " . htmlspecialchars($dept_name) . "<br>
        <b>Email:</b> " . htmlspecialchars($email) . "</p>
        <p>Please log in to your Admin Dashboard to review and approve this account.</p>
        <br><p>– MIS Helpdesk</p>";
        
        sendMail($admin['email'], $admin_subject, $admin_body);
    }
}


                // Success message for user
                $success_message = "✅ Your account has been submitted for approval. You’ll receive an email once it’s approved.";
                echo "<meta http-equiv='refresh' content='3;url=index.php'>";
            } else {
                $message = "Error creating account: " . $conn->error;
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Sign Up - Ticketing System</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <style>
      body {
          margin: 0;
          padding: 0;
          height: 100vh;
          font-family: Arial, sans-serif;
          display: flex;
          justify-content: center;
          align-items: center;
          background: url('background.png') no-repeat center center/cover;
      }

      .signup-container {
          background: rgba(255, 255, 255, 0.95);
          padding: 20px 18px;
          border-radius: 10px;
          text-align: center;
          width: 100%;
          max-width: 550px;
          margin: 0 10px;
          box-shadow: 0 3px 15px rgba(0,0,0,0.2);
      }

      .signup-container img {
          max-width: 110px;
          margin-bottom: 10px;
      }

      .signup-container h2 {
          margin: 8px 0 15px;
          color: #2d572c;
          font-size: 18px;
          font-weight: bold;
      }

      .signup-container input,
      .signup-container select {
          width: 100%;
          padding: 9px;
          margin: 7px 0;
          border: 1px solid #ccc;
          border-radius: 5px;
          font-size: 13px;
          box-sizing: border-box;
      }

      .signup-container button {
          width: 100%;
          padding: 10px;
          background: #17984b;
          color: #fff;
          border: none;
          border-radius: 5px;
          font-size: 14px;
          cursor: pointer;
          margin-top: 8px;
      }

      .signup-container button:hover {
          background: #137c3b;
      }

      .signup-container a {
          display: block;
          margin-top: 10px;
          color: #17984b;
          text-decoration: none;
          font-size: 12px;
      }

      .signup-container a:hover {
          text-decoration: underline;
      }

      .message {
          margin-top: 10px;
          color: #d9534f;
          font-size: 13px;
      }

      .success-message {
          margin-top: 10px;
          color: #17984b;
          font-size: 13px;
          font-weight: bold;
      }

      @media (max-width: 480px) {
          .signup-container {
              max-width: 280px;
              padding: 18px 15px;
          }
          .signup-container h2 {
              font-size: 16px;
          }
      }
  </style>
</head>
<body>
  <div class="signup-container">
      <img src="logo.png" alt="HSC Logo">
      <h2>Sign Up</h2>
      <form method="POST">
          <input type="text" name="name" placeholder="Full Name" value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name']) : ''; ?>" required>
          <input type="email" name="email" placeholder="Email" value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" required>
          <input type="password" name="password" placeholder="Password" required>
          <input type="text" name="position" placeholder="Position" value="<?php echo isset($_POST['position']) ? htmlspecialchars($_POST['position']) : ''; ?>" required>

          <select name="department" required>
              <option value="">Select Department</option>
              <?php
              $departments = $conn->query("SELECT * FROM departments");
              while ($row = $departments->fetch_assoc()) {
                  $selected = (isset($_POST['department']) && $_POST['department'] == $row['department_id']) ? 'selected' : '';
                  echo "<option value='{$row['department_id']}' $selected>{$row['department_name']}</option>";
              }
              ?>
          </select>

          <select name="role" required>
              <option value="">Select Role</option>
              <?php
              $roles = ['manager' => 'Support Staff', 'staff' => 'Staff'];
              foreach ($roles as $value => $label) {
                  $selected = (isset($_POST['role']) && $_POST['role'] == $value) ? 'selected' : '';
                  echo "<option value='$value' $selected>$label</option>";
              }
              ?>
          </select>

          <button type="submit">Sign Up</button>
      </form>
      <a href="index.php">Back to Login</a>
      <?php if ($message != "") echo "<p class='message'>$message</p>"; ?>
      <?php if ($success_message != "") echo "<p class='success-message'>$success_message</p>"; ?>
  </div>
</body>
</html>
