<?php
// ==========================================================
// send_mail.php
// Reusable email sender using Gmail SMTP via PHPMailer
// For ticketing system notifications and password recovery
// ==========================================================

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Include PHPMailer library files
require_once __DIR__ . '/PHPMailer/src/Exception.php';
require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/src/SMTP.php';

function sendMail($toEmail, $subject, $body, $altBody = '', $toName = '') {
    $mail = new PHPMailer(true);

    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'hopewellsalesco@gmail.com';  // ✅ corrected Gmail
        $mail->Password   = 'xiimxbbwtrxildjl';           // ✅ no spaces
        $mail->SMTPSecure = 'tls';
        $mail->Port       = 587;

        // Sender info
        $mail->setFrom('hopewellsalesco@gmail.com', 'MIS Helpdesk');

        // Recipient info
        if (!empty($toName)) {
            $mail->addAddress($toEmail, $toName);
        } else {
            $mail->addAddress($toEmail);
        }

        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->AltBody = $altBody ?: strip_tags($body);

        // Optional: reply-to address
        $mail->addReplyTo('hopewellsalesco@gmail.com', 'MIS Helpdesk');

        // Send
        $mail->send();
        return true;

    } catch (Exception $e) {
        return "Mailer Error: {$mail->ErrorInfo}";
    }
}
?>
