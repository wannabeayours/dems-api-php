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
            $mail->SMTPDebug = 0;                                    // Disable debug output for production
            $mail->isSMTP();                                        // Send using SMTP
            $mail->Host       = 'smtp.gmail.com';                   // Set the SMTP server to send through
            $mail->SMTPAuth   = true;                               // Enable SMTP authentication
            $mail->Username   = 'beyasabellach@gmail.com';              // SMTP username
            $mail->Password   = 'ufpbvrtwpiqrjllq';                 // SMTP password (App Password)
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;     // Enable TLS encryption
            $mail->Port       = 587;                                // TCP port to connect to; use 587 for STARTTLS
            $mail->Timeout    = 30;                                 // Set timeout to 30 seconds
            $mail->CharSet    = 'UTF-8';                            // Set character encoding

            // Recipients
            $mail->setFrom('beyasabellach@gmail.com', 'Demiren Hotel');
            $mail->addAddress($emailToSent, 'Guest');               // Add a recipient

            // Content
            $mail->isHTML(true);                                    // Set email format to HTML
            $mail->Subject = $emailSubject;
            $mail->Body    = $emailBody;
            $mail->AltBody = 'This is the plain text version of the email.';

            $mail->send();
            
            // Log success
            error_log("Email sent successfully to: " . $emailToSent);
            return true; // Success
        } catch (Exception $e) {
            // Log detailed error information
            error_log("Email sending failed to: " . $emailToSent);
            error_log("PHPMailer Error: " . $mail->ErrorInfo);
            error_log("Exception: " . $e->getMessage());
            
            return false; // Failure
        }
    }
}