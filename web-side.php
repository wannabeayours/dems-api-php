<?php

include 'header.php';

// ------------------------------------------- For Website ------------------------------------------- //

class Web_Tools_Functions
{

    function get_CurrDate()
    {
        $today = new DateTime("now", new DateTimeZone("Asia/Manila"));
        return $today->format('Y-m-d h:i:s A');
    }

    function customer_login($data)
    {
        include "connection.php";
        date_default_timezone_set('Asia/Manila'); // Ensure correct timezone

        $emailData = $data["email"];
        $emailPass = $data["password"];

        // Checks user if Blocked
        $stmt = $pdo->prepare("SELECT attempted_tries, attempted_until FROM tbl_loggedin_attempts WHERE attempted_email = ?");
        $stmt->execute([$emailData]);
        $user_attempt = $stmt->fetch(PDO::FETCH_ASSOC);

        $currentTime = $this->get_CurrDate();

        if ($user_attempt && $user_attempt["attempted_tries"] >= 5 && strtotime($user_attempt["attempted_until"]) > strtotime($currentTime)) {
            return json_encode(["response" => false, "blocked" => true, "message" => "Too many attempts"]);
            exit;
        }

        // Checks if email exists
        $stmt = $pdo->prepare("SELECT * FROM tbl_customers WHERE customers_email = ?");
        $stmt->execute([$emailData]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // Checks if User Exists
        if (!$user || !password_verify($emailPass, $user["customers_password"])) {
            if ($user_attempt) {
                $addAttempt = $user_attempt["attempted_tries"] + 1;
                $lockedOut = ($user_attempt["attempted_tries"] >= 4) ? date("Y-m-d H:i:s", strtotime("+5 minutes")) : NULL;

                $stmt = $pdo->prepare("UPDATE tbl_loggedin_attempts SET attempted_tries = ?, attempted_until =? WHERE attempted_email  = ?");
                $stmt->execute([$addAttempt, $lockedOut, $emailData]);
            } else {
                // If user has no previous failed attempts
                $stmt = $pdo->prepare("INSERT INTO tbl_loggedin_attempts (attempted_email, attempted_tries, attempted_until) VALUES(?, 1, NULL)");
                $stmt->execute([$emailData]);
            }
            unset($stmt, $pdo); // *New*
            return json_encode(["response" => false, "message" => "Wrong Credentials"]);
            exit;
        }

        // If Successful, reset failed attempts
        $stmt = $pdo->prepare("DELETE FROM tbl_loggedin_attempts WHERE attempted_email = ?");
        $stmt->execute([$emailData]);
        return json_encode(["response" => true, "message" => "User Successfully Logged In!", "userData" => $user]);
        unset($stmt, $pdo); // *New*
    }

    // Sends OTP to email
    function sendOTP($data)
    {
        include 'connection.php';
        include 'mail_test.php';
        $generatedOTP = mt_rand(100000, 999999);

        $stmt = $pdo->prepare("SELECT * FROM tbl_customers WHERE customers_email = ?");
        $stmt->execute([$data["email"]]);
        $user_email = $stmt->fetch(PDO::FETCH_ASSOC);

        // Checks if the email exists
        if (!$user_email) {
            return json_encode(["response" => false, "message" => "Email does not exist"]);
            exit;
        }

        $sendEmail = new Send_to_Email();
        $email = $user_email["customers_email"];
        $firstName = $user_email["customers_fname"];
        $lastName = $user_email["customers_lname"];
        $sendEmail->send_OTP(
            $email,
            "Reset Password  for Demiren Customer Account",
            "Hello $lastName, $firstName. </br> Here is your One Time Password: $generatedOTP"
        );

        ob_clean();
        return json_encode(["response" => true, "otp_code" => $generatedOTP]);
    }

    // Changes Forget Password
    function iForgor($data)
    {
        include 'connection.php';

        $stmt = $pdo->prepare("SELECT * FROM tbl_customers WHERE customers_email = ?");
        $stmt->execute([$data["verifyEmail"]]);
        $customer = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$customer) {
            return json_encode(["response" => false, "message" => "Could not find anyone with the existing email"]);
            exit;
        }

        $hashNewPassword = password_hash($data["newPass"], PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE tbl_customers SET customers_password = :password WHERE customers_email = :email");
        $stmt->bindParam(":password", $hashNewPassword);
        $stmt->bindParam(":email", $customer["customers_email"]);
        $stmt->execute();
        $checkChanged = $stmt->rowCount() > 0;

        if ($checkChanged) {
            return json_encode(["response" => true, "message" => "Successfully Updated Password"]);
        }
        return json_encode(["response" => false, "message" => "Failed to Update Password"]);
    }

    // Create New Account
    function new_account($data)
    {
        include "connection.php";

        $checkSql = "SELECT COUNT(*) FROM tbl_customers WHERE customers_email = :email";
        $checkStmt = $pdo->prepare($checkSql);
        $checkStmt->bindParam(":email", $data["email"]);
        $checkStmt->execute();
        $emailExists = $checkStmt->fetchColumn();

        if ($emailExists > 0) {
            return $rowCount > 0 ? json_encode(["response" => true, "message" => "Email already Exists"]) : 0;
            exit();
        }

        $hashedPassword = password_hash($data["password"], PASSWORD_DEFAULT);

        $sql = "INSERT INTO tbl_customers(customer_user_level_id, customers_fname, customers_lname, customers_country, 
        customers_email, customers_phone, customers_age, customers_password) 
        VALUES (1, :fname, :lname, :country, :email, :phone, :age, :code)";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(":fname", $data["firstName"]);
        $stmt->bindParam(":lname", $data["lastName"]);
        $stmt->bindParam(":country", $data["country"]);
        $stmt->bindParam(":email", $data["email"]);
        $stmt->bindParam(":phone", $data["phone"]);
        $stmt->bindParam(":age", $data["age"]);
        $stmt->bindParam(":code", $hashedPassword);
        $stmt->execute();
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $rowCount = $stmt->rowCount();
        unset($stmt, $pdo);

        return $rowCount > 0 ? json_encode($result) : 0;
    }

