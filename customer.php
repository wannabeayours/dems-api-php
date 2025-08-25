<?php

include "headers.php";

class Demiren_customer
{
    function customerProfile($json)
    {
        // {"customers_id":1}
        include "connection.php";
        $json = json_decode($json, true);
        $sql = "SELECT a.customers_fname, a.customers_lname, a.customers_email, a.customers_phone_number, a.customers_date_of_birth, b.nationality_id, b.nationality_name, c.customer_identification_attachment_filename, d.customers_online_username, d.customers_online_profile_image, d.customers_online_authentication_status
                FROM tbl_customers AS a
                INNER JOIN tbl_nationality AS b ON b.nationality_id = a.nationality_id
                INNER JOIN tbl_customer_identification AS c ON c.identification_id = a.identification_id
                INNER JOIN tbl_customers_online AS d ON d.customers_online_id = a.customers_online_id
                WHERE a.customers_id = :customers_id";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":customers_id", $json["customers_id"]);
        $stmt->execute();
        return json_encode($stmt->rowCount() > 0 ? $stmt->fetch(PDO::FETCH_ASSOC) : 0);
    }

    function customerUpdateProfile($json)
    {
        include "connection.php";
        try {
            $json = json_decode($json, true);
            if (empty($json["customers_id"])) {
                return 0;
            }

            $customers_id = $json["customers_id"];

            $conn->beginTransaction();

            $stmt = $conn->prepare("
                SELECT a.customers_fname, a.customers_lname, a.customers_phone_number, 
                a.customers_email, a.customers_date_of_birth, b.nationality_name, 
                d.customers_online_username, a.nationality_id, a.customers_online_id
                FROM tbl_customers AS a
                INNER JOIN tbl_nationality AS b ON b.nationality_id = a.nationality_id
                INNER JOIN tbl_customers_online AS d ON d.customers_online_id = a.customers_online_id
                WHERE a.customers_id = :customers_id
            ");
            $stmt->execute([":customers_id" => $customers_id]);
            $currentData = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$currentData) {
                $conn->rollBack();
                return 0;
            }

            // Update first name if changed
            if (isset($json["customers_fname"]) && $json["customers_fname"] !== $currentData["customers_fname"]) {
                $stmt = $conn->prepare("
                    UPDATE tbl_customers 
                    SET customers_fname = :customers_fname 
                    WHERE customers_id = :customers_id
                ");
                $stmt->execute([
                    ":customers_fname" => $json["customers_fname"],
                    ":customers_id" => $customers_id
                ]);
            }

            // Update last name if changed
            if (isset($json["customers_lname"]) && $json["customers_lname"] !== $currentData["customers_lname"]) {
                $stmt = $conn->prepare("
                    UPDATE tbl_customers 
                    SET customers_lname = :customers_lname 
                    WHERE customers_id = :customers_id
                ");
                $stmt->execute([
                    ":customers_lname" => $json["customers_lname"],
                    ":customers_id" => $customers_id
                ]);
            }

            // Update phone number if changed
            if (isset($json["customers_phone_number"]) && $json["customers_phone_number"] !== $currentData["customers_phone_number"]) {
                $stmt = $conn->prepare("
                    UPDATE tbl_customers 
                    SET customers_phone_number = :customers_phone_number 
                    WHERE customers_id = :customers_id
                ");
                $stmt->execute([
                    ":customers_phone_number" => $json["customers_phone_number"],
                    ":customers_id" => $customers_id
                ]);
            }

            // Update email if changed
            if (isset($json["customers_email"]) && $json["customers_email"] !== $currentData["customers_email"]) {
                // First verify email doesn't already exist
                $stmt = $conn->prepare("
                    SELECT customers_id FROM tbl_customers 
                    WHERE customers_email = :customers_email AND customers_id != :customers_id
                ");
                $stmt->execute([
                    ":customers_email" => $json["customers_email"],
                    ":customers_id" => $customers_id
                ]);

                if ($stmt->fetch()) {
                    $conn->rollBack();
                    return -1; // Special code for duplicate email
                }

                $stmt = $conn->prepare("
                    UPDATE tbl_customers 
                    SET customers_email = :customers_email 
                    WHERE customers_id = :customers_id
                ");
                $stmt->execute([
                    ":customers_email" => $json["customers_email"],
                    ":customers_id" => $customers_id
                ]);
            }

            // Update nationality if changed
            if (isset($json["nationality_id"]) && $json["nationality_id"] != $currentData["nationality_id"]) {
                $stmt = $conn->prepare("
                UPDATE tbl_customers 
                SET nationality_id = :nationality_id 
                WHERE customers_id = :customers_id
            ");
                $stmt->execute([
                    ":nationality_id" => $json["nationality_id"],
                    ":customers_id" => $customers_id
                ]);
            }
            // Update username if changed
            if (isset($json["customers_online_username"]) && $json["customers_online_username"] !== $currentData["customers_online_username"]) {
                $stmt = $conn->prepare("
                    UPDATE tbl_customers_online 
                    SET customers_online_username = :customers_online_username 
                    WHERE customers_online_id = :customers_online_id
                ");
                $stmt->execute([
                    ":customers_online_username" => $json["customers_online_username"],
                    ":customers_online_id" => $currentData["customers_online_id"]
                ]);
            }

            // Update date of birth if changed
            if (isset($json["customers_date_of_birth"]) && $json["customers_date_of_birth"] !== $currentData["customers_date_of_birth"]) {
                $stmt = $conn->prepare("
                    UPDATE tbl_customers 
                    SET customers_date_of_birth = :customers_date_of_birth 
                    WHERE customers_id = :customers_id
                ");
                $stmt->execute([
                    ":customers_date_of_birth" => $json["customers_date_of_birth"],
                    ":customers_id" => $customers_id
                ]);
            }

            $conn->commit();
            return 1;
        } catch (PDOException $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            error_log("PDOException: " . $e->getMessage());
            return $e;
        } catch (Exception $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            error_log("Exception: " . $e->getMessage());
            return -100;
        }
    }
    //date 


    function customerChangePassword($json)
    {
        include "connection.php";
        try {
            $json = json_decode($json, true);

            // 1. First verify the current password
            $sql = "SELECT customers_online_password
                    FROM tbl_customers_online 
                    WHERE customers_online_id = :customers_online_id";
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(":customers_online_id", $json["customers_online_id"]);
            $stmt->execute();

            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$result) {
                return 0; // User not found
            }

            // 2. Compare current password with stored password
            if ($json["current_password"] !== $result["customers_online_password"]) {
                return -1; // Current password is wrong
            }
            $newPassword = $json["new_password"];

            // 4. Update the password
            $sql = "UPDATE tbl_customers_online 
                    SET customers_online_password = :customers_online_password 
                    WHERE customers_online_id = :customers_online_id";
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(":customers_online_password", $newPassword);
            $stmt->bindParam(":customers_online_id", $json["customers_online_id"]);
            $stmt->execute();

            return $stmt->rowCount() > 0 ? 1 : 0;
        } catch (PDOException $e) {
            error_log("Password change error: " . $e->getMessage());
            return 0;
        }
    }

    function customerChangeEmail($json)
    {
        include "connection.php";
        $json = json_decode($json, true);
        $sql = "UPDATE tbl_customers_online SET customers_online_email = :customers_online_email WHERE customers_online_id = :customers_online_id";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":customers_online_email", $json["customers_online_email"]);
        $stmt->bindParam(":customers_online_id", $json["customers_online_id"]);
        $stmt->execute();
        return $stmt->rowCount() > 0 ? 1 : 0;
    }
    //for now


    function customerChangeAuthenticationStatus($json)
    {
        include "connection.php";
        $json = json_decode($json, true);
        $sql = "UPDATE tbl_customers_online SET customers_online_authentication_status = :customers_online_authentication_status WHERE customers_online_id = :customers_online_id";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":customers_online_authentication_status", $json["customers_online_authentication_status"]);
        $stmt->bindParam(":customers_online_id", $json["customers_online_id"]);
        $stmt->execute();
        return $stmt->rowCount() > 0 ? 1 : 0;
    }

    function customerForgotPassword($json)
    {
        include "connection.php";
        $json = json_decode($json, true);
        $sql = "UPDATE tbl_customers_online SET customers_online_password = :customers_online_password WHERE customers_online_email = :customers_online_email";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":customers_online_password", $json["customers_online_password"]);
        $stmt->bindParam(":customers_online_email", $json["customers_online_email"]);
        $stmt->execute();
        return $stmt->rowCount() > 0 ? 1 : 0;
    }

    // New Method
    function customerBookingNoAccount($json)
    {
        include "connection.php";
        $json = json_decode($json, true);

        try {
            $conn->beginTransaction();

            // Insert walk-in customer
            $stmt = $conn->prepare("
                INSERT INTO tbl_customers_walk_in 
                    (customers_walk_in_fname, customers_walk_in_lname, customers_walk_in_email, customers_walk_in_phone_number) 
                VALUES 
                    (:customers_walk_in_fname, :customers_walk_in_lname, :customers_walk_in_email, :customers_walk_in_phone_number)
            ");
            $stmt->bindParam(":customers_walk_in_fname", $json["customers_walk_in_fname"]);
            $stmt->bindParam(":customers_walk_in_lname", $json["customers_walk_in_lname"]);
            $stmt->bindParam(":customers_walk_in_email", $json["customers_walk_in_email"]);
            $stmt->bindParam(":customers_walk_in_phone_number", $json["customers_walk_in_phone_number"]);
            $stmt->execute();
            $walkInCustomerId = $conn->lastInsertId();

            // Insert booking
            $stmt = $conn->prepare("
                INSERT INTO tbl_booking 
                    (customers_id, customers_walk_in_id, booking_downpayment, booking_checkin_dateandtime, booking_checkout_dateandtime, booking_created_at) 
                VALUES 
                    (NULL, :customers_walk_in_id, :booking_downpayment, :booking_checkin_dateandtime, :booking_checkout_dateandtime, NOW())
            ");
            $stmt->bindParam(":customers_walk_in_id", $walkInCustomerId);
            $stmt->bindParam(":booking_downpayment", $json["booking_downpayment"]);
            $stmt->bindParam(":booking_checkin_dateandtime", $json["booking_checkin_dateandtime"]);
            $stmt->bindParam(":booking_checkout_dateandtime", $json["booking_checkout_dateandtime"]);
            $stmt->execute();
            $bookingId = $conn->lastInsertId();

            // Insert into tbl_booking_room based on room quantity
            $roomtype_id = $json["roomtype_id"];
            $room_count = intval($json["room_count"]);

            for ($i = 0; $i < $room_count; $i++) {
                $stmt = $conn->prepare("
                    INSERT INTO tbl_booking_room 
                        (booking_id, roomtype_id, roomnumber_id) 
                    VALUES 
                        (:booking_id, :roomtype_id, NULL)
                ");
                $stmt->bindParam(":booking_id", $bookingId);
                $stmt->bindParam(":roomtype_id", $roomtype_id);
                $stmt->execute();
            }

            $conn->commit();
            return 1;
        } catch (PDOException $e) {
            $conn->rollBack();
            return 0;
        }
    }

    // Old Method
    // function customerBookingNoAccount($json)
    // {
    //     include "connection.php";
    //     $json = json_decode($json, true);

    //     try {
    //         $conn->beginTransaction();

    //         // Insert walk-in customer
    //         $stmt = $conn->prepare("
    //             INSERT INTO tbl_customers_walk_in 
    //                 (customers_walk_in_fname, customers_walk_in_lname, customers_walk_in_email, customers_walk_in_phone_number) 
    //             VALUES 
    //                 (:customers_walk_in_fname, :customers_walk_in_lname, :customers_walk_in_email, :customers_walk_in_phone_number)
    //         ");
    //         $stmt->bindParam(":customers_walk_in_fname", $json["customers_walk_in_fname"]);
    //         $stmt->bindParam(":customers_walk_in_lname", $json["customers_walk_in_lname"]);
    //         $stmt->bindParam(":customers_walk_in_email", $json["customers_walk_in_email"]);
    //         $stmt->bindParam(":customers_walk_in_phone_number", $json["customers_walk_in_phone_number"]);
    //         $stmt->execute();
    //         $walkInCustomerId = $conn->lastInsertId();

    //         // Insert booking
    //         $stmt = $conn->prepare("
    //             INSERT INTO tbl_booking 
    //                 (customers_id, customers_walk_in_id, booking_status_id, booking_downpayment, booking_checkin_dateandtime, booking_checkout_dateandtime, booking_created_at) 
    //             VALUES 
    //                 (NULL, :customers_walk_in_id, 2, :booking_downpayment, :booking_checkin_dateandtime, :booking_checkout_dateandtime, NOW())
    //         ");
    //         $stmt->bindParam(":customers_walk_in_id", $walkInCustomerId);
    //         $stmt->bindParam(":booking_downpayment", $json["booking_downpayment"]);
    //         $stmt->bindParam(":booking_checkin_dateandtime", $json["booking_checkin_dateandtime"]);
    //         $stmt->bindParam(":booking_checkout_dateandtime", $json["booking_checkout_dateandtime"]);
    //         $stmt->execute();
    //         $bookingId = $conn->lastInsertId();

    //         // Insert into tbl_booking_room based on room quantity
    //         $roomtype_id = $json["roomtype_id"];
    //         $room_count = intval($json["room_count"]);

    //         for ($i = 0; $i < $room_count; $i++) {
    //             $stmt = $conn->prepare("
    //                 INSERT INTO tbl_booking_room 
    //                     (booking_id, roomtype_id, roomnumber_id) 
    //                 VALUES 
    //                     (:booking_id, :roomtype_id, NULL)
    //             ");
    //             $stmt->bindParam(":booking_id", $bookingId);
    //             $stmt->bindParam(":roomtype_id", $roomtype_id);
    //             $stmt->execute();
    //         }

    //         $conn->commit();
    //         return 1;
    //     } catch (PDOException $e) {
    //         $conn->rollBack();
    //         return 0;
    //     }
    // }

    function customerViewBookings($json)
    {
        include "connection.php";
        $json = json_decode($json, true);
        $bookingCustomerId = $json['booking_customer_id'] ?? 0;

        $sql = "SELECT a.*, b.*, c.*, d.*
            FROM tbl_booking a
            INNER JOIN tbl_booking_room b ON b.booking_id = a.booking_id
            INNER JOIN tbl_roomtype c ON c.roomtype_id = b.roomtype_id
            INNER JOIN tbl_rooms d ON d.roomtype_id = c.roomtype_id
            WHERE 
                (a.customers_id = :bookingCustomerId OR a.customers_walk_in_id = :bookingCustomerId)
                AND a.booking_id NOT IN (
                    SELECT booking_id 
                    FROM tbl_booking_history 
                    WHERE booking_id = a.booking_id
                )
            ORDER BY a.booking_created_at DESC";

        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':bookingCustomerId', $bookingCustomerId);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $bookings = [];

        // echo json_encode($rows);
        // die();

        foreach ($rows as $row) {
            $bookingId = $row['booking_id'];

            if (!isset($bookings[$bookingId])) {
                $bookings[$bookingId] = [
                    "booking_id" => $row['booking_id'],
                    "booking_totalAmount" => $row['booking_totalAmount'],
                    "customers_id" => $row['customers_id'],
                    "customers_walk_in_id" => $row['customers_walk_in_id'],
                    "adult" => $row['adult'],
                    "children" => $row['children'],
                    "guests_amnt" => $row['guests_amnt'],
                    "booking_totalAmount" => $row['booking_totalAmount'],
                    "booking_downpayment" => $row['booking_downpayment'],
                    "reference_no" => $row['reference_no'],
                    "booking_checkin_dateandtime" => $row['booking_checkin_dateandtime'],
                    "booking_checkout_dateandtime" => $row['booking_checkout_dateandtime'],
                    "booking_created_at" => $row['booking_created_at'],
                    "booking_isArchive" => $row['booking_isArchive'],
                    "roomsList" => []
                ];
            }

            $bookings[$bookingId]['roomsList'][] = [
                "booking_room_id" => $row['booking_room_id'],
                "roomtype_id" => $row['roomtype_id'],
                "roomtype_name" => $row['roomtype_name'],
                "max_capacity" => $row['max_capacity'],
                "roomtype_description" => $row['roomtype_description'],
                "roomtype_price" => $row['roomtype_price'],
                "roomnumber_id" => $row['roomnumber_id'],
                "roomfloor" => $row['roomfloor'],
                "room_status_id" => $row['room_status_id'],
                "room_capacity" => $row['room_capacity'],
                "room_beds" => $row['room_beds'],
                "room_sizes" => $row['room_sizes']
            ];
        }

        // Return as an indexed array
        return array_values($bookings);
    }

    function customerCurrentBookingsWithAccount($json)
    {
        // {"booking_customer_id": 1}
        include "connection.php";
        $json = json_decode($json, true);

        $bookingCustomerId = $json['booking_customer_id'] ?? 0;

        $sql = "SELECT 
                a.roomtype_name,
                b.roomnumber_id,
                c.booking_downpayment,
                e.room_beds,
                e.room_sizes,
                c.booking_created_at,
                d.booking_status_name,
                c.booking_checkin_dateandtime,
                c.booking_checkout_dateandtime
            FROM tbl_roomtype AS a
            LEFT JOIN tbl_booking_room AS b ON b.roomtype_id = a.roomtype_id
            LEFT JOIN tbl_booking AS c ON c.booking_id = b.booking_id
            LEFT JOIN tbl_rooms AS e ON e.roomtype_id = a.roomtype_id
            LEFT JOIN tbl_booking_history AS f ON f.booking_id = c.booking_id
            LEFT JOIN tbl_booking_status AS d ON d.booking_status_id = f.status_id
            WHERE c.customers_id = :bookingCustomerId AND f.status_id IN (1, 2)";

        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':bookingCustomerId', $bookingCustomerId);
        $stmt->execute();
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return json_encode($result);
    }

    function customerCurrentBookingsWithoutAccount($json)
    {
        include "connection.php";
        $json = json_decode($json, true);

        $bookingReferenceNumber = $json['booking_reference_number'] ?? 0;

        $sql = "SELECT 
                a.roomtype_name,
                b.roomnumber_id,
                c.booking_downpayment,
                e.room_beds,
                e.room_sizes,
                c.booking_created_at,
                d.booking_status_name,
                c.booking_checkin_dateandtime,
                c.booking_checkout_dateandtime
            FROM tbl_roomtype AS a
            INNER JOIN tbl_booking_room AS b ON b.roomtype_id = a.roomtype_id
            INNER JOIN tbl_booking AS c ON c.booking_id = b.booking_id
            INNER JOIN tbl_booking_status AS d ON d.booking_status_id = c.booking_status_id
            INNER JOIN tbl_rooms AS e ON e.roomtype_id = a.roomtype_id
            WHERE c.booking_reference_number = :bookingReferenceNumber AND c.booking_status_id IN (1, 2) LIMIT 1";

        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':bookingReferenceNumber', $bookingReferenceNumber);
        $stmt->execute();
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return json_encode($result);
    }

    function customerFeedBack($json)
    {
        include "connection.php";
        $json = json_decode($json, true);
        $sql = "INSERT INTO tbl_customersreviews (customers_id, customersreviews, customersreviews_hospitality_rate,	customersreviews_behavior_rate, customersreviews_facilities_rate, customersreviews_cleanliness_rate, customersreviews_foods_rate ) VALUES (:customers_id, :customersreviews, :customersreviews_hospitality_rate, :customersreviews_behavior_rate, :customersreviews_facilities_rate, :customersreviews_cleanliness_rate, :customersreviews_foods_rate)";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":customersreviews", $json["customersreviews"]);
        $stmt->bindParam(":customersreviews_hospitality_rate", $json["customersreviews_hospitality_rate"]);
        $stmt->bindParam(":customersreviews_behavior_rate", $json["customersreviews_behavior_rate"]);
        $stmt->bindParam(":customersreviews_facilities_rate", $json["customersreviews_facilities_rate"]);
        $stmt->bindParam(":customersreviews_cleanliness_rate", $json["customersreviews_cleanliness_rate"]);
        $stmt->bindParam(":customersreviews_foods_rate", $json["customersreviews_foods_rate"]);
        $stmt->bindParam(":customers_id", $json["customers_id"]);
        $stmt->execute();
        return $stmt->rowCount() > 0 ? 1 : 0;
    }

    function customerCancelBooking($json)
    {
        include "connection.php";
        $json = json_decode($json, true);
        $sql = "INSERT INTO tbl_booking_history (booking_id, status_id) VALUES (:booking_id, 4)";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":booking_id", $json["booking_id"]);
        $stmt->execute();
        return $stmt->rowCount() > 0 ? 1 : 0;
    }

    function sendEmail($json)
    {
        include "send_email.php";
        $json = json_decode($json, true);

        // Extract or default values
        $emailTo = $json['emailToSent'] ?? null;
        $emailSubject = $json['emailSubject'] ?? "Demiren Hotel & Restaurant";
        $confirmationNumber = $json['confirmationNumber'] ?? "N/A";

        // Construct designed email body
        $emailBody = '
        <html>
        <head>
        <style>
            body { font-family: Arial, sans-serif; color: #333; background-color: #f9f9f9; padding: 20px; }
            .container { background-color: #fff; border-radius: 10px; padding: 20px; max-width: 600px; margin: auto; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
            h2 { color: #1a73e8; }
            .details { margin-top: 20px; }
            .label { font-weight: bold; color: #555; }
            .value { margin-bottom: 10px; }
            .code { font-size: 20px; font-weight: bold; color: #444; background: #f0f0f0; padding: 10px; border-radius: 5px; text-align: center; margin: 20px 0; }
            .footer { font-size: 12px; color: #777; margin-top: 30px; text-align: center; }
        </style>
        </head>
        <body>
        <div class="container">
            <h2>Booking Confirmation</h2>
            <p>Thank you for choosing <strong>Demiren Hotel & Restaurant</strong>.</p>
            <p>Your booking has been confirmed successfully.</p>
            
            <div class="code">Confirmation #: ABC1234</div>

            <div class="details">
            <p><span class="label">Room Number:</span> Room 205</p>
            <p><span class="label">Check-in Date:</span> May 5, 2025</p>
            <p><span class="label">Check-out Date:</span> May 7, 2025</p>
            </div>

            <p>We look forward to welcoming you!</p>

            <div class="footer">
            This is an automated message. Please do not reply to this email.
            </div>
        </div>
        </body>
        </html>';


        $sendEmail = new SendEmail();
        return $sendEmail->sendEmail($emailTo, $emailSubject, $emailBody);
    }

    function getNationality()
    {
        include "connection.php";
        $sql = "SELECT * FROM tbl_nationality";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        return $stmt->rowCount() > 0 ? $stmt->fetchAll(PDO::FETCH_ASSOC) : 0;
    }


    function getCustomerAuthenticationStatus($json)
    {
        // {"customers_online_id": 1}
        include "connection.php";
        $data = json_decode($json, true);
        $sql = "SELECT customers_online_authentication_status FROM tbl_customers_online WHERE customers_online_id = :customers_online_id";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":customers_online_id", $data["customers_online_id"]);
        $stmt->execute();
        $returnValue = $stmt->fetch(PDO::FETCH_ASSOC);
        return $returnValue["customers_online_authentication_status"] ?? 0;
    }
    function getRooms()
    {
        include "connection.php";

        $sql = "SELECT b.roomtype_id AS room_type,
        b.max_capacity,
        b.roomtype_description,
        b.roomtype_name, 
        GROUP_CONCAT(DISTINCT a.imagesroommaster_filename) AS images,
        b.roomtype_price, 
        GROUP_CONCAT(DISTINCT c.roomnumber_id) AS room_ids,
        GROUP_CONCAT(DISTINCT  c.room_beds) AS room_beds,
        GROUP_CONCAT(DISTINCT c.room_capacity) AS room_capacity,
        GROUP_CONCAT(DISTINCT c.room_sizes) AS room_sizes,
        -- GROUP_CONCAT(DISTINCT e.room_amenities_master_name) AS amenities,
        f.status_name,
        f.status_id
        FROM tbl_roomtype b
        LEFT JOIN tbl_imagesroommaster a ON a.roomtype_id = b.roomtype_id
        LEFT JOIN tbl_rooms c ON b.roomtype_id = c.roomtype_id
        -- LEFT JOIN tbl_room_amenities_master e ON d.room_amenities_master = e.room_amenities_master_id
        LEFT JOIN tbl_status_types f ON f.status_id = c.room_status_id
        GROUP BY b.roomtype_id, b.roomtype_name, b.roomtype_price
        ";

        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $rowCount = $stmt->rowCount();
        unset($stmt, $pdo);

        return $rowCount > 0 ? json_encode($result) : 0;
    }

    function getFeedbacks()
    {
        include "connection.php";
        $sql = "SELECT CONCAT(b.customers_fname,' ',b.customers_lname) as customer_fullname,a.* FROM `tbl_customersreviews` a
                INNER JOIN tbl_customers b ON b.customers_id = a.customers_id";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        return $stmt->rowCount() > 0 ? $stmt->fetchAll(PDO::FETCH_ASSOC) : 0;
    }
    function customerRegistration($json)
    {
        include "connection.php";
        $json = json_decode($json, true);

        try {
            $conn->beginTransaction();


            $stmt = $conn->prepare("
                INSERT INTO tbl_customers_online (
                    customers_online_username, 
                    customers_online_password, 
                    customers_online_profile_image,
                    customers_online_authentication_status
                ) VALUES (
                    :customers_online_username, 
                    :customers_online_password, 
                    :customers_online_profile_image,
                    0
                )
            ");
            $stmt->bindParam(':customers_online_username', $json['customers_online_username']);
            $stmt->bindParam(':customers_online_password', $json['customers_online_password']);
            $stmt->bindParam(':customers_online_profile_image', $json['customers_online_profile_image']);
            $stmt->execute();
            $customers_online_id = $conn->lastInsertId();


            $stmt = $conn->prepare("
                INSERT INTO tbl_customer_identification (
                    customer_identification_attachment_filename
                ) VALUES (
                    :customer_identification_attachment_filename
                )
            ");
            $stmt->bindParam(':customer_identification_attachment_filename', $json['customer_identification_attachment_filename']);
            $stmt->execute();
            $identification_id = $conn->lastInsertId();


            $stmt = $conn->prepare("
                INSERT INTO tbl_customers (
                    nationality_id,
                    identification_id,
                    customers_online_id,
                    customers_fname,
                    customers_lname,
                    customers_email,
                    customers_phone_number,
                    customers_date_of_birth
                ) VALUES (
                    :nationality_id,
                    :identification_id,
                    :customers_online_id,
                    :customers_fname,
                    :customers_lname,
                    :customers_email,
                    :customers_phone_number,
                    :customers_date_of_birth
                )
            ");
            $stmt->bindParam(':nationality_id', $json['nationality_id']);
            $stmt->bindParam(':identification_id', $identification_id);
            $stmt->bindParam(':customers_online_id', $customers_online_id);
            $stmt->bindParam(':customers_fname', $json['customers_fname']);
            $stmt->bindParam(':customers_lname', $json['customers_lname']);
            $stmt->bindParam(':customers_email', $json['customers_email']);
            $stmt->bindParam(':customers_phone_number', $json['customers_phone_number']);
            $stmt->bindParam(':customers_date_of_birth', $json['customers_date_of_birth']);
            $stmt->execute();

            $conn->commit();
            return 1;
        } catch (PDOException $e) {
            $conn->rollBack();
            return $e->getMessage();
        }
    }

    function customerCurrentBookings($json)
    {
        include "connection.php";
        $json = json_decode($json, true);

        $bookingCustomerId = $json['booking_customer_id'] ?? 0;

        $sql = "SELECT 
                c.booking_id,
                a.roomtype_name,
                e.roomnumber_id,
                c.booking_downpayment,
                e.room_beds,
                e.room_sizes,
                c.booking_created_at,
                d.booking_status_name,
                c.booking_checkin_dateandtime,
                c.booking_checkout_dateandtime
            FROM tbl_roomtype AS a
            INNER JOIN tbl_booking_room AS b ON b.roomtype_id = a.roomtype_id
            INNER JOIN tbl_booking AS c ON c.booking_id = b.booking_id
            INNER JOIN tbl_booking_status AS d ON d.booking_status_id = c.booking_status_id
            INNER JOIN tbl_rooms AS e ON e.roomtype_id = a.roomtype_id
            WHERE 
                (c.customers_id = :bookingCustomerId OR c.customers_walk_in_id = :bookingCustomerId)
                AND c.booking_status_id IN (1, 2)";

        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':bookingCustomerId', $bookingCustomerId);
        $stmt->execute();
        return $stmt->rowCount() > 0 ? $stmt->fetchAll(PDO::FETCH_ASSOC) : 0;
    }

    function getCurrentBillings($json)
    {
        include "connection.php";
        $json = json_decode($json, true);

        try {
            $stmt = $conn->prepare("
            SELECT 
                CONCAT(a.customers_fname, ' ', a.customers_lname) AS customers_fullname,
                MAX(f.invoice_date) AS invoice_date,
                CONCAT(g.employee_fname, ' ', g.employee_lname) AS employee_fullname,
                SUM(CASE WHEN e.charges_category_id = 1 THEN b.booking_charges_price * b.booking_charges_quantity ELSE 0 END) AS room_charges,
                SUM(CASE WHEN e.charges_category_id = 2 THEN b.booking_charges_price * b.booking_charges_quantity ELSE 0 END) AS food_charges,
                SUM(CASE WHEN e.charges_category_id = 3 THEN b.booking_charges_price * b.booking_charges_quantity ELSE 0 END) AS extra_charges,
                SUM(b.booking_charges_price * b.booking_charges_quantity) AS total_charges
            FROM tbl_customers AS a
            INNER JOIN tbl_booking AS j ON j.customers_id = a.customers_id
            INNER JOIN tbl_booking_room AS c ON c.booking_id = j.booking_id
            INNER JOIN tbl_booking_charges AS b ON b.booking_room_id = c.booking_room_id
            INNER JOIN tbl_charges_master AS d ON d.charges_master_id = b.charges_master_id
            INNER JOIN tbl_charges_category AS e ON e.charges_category_id = d.charges_category_id
            LEFT JOIN tbl_billing AS h ON h.booking_id = j.booking_id
            LEFT JOIN tbl_invoice AS f ON f.billing_id = h.billing_id
            LEFT JOIN tbl_employee AS g ON g.employee_id = f.employee_id
            WHERE b.booking_room_id = :booking_room_id
              AND j.booking_status_id IN (1, 2)
        ");

            $stmt->bindParam(":booking_room_id", $json["booking_room_id"]);
            $stmt->execute();

            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return json_encode($result);
        } catch (PDOException $e) {
            return "Error: " . $e->getMessage();
        }
    }

    function getAvailableRoomsWithGuests($json)
    {
        // {"guestNumber": 2, "checkIn": "2025-08-29 22:15:00", "checkOut": "2025-10-14 12:00:00"}
        include "connection.php";
        $data = json_decode($json, true);
        $guestNumber = $data["guestNumber"];
        $checkIn = $data["checkIn"];
        $checkOut = $data["checkOut"];

        $sql = "SELECT a.roomnumber_id AS room_ids, a.roomfloor, a.room_capacity, a.room_beds AS room_beds, a.room_sizes, b.roomtype_id, b.roomtype_name, b.roomtype_description, b.roomtype_price, c.status_name, c.status_id
                FROM tbl_rooms a
                INNER JOIN tbl_roomtype b ON b.roomtype_id = a.roomtype_id
                INNER JOIN tbl_status_types c ON c.status_id = a.room_status_id
                WHERE a.room_status_id = 3 AND a.roomtype_id NOT IN (
                    SELECT a.roomtype_id
                    FROM tbl_booking_room a
                    INNER JOIN tbl_booking b ON b.booking_id = a.booking_id
                    WHERE b.booking_checkin_dateandtime < :checkOut 
                    AND b.booking_checkout_dateandtime > :checkIn
                ) AND a.room_capacity >= :guestNumber
        ";

        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":guestNumber", $guestNumber);
        $stmt->bindParam(":checkIn", $checkIn);
        $stmt->bindParam(":checkOut", $checkOut);
        $stmt->execute();

        return $stmt->rowCount() > 0 ? $stmt->fetchAll(PDO::FETCH_ASSOC) : 0;
    }


    function customerBookingWithAccount($json)
    {
        // {
        //     "customerId":2,
        //     "bookingDetails":{"checkIn":"2023-06-01 02:00:00","checkOut":"2025-06-02 03:00:00","downpayment":1000,"children":2,"adult":3, "totalAmount": 5000},
        //     "roomDetails":[ {"roomTypeId":1}, {"roomTypeId":2} ]
        // }
        //THANK YOU <333 😭 XOXO xD
        include "connection.php";
        $json = json_decode($json, true);

        try {
            $conn->beginTransaction();
            $customerId = $json["customerId"];
            $bookingDetails = $json["bookingDetails"];
            $roomDetails = $json["roomDetails"];
            $totalGuests = $bookingDetails["adult"] + $bookingDetails["children"];

            $stmt = $conn->prepare("
                INSERT INTO tbl_booking 
                    (customers_id, guests_amnt, customers_walk_in_id, booking_downpayment, booking_checkin_dateandtime, booking_checkout_dateandtime, booking_created_at, children, adult, booking_totalAmount) 
                VALUES 
                    (:customers_id, :guestTotalAmount, NULL, :booking_downpayment, :booking_checkin_dateandtime, :booking_checkout_dateandtime, NOW(), :children, :adult, :totalAmount)
            ");
            $stmt->bindParam(":customers_id", $customerId);
            $stmt->bindParam(":booking_downpayment", $bookingDetails["downpayment"]);
            $stmt->bindParam(":booking_checkin_dateandtime", $bookingDetails["checkIn"]);
            $stmt->bindParam(":booking_checkout_dateandtime", $bookingDetails["checkOut"]);
            $stmt->bindParam(":totalAmount", $bookingDetails["totalAmount"]);
            $stmt->bindParam(":guestTotalAmount", $totalGuests);
            $stmt->bindParam(":children", $bookingDetails["children"]);
            $stmt->bindParam(":adult", $bookingDetails["adult"]);
            $stmt->execute();
            $bookingId = $conn->lastInsertId();

            $sql = "INSERT INTO tbl_booking_room (booking_id, roomtype_id, roomnumber_id) VALUES (:booking_id, :roomtype_id, NULL)";
            foreach ($roomDetails as $room) {
                $stmt = $conn->prepare($sql);
                $stmt->bindParam(":booking_id", $bookingId);
                $stmt->bindParam(":roomtype_id", $room["roomTypeId"]);
                $stmt->execute();
            }

            $conn->commit();
            return 1;
        } catch (PDOException $e) {
            $conn->rollBack();
            return $e->getMessage();
        }
    }

    function getBookingHistory($json)
    {
        include "connection.php";
        $json = json_decode($json, true);

        $bookingCustomerId = $json['booking_customer_id'] ?? 0;
        $sql = "SELECT a.*, b.*, c.*, d.*, f.booking_status_name
            FROM tbl_booking a
            LEFT JOIN tbl_booking_room b ON b.booking_id = a.booking_id
            INNER JOIN tbl_roomtype c ON c.roomtype_id = b.roomtype_id
            INNER JOIN tbl_rooms d ON d.roomtype_id = c.roomtype_id
            INNER JOIN tbl_booking_history e ON e.booking_id = a.booking_id
            INNER JOIN tbl_booking_status f ON f.booking_status_id = e.status_id
            WHERE a.customers_id = :bookingCustomerId
            AND a.booking_isArchive = 0
            ORDER BY a.booking_id DESC";

        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':bookingCustomerId', $bookingCustomerId);
        $stmt->execute();

        if ($stmt->rowCount() === 0) {
            return 0;
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // echo json_encode($rows);
        // die();
        // Group by booking_id
        $grouped = [];
        foreach ($rows as $row) {
            $bookingId = $row['booking_id'];

            if (!isset($grouped[$bookingId])) {
                $grouped[$bookingId] = [
                    "booking_id" => $row["booking_id"],
                    "booking_checkin_dateandtime" => $row["booking_checkin_dateandtime"],
                    "booking_checkout_dateandtime" => $row["booking_checkout_dateandtime"],
                    "customers_id" => $row["customers_id"],
                    "booking_date" => $row["booking_created_at"],
                    "booking_status" => $row["booking_status_name"],
                    "guests_amnt" => $row["guests_amnt"],
                    "booking_downpayment" => $row["booking_downpayment"],
                    "booking_total" => $row["booking_totalAmount"],
                    "rooms" => []
                ];
            }

            $grouped[$bookingId]["rooms"][] = [
                "booking_room_id" => $row["booking_room_id"],
                "room_description" => $row["roomtype_description"],
                "room_id" => $row["roomnumber_id"],
                "room_number" => $row["roomnumber_id"],
                "roomtype_id" => $row["roomtype_id"],
                "roomtype_name" => $row["roomtype_name"],
                "room_price" => $row["roomtype_price"]
            ];
        }

        return array_values($grouped);
    }




    function archiveBooking($json)
    {
        // {"bookingId":4}
        include "connection.php";
        $json = json_decode($json, true);
        $bookingId = $json['bookingId'];
        $sql = "UPDATE tbl_booking SET booking_isArchive = 1 WHERE booking_id = :bookingId";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':bookingId', $bookingId);
        $stmt->execute();
        return $stmt->rowCount() > 0 ? 1 : 0;
    }

    function getArchivedBookings($json)
    {
        include "connection.php";
        $json = json_decode($json, true);

        $bookingCustomerId = $json['booking_customer_id'] ?? 0;
        $sql = "SELECT a.*, b.*, c.*, d.*, f.booking_status_name
            FROM tbl_booking a
            INNER JOIN tbl_booking_room b ON b.booking_id = a.booking_id
            INNER JOIN tbl_roomtype c ON c.roomtype_id = b.roomtype_id
            INNER JOIN tbl_rooms d ON d.roomtype_id = c.roomtype_id
            INNER JOIN tbl_booking_history e ON e.booking_id = a.booking_id
            INNER JOIN tbl_booking_status f ON f.booking_status_id = e.status_id
            WHERE a.customers_id = :bookingCustomerId
            AND a.booking_isArchive = 1
            ORDER BY a.booking_created_at DESC";

        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':bookingCustomerId', $bookingCustomerId);
        $stmt->execute();

        if ($stmt->rowCount() === 0) {
            return 0;
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // echo json_encode($rows);
        // die();
        // Group by booking_id
        $grouped = [];
        foreach ($rows as $row) {
            $bookingId = $row['booking_id'];

            if (!isset($grouped[$bookingId])) {
                $grouped[$bookingId] = [
                    "booking_id" => $row["booking_id"],
                    "booking_checkin_dateandtime" => $row["booking_checkin_dateandtime"],
                    "booking_checkout_dateandtime" => $row["booking_checkout_dateandtime"],
                    "customers_id" => $row["customers_id"],
                    "booking_date" => $row["booking_created_at"],
                    "booking_status" => $row["booking_status_name"],
                    "guests_amnt" => $row["guests_amnt"],
                    "booking_downpayment" => $row["booking_downpayment"],
                    "booking_total" => $row["booking_totalAmount"],
                    "rooms" => []
                ];
            }

            $grouped[$bookingId]["rooms"][] = [
                "booking_room_id" => $row["booking_room_id"],
                "room_description" => $row["roomtype_description"],
                "room_id" => $row["roomnumber_id"],
                "room_number" => $row["roomnumber_id"],
                "roomtype_id" => $row["roomtype_id"],
                "roomtype_name" => $row["roomtype_name"],
                "room_price" => $row["roomtype_price"]
            ];
        }

        return array_values($grouped);
    }

    function unarchiveBooking($json)
    {
        // {"bookingId":4}
        include "connection.php";
        $json = json_decode($json, true);
        $bookingId = $json['bookingId'];
        $sql = "UPDATE tbl_booking SET booking_isArchive = 0 WHERE booking_id = :bookingId";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':bookingId', $bookingId);
        $stmt->execute();
        return $stmt->rowCount() > 0 ? 1 : 0;
    }

    function removeBookingRoom($json)
    {
        include "connection.php";
        $conn->beginTransaction();
        try {
            $json = json_decode($json, true);
            $bookingRoomId = $json['bookingRoomId'];
            $bookingId = $json['bookingId'];

            // 1️⃣ Delete the booking room
            $sql = "DELETE FROM tbl_booking_room WHERE booking_room_id = :bookingRoomId";
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(':bookingRoomId', $bookingRoomId);
            $stmt->execute();

            // 2️⃣ Recalculate the total from remaining rooms
            $sql = "SELECT SUM(rt.roomtype_price) as total 
                FROM tbl_booking_room br
                INNER JOIN tbl_roomtype rt ON rt.roomtype_id = br.roomtype_id
                WHERE br.booking_id = :bookingId";
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(':bookingId', $bookingId);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $newTotal = $row['total'] ?? 0;

            // 3️⃣ Calculate new downpayment (half of total)
            $newDownpayment = $newTotal / 2;

            // 4️⃣ Update booking total and downpayment
            $sql = "UPDATE tbl_booking 
                SET booking_totalAmount = :newTotal, 
                    booking_downpayment = :newDownpayment
                WHERE booking_id = :bookingId";
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(':newTotal', $newTotal);
            $stmt->bindParam(':newDownpayment', $newDownpayment);
            $stmt->bindParam(':bookingId', $bookingId);
            $stmt->execute();

            $conn->commit();
            return 1;
        } catch (PDOException $th) {
            $conn->rollBack();
            return $th->getMessage();
        }
    }

    function login($json){
        // {"username":"sabils","password":"sabils"}
        include "connection.php";
        $data = json_decode($json, true);
        $sql = "SELECT a.customers_online_id, a.customers_online_profile_image, b.*
        FROM tbl_customers_online a 
        INNER JOIN tbl_customers b ON b.customers_online_id = a.customers_online_id
        WHERE a.customers_online_username = :customers_online_username AND BINARY a.customers_online_password = :customers_online_password";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":customers_online_username", $data["username"]);
        $stmt->bindParam(":customers_online_password", $data["password"]);
        $stmt->execute();
        return $stmt->rowCount() > 0 ? $stmt->fetch(PDO::FETCH_ASSOC) : 0;
        
        // $customer = $stmt->rowCount() > 0 ? $stmt->fetch(PDO::FETCH_ASSOC) : 0;
        // if($customer == 0){
        //     return $this->employeeLogin($json);
        // }else{
        //     return $customer;
        // }
    }

    function employeeLogin($json){
        include "connection.php";
        $data = json_decode($json, true);
        $sql = " SELECT * FROM tbl_employee WHERE employee_username = :employee_username AND BINARY employee_password = :employee_password";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":employee_username", $data["username"]);
        $stmt->bindParam(":employee_password", $data["password"]);
        $stmt->execute();
        return $stmt->rowCount() > 0 ? $stmt->fetch(PDO::FETCH_ASSOC) : 0;
    }

} //customer



$json = isset($_POST["json"]) ? $_POST["json"] : "0";
$operation = isset($_POST["operation"]) ? $_POST["operation"] : "0";
$demiren_customer = new Demiren_customer();
switch ($operation) {
    case "customerProfile":
        echo $demiren_customer->customerProfile($json);
        break;
    case "customerUpdateProfile":
        echo $demiren_customer->customerUpdateProfile($json);
        break;
    case "customerChangePassword":
        echo $demiren_customer->customerChangePassword($json);
        break;
    case "customerChangeEmail":
        echo $demiren_customer->customerChangeEmail($json);
        break;
    case "customerForgotPassword":
        echo $demiren_customer->customerForgotPassword($json);
        break;
    case "customerChangeAuthenticationStatus":
        echo $demiren_customer->customerChangeAuthenticationStatus($json);
        break;
    case "customerBookingNoAccount":
        echo $demiren_customer->customerBookingNoAccount($json);
        break;
    case "customerFeedBack":
        echo $demiren_customer->customerFeedBack($json);
        break;
    case "customerCancelBooking":
        echo $demiren_customer->customerCancelBooking($json);
        break;
    case "customerViewBookings":
        echo json_encode($demiren_customer->customerViewBookings($json));
        break;
    case "sendEmail":
        echo $demiren_customer->sendEmail($json);
        break;
    case "getNationality":
        echo json_encode($demiren_customer->getNationality());
        break;
    case "getCustomerAuthenticationStatus":
        echo json_encode($demiren_customer->getCustomerAuthenticationStatus($json));
        break;
    case "getRooms":
        echo $demiren_customer->getRooms();
        break;
    case "getFeedbacks":
        echo json_encode($demiren_customer->getFeedbacks());
        break;
    case "customerRegistration":
        echo $demiren_customer->customerRegistration($json);
        break;
    case "customerCurrentBookings":
        echo json_encode($demiren_customer->customerCurrentBookings($json));
        break;
    case "getCurrentBillings":
        echo $demiren_customer->getCurrentBillings($json);
        break;
    case "customerCurrentBookingsWithAccount":
        echo $demiren_customer->customerCurrentBookingsWithAccount($json);
        break;
    case "customerCurrentBookingsWithoutAccount":
        echo $demiren_customer->customerCurrentBookingsWithoutAccount($json);
        break;
    case "customerBookingWithAccount":
        echo $demiren_customer->customerBookingWithAccount($json);
        break;
    case "getAvailableRoomsWithGuests":
        echo json_encode($demiren_customer->getAvailableRoomsWithGuests($json));
        break;
    case "getBookingHistory":
        echo json_encode($demiren_customer->getBookingHistory($json));
        break;
    case "archiveBooking":
        echo json_encode($demiren_customer->archiveBooking($json));
        break;
    case "getArchivedBookings":
        echo json_encode($demiren_customer->getArchivedBookings($json));
        break;
    case "unarchiveBooking":
        echo json_encode($demiren_customer->unarchiveBooking($json));
        break;
    case "removeBookingRoom":
        echo json_encode($demiren_customer->removeBookingRoom($json));
        break;
    case "login":
        echo json_encode($demiren_customer->login($json));
        break;
    default:
        echo json_encode(["error" => "Invalid operation"]);
        break;
}


//MUST REMEMBER
//gwapa ko (naka default nani)({}, []);
//hende ko na alam 
//pero maganda ako >< <3
// wowowowo = feedback name sa github
//hay nako
//ang image sa logobells dapat ilisan 
//WALA KOY MOUSE :((
//2029??!!!! the helly