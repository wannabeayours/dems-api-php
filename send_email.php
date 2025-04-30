<?php
// Import PHPMailer classes into the global namespace
// These must be at the top of your script, not inside a function
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

class SendEmail
{
    function sendEmail($emailToSent, $emailSubject, $emailBody)
    {
        // Load Composer's autoloader
        require 'vendor/autoload.php';

        // Create an instance; passing `true` enables exceptions
        $mail = new PHPMailer(true);

        try {
            // Server settings
            $mail->SMTPDebug = 0;                                    // Disable verbose debug output
            $mail->isSMTP();                                        // Send using SMTP
            $mail->Host       = 'smtp.gmail.com';                   // Set the SMTP server to send through
            $mail->SMTPAuth   = true;                               // Enable SMTP authentication
            $mail->Username   = 'baldozarazieljade96@gmail.com';               // SMTP username
            $mail->Password   = 'ntxbolxdsdpfgdwo';                 // SMTP password
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;        // Enable implicit TLS encryption
            $mail->Port       = 465;                                // TCP port to connect to; use 587 if you have set `SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS`

            // Recipients
            $mail->setFrom('baldozarazieljade96@gmail.com', 'Phinma-COC CSDL');
            $mail->addAddress($emailToSent, 'user');                 // Add a recipient

            // Content
            $mail->isHTML(true);                                  // Set email format to HTML
            $mail->Subject = $emailSubject;
            $mail->Body    = $emailBody;
            $mail->AltBody = 'Kunwari alt body diri hehe';

            $mail->send();
            return 1; // Success
        } catch (Exception $e) {
            // Log or handle the error as needed
            // echo "Message could not be sent. Mailer Error: {$mail->ErrorInfo}";
            return $e; // Failure
        }
    }
}