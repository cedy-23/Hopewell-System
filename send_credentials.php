<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/vendor/autoload.php';

/**
 * Send employee login credentials via email
 *
 * @param string $email
 * @param string $employee_id
 * @param string $rawPassword  // ⚠️ PLAIN password (NOT hashed)
 * @return bool
 */
function sendEmployeeCredentials($email, $employee_id, $rawPassword)
{
    $login_url = "https://hopewellsalecorporation-ticketingsystem.com/index.php";

    $mail = new PHPMailer(true);

    try {
        /* ===============================
           SMTP SETTINGS
        =============================== */
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'hopewellsalesco@gmail.com';
        $mail->Password   = 'xiimxbbwtrxildjl'; // ⚠️ CHANGE THIS ASAP
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        /* ===============================
           EMAIL INFO
        =============================== */
        $mail->setFrom('hopewellsalesco@gmail.com', 'MIS Helpdesk');
        $mail->addAddress($email);

        $mail->isHTML(true);
        $mail->Subject = 'Your Employee Account Credentials';

        /* ===============================
           EMAIL BODY
        =============================== */
        $mail->Body = "
            <h2>Welcome!</h2>
            <p>Your employee account has been created.</p>

            <p><strong>Employee ID:</strong> {$employee_id}</p>
            <p><strong>Temporary Password:</strong> {$rawPassword}</p>

            <br>
            <a href='{$login_url}'
               style='background:#007bff;color:#ffffff;padding:12px 20px;
               text-decoration:none;border-radius:6px;display:inline-block;'>
               Login Here
            </a>

            <br><br>
            <small>Please change your password after logging in.</small>
        ";

        $mail->send();
        return true;

    } catch (Exception $e) {
        // You can log $mail->ErrorInfo if needed
        return false;
    }
}