    function addReservation_Request($data)
    {
        include "connection.php";
        $time_n_date = $data["timeAndDate_json"];
        $rooms = $data["rooms_json"];

        $returnValue = $this->new_timeAndDate($time_n_date);
        if ($returnValue) {
            // $this
        }
    }

    // Adds new reservation
    function new_reservation($data)
    {
        include "connection.php";

        $sql = "INSERT INTO  tbl_booking_online(booking_online_customers_id, booking_online_roomtype_id, booking_online_num_of_customers,
                                    booking_online_adult, booking_online_children_age, booking_online_total, booking_online_downpayment,
                                    booking_online_balance, timeanddate_time_arrival, timeanddate_date_arrival, timeanddate_created_at,
                                    timeanddate_updated_at) 
                VALUES(:customer, :roomType, :amnt_of_cust, :adult, :child_age, :total, :downpayment, :balance, :time_arrive, :date_arrive, 
                        :createdAt, :updatedAt)";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(":customer", $data["customer_id"]);
        $stmt->bindParam(":roomType", $data[""]);
        $stmt->bindParam(":amnt_of_cust", $data[""]);
        $stmt->bindParam(":num_of_customers", $data[""]);
        $stmt->bindParam(":adult", $data[""]);
        $stmt->bindParam(":child_age", $data[""]);
        $stmt->bindParam(":total", $data[""]);
        $stmt->bindParam(":downpayment", $data[""]);
        $stmt->bindParam(":balance", $data[""]);
        $stmt->bindParam(":time_arrive", $data[""]);
        $stmt->bindParam(":date_arrive", $data[""]);
        $stmt->execute();
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $rowCount = $stmt->rowCount();
        unset($stmt, $pdo);

