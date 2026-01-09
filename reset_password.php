<?php
session_start();

$conn = new mysqli("localhost", "u248040635_cedy", "(April_23_2004)!!!!", "u248040635_ticketsystem");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

$message = "";
$token = isset($_GET['token']) ? trim($_GET['token']) : '';

if (empty($token)) {
    $message = "Invalid or missing password reset token.";
} else {
    // Validate token from database
    $stmt = $conn->prepare("
        SELECT prt.user_id, prt.expires_at, u.name 
        FROM password_reset_tokens prt
        JOIN users u ON prt.user_id = u.user_id
        WHERE prt.token = ?
        LIMIT 1
    ");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $data = $result->fetch_assoc();
        $user_id = $data['user_id'];
        $expires = strtotime($data['expires_at']);
        $now = time();

        if ($now > $expires) {
            $message = "⚠️ This password reset link has expired. Please request a new one.";
        } elseif ($_SERVER["REQUEST_METHOD"] === "POST") {
            $new_pass = $_POST['new_password'];
            $confirm_pass = $_POST['confirm_password'];

            if ($new_pass === $confirm_pass && strlen($new_pass) >= 8) {
                $hashed_pass = password_hash($new_pass, PASSWORD_DEFAULT);

                // Update user's password
                $update = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
                $update->bind_param("si", $hashed_pass, $user_id);
                $update->execute();

                // Delete token after successful reset
                $conn->query("DELETE FROM password_reset_tokens WHERE user_id = $user_id");

                $message = "✅ Your password has been reset successfully! You can now log in.";
            } else {
                $message = "❌ Passwords do not match or must be at least 8 characters long.";
            }
        }
    } else {
        $message = "❌ Invalid or expired token. Please request a new password reset link.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Reset Password - MIS Ticketing System</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="style.css">
  <style>
      body {
          background: url('background.png') no-repeat center center/cover;
      }
  </style>
</head>
<body>
  <div class="container">
      <img src="logo.png" alt="Logo">
      <h2>Reset Password</h2>

      <?php if (!empty($message)) { echo "<p class='message'>$message</p>"; } ?>

      <?php if (isset($data) && strtotime($data['expires_at']) > time()): ?>
      <form method="POST">
          <input type="password" name="new_password" placeholder="Enter new password" required minlength="8">
          <input type="password" name="confirm_password" placeholder="Confirm new password" required minlength="8">
          <button type="submit">Reset Password</button>
      </form>
      <?php endif; ?>

      <a href="index.php">Back to Login</a>
  </div>
</body>
</html>
