<?php
session_start();
$conn = new mysqli("localhost", "u248040635_cedy", "(April_23_2004)!!!!", "u248040635_ticketsystem");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

require 'send_mail.php';

$message = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email = trim($_POST['email']);

    if (!empty($email)) {
        // Verify user exists
        $stmt = $conn->prepare("SELECT user_id, name FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            $user_id = $user['user_id'];

            // Generate token and expiry
            $token = bin2hex(random_bytes(16));
            $expiry = date("Y-m-d H:i:s", strtotime("+1 hour"));

            // Remove any old tokens for this user
            $conn->query("DELETE FROM password_reset_tokens WHERE user_id = $user_id");

            // Insert new reset token
            $insert = $conn->prepare("INSERT INTO password_reset_tokens (user_id, token, expires_at) VALUES (?, ?, ?)");
            if (!$insert) {
                die("SQL Error: " . $conn->error);
            }
            $insert->bind_param("iss", $user_id, $token, $expiry);
            $insert->execute();

            // Create reset link
            $resetLink = "https://hopewellsalecorporation-ticketingsystem.com/reset_password.php?token=$token";

            // Email content
            $subject = "Password Reset Request - MIS Ticketing System";
            $body = "
                <h2>Password Reset Request</h2>
                <p>Hello <b>{$user['name']}</b>,</p>
                <p>We received a request to reset your password for your MIS Ticketing System account.</p>
                <p>Click the link below to reset your password:</p>
                <p><a href='$resetLink'>$resetLink</a></p>
                <p><b>This link will expire in 1 hour.</b></p>
                <br><p>– MIS Helpdesk</p>
            ";

            // Send email via PHPMailer
            if (sendMail($email, $subject, $body)) {
                $message = "✅ A password reset link has been sent to your email.";
            } else {
                $message = "❌ Failed to send email. Please try again later.";
            }
        } else {
            $message = "⚠️ No account found with that email address.";
        }

        $stmt->close();
    } else {
        $message = "⚠️ Please enter your email address.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Forgot Password - MIS Ticketing System</title>
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
      <h2>Forgot Password</h2>

      <form method="POST">
          <input type="email" name="email" placeholder="Enter your email" required>
          <button type="submit">Send Reset Link</button>
      </form>

      <?php if (!empty($message)) { echo "<p class='message'>$message</p>"; } ?>

      <a href="index.php">Back to Login</a>
  </div>
</body>
</html>