        return $rowCount > 0 ? json_encode($result) : 0;
    }

    // Adds New Time and Date
    function new_timeAndDate($data)
    {
        include "connection.php";

        $sql = "INSERT INTO tbl_timeanddate(timeanddate_customers_id, timeanddate_child_age_id, timeanddate_time_arrival, 
                    timeanddate_date_arrival, timeanddate_created_at, timeanddate_updated_at) 
                VALUES(:cust_id, :childAge_id, :time_arrived, :date_arrived, :created, :updated)";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(":cust_id", $data["customer_id"]);
        $stmt->bindParam(":childAge_id", $data["child_age"]);
        $stmt->bindParam(":time_arrived", $data["arrived_time"]);
        $stmt->bindParam(":date_arrived", $data["arrived_date"]);
        $stmt->bindParam(":created", $data["created_at"]);
        $stmt->bindParam(":updated", $data["updated_at"]);
        $stmt->execute();
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $rowCount = $stmt->rowCount();
        unset($stmt, $pdo);

        return $rowCount > 0 ? json_encode($result) : 0;
    }

    // ---------------- Read Data ---------------- //
    // Display only the Current User's Check Ins and Outs
    function display_signins($data)
    {
        include "connection.php";

        $sql = "SELECT checkin_checkout_id, checkin_date, checkin_time, checkout_date, checkout_time, checkin_checkout_status, 
                    reservation_online_downpayment FROM tbl_checkin_checkout 
                WHERE checkin_checkout_customers_id = :cust_id";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(":cust_id", $data[""]);
        $stmt->execute();
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $rowCount = $stmt->rowCount();

        return $rowCount > 0 ? json_encode($result) : 0;
    }

    // Displays Current Customer Information
    function display_myInfo($data)
    {
        include "connection.php";

        $sql = "SELECT customers_fname, customers_lname, customers_country, customers_email, customers_phone, customers_age FROM tbl_customers
                WHERE customers_id = :cust_id";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(":cust_id", $data[""]);
        $stmt->execute();
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $rowCount = $stmt->rowCount();

        return $rowCount > 0 ? json_encode($result) : 0;
    }

    // Shows All Available Room
    function display_rooms()
    {
        include "connection.php";

        $sql = "SELECT * FROM tbl_rooms";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $rowCount = $stmt->rowCount();

        return $rowCount > 0 ? json_encode($result) : 0;
    }


    // ---------------- Update Data ---------------- //
    // Customer Updates Account
    function update_account($data)
    {
        include "connection.php";

        $sql = "UPDATE tbl_customers SET customers_fname = :fname, customers_lname = :lname, customers_country = :country, customers_email = :email, 
                    customers_phone = :phone, customers_age = :age
                WHERE customers_id = :cust_id";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(":cust_id", $data[""]);
        $stmt->bindParam(":fname", $data[""]);
        $stmt->bindParam(":lname", $data[""]);
        $stmt->bindParam(":country", $data[""]);
        $stmt->bindParam(":email", $data[""]);
        $stmt->bindParam(":phone", $data[""]);
        $stmt->bindParam(":age", $data[""]);
        $stmt->execute();
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $rowCount = $stmt->rowCount();
        unset($stmt, $pdo);

        return $rowCount > 0 ? json_encode($result) : 0;
    }

    // Changes Attempts after a certain amount of time has
    function change_attempts() {}


    // ---------------- Update Data ---------------- //
    // Deletes the 
}

$web_tools = new Web_Tools_Functions();
$methodType = isset($_POST["method"]) ? $_POST["method"] : 0;
$jsonData = isset($_POST["json"]) ? json_decode($_POST["json"], true) : 0;

switch ($methodType) {
    // Login
    case "submit-login":
        echo $web_tools->customer_login($jsonData);
        break;

    // Send OTP
    case "emailOTP":
        echo $web_tools->sendOTP($jsonData);
        break;

    // Forgot Passoword
    case "forgotPass":
        echo $web_tools->iForgor($jsonData);
        break;

    // Create Methods
    case "addUser":
        echo $web_tools->new_account($jsonData);
        break;

    case "addReservation":
        echo $web_tools->addReservation_Request($jsonData);
        break;

    // Read Methods
    case "display-all-rooms":
        echo $web_tools->display_rooms();
        break;

    // Update Methods
    default:
        return json_encode(["response" => false, "message" => "Request Denied"]);
}
