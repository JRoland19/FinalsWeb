<?php
// send_email.php - Placeholder for actual email functionality

function send_reset_email($recipient_email, $token) {
    // NOTE: In a real system, you MUST use a robust library like PHPMailer 
    // and an authenticated SMTP server to send emails.
    // This is a minimal function for testing/placeholder purposes.
    
    $reset_link = "http://yourdomain.com/reset_password.php?token=" . urlencode($token);

    $subject = "Password Reset Request";
    
    $body = "Hello,\n\n";
    $body .= "You requested a password reset. Click the link below to reset your password:\n\n";
    $body .= $reset_link . "\n\n";
    $body .= "This link will expire in 60 minutes.\n\n";
    $body .= "If you did not request this, please ignore this email.";

    $headers = 'From: noreply@yourdomain.com' . "\r\n" .
               'Reply-To: noreply@yourdomain.com' . "\r\n" .
               'X-Mailer: PHP/' . phpversion();

    // The mail() function is often unreliable or disabled on shared hosting.
    // Replace this with PHPMailer or another solution for production.
    return mail($recipient_email, $subject, $body, $headers); 
}
?>