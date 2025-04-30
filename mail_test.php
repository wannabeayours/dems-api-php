<?php
// Import PHPMailer classes into the global namespace
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

class Send_to_Email
{
    function send_OTP($sentEmail, $emailSubject, $emailBody)
    {

        // Load PHPMailer manually (not using Composer)
        require 'PHPMailer/src/PHPMailer.php';
        require 'PHPMailer/src/SMTP.php';
        require 'PHPMailer/src/Exception.php';

        // Create an instance; passing `true` enables exceptions
        $mail = new PHPMailer(true);

        try {
            // Accepts only gmail
            $mailer_host = "smtp.gmail.com";
            // User email used for this code
            $mailer_user = "ikversoza@gmail.com";
            // User password for the user
            $mailer_password = "cjgomhdwaxdchlox";
            $mailer_port = 465;

            // Change to official actual Demiren's Hotel email
            $emailFrom = "ikversoza@gmail.com";

            // Server settings
            $mail->SMTPDebug = SMTP::DEBUG_SERVER;  // Enable verbose debug output
            $mail->isSMTP();                        // Send using SMTP
            $mail->Host       = $mailer_host; // Set the SMTP server
            $mail->SMTPAuth   = true;               // Enable SMTP authentication
            $mail->Username   = $mailer_user; // SMTP username
            $mail->Password   = $mailer_password;           // SMTP password
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; // Enable SSL encryption
            $mail->Port       = $mailer_port;                // TCP port to connect to

            // Recipients
            $mail->setFrom($emailFrom, 'Hotel');
            $mail->addAddress($sentEmail, 'Customer'); // Add a recipient

            // Content
            $mail->isHTML(true);
            $mail->Subject = $emailSubject;
            $mail->Body    = $emailBody;
            $mail->AltBody = 'This is the body in plain text for non-HTML mail clients';

            $mail->send();
            echo 'Message has been sent';
        } catch (Exception $e) {
            echo "Message could not be sent. Mailer Error: {$mail->ErrorInfo}";
        }
    }
}
