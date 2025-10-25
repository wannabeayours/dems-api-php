<?php
date_default_timezone_set("Asia/Manila");
include "headers.php";

class Demiren_customer
{
    function customerProfile($json)
    {
        // {"customers_id":1}
        include "connection.php";
        $json = json_decode($json, true);
        $sql = "SELECT a.*, b.*, c.*, d.*
                FROM tbl_customers AS a
                LEFT JOIN tbl_nationality AS b ON b.nationality_id = a.nationality_id
                LEFT JOIN tbl_customer_identification AS c ON c.identification_id = a.identification_id
                LEFT JOIN tbl_customers_online AS d ON d.customers_online_id = a.customers_online_id
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

            // ✅ Aliased fields so keys match frontend JSON
            $stmt = $conn->prepare("
            SELECT 
                a.customers_fname, 
                a.customers_lname, 
                a.customers_phone AS customers_phone_number, 
                a.customers_email, 
                a.customers_birthdate AS customers_date_of_birth, 
                b.nationality_name, 
                d.customers_online_username, 
                a.nationality_id, 
                a.customers_online_id
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
                SET customers_phone = :customers_phone_number 
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
                SET customers_birthdate = :customers_date_of_birth 
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
        $sql = "UPDATE tbl_customers_online SET customers_online_status = :customers_online_authentication_status WHERE customers_online_id = :customers_online_id";
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
        include "send_email.php";
        $json = json_decode($json, true);
        try {
            $conn->beginTransaction();
            // ✅ Step 1: Insert walk-in customer
            $stmt = $conn->prepare("
            INSERT INTO tbl_customers_walk_in 
                (customers_walk_in_fname, customers_walk_in_lname, customers_walk_in_email, customers_walk_in_phone, customers_walk_in_created_at, customers_walk_in_status)
            VALUES 
                (:fname, :lname, :email, :phone, NOW(), 'Active')
        ");
            $stmt->bindParam(":fname", $json["walkinfirstname"]);
            $stmt->bindParam(":lname", $json["walkinlastname"]);
            $stmt->bindParam(":email", $json["email"]);
            $stmt->bindParam(":phone", $json["contactNumber"]);
            $stmt->execute();
            $walkInCustomerId = $conn->lastInsertId();

            // ✅ Step 2: Extract booking details
            $bookingDetails = $json["bookingDetails"];
            $roomDetails = $json["roomDetails"];
            $totalGuests = $bookingDetails["adult"] + $bookingDetails["children"];
            $paymentMethod = $bookingDetails["payment_method_id"];
            $checkIn = $bookingDetails["checkIn"];
            $checkOut = $bookingDetails["checkOut"];
            $numberOfNights = $bookingDetails["numberOfNights"];

            // ✅ Step 3: Insert booking
            $referenceNo = "REF" . date("YmdHis") . rand(100, 999);
            $stmt = $conn->prepare("
            INSERT INTO tbl_booking 
                (customers_id, customers_walk_in_id, guests_amnt, booking_payment,
                booking_checkin_dateandtime, booking_checkout_dateandtime, booking_created_at, 
                booking_totalAmount, booking_isArchive, reference_no, booking_paymentMethod) 
            VALUES 
                (NULL, :walkin_id, :guestTotal, :downpayment, 
                :checkin, :checkout, NOW(), 
                :totalAmount, 0, :reference_no, :payment_method_id)
        ");
            $stmt->bindParam(":walkin_id", $walkInCustomerId);
            $stmt->bindParam(":guestTotal", $totalGuests);
            $stmt->bindParam(":downpayment", $bookingDetails["downpayment"]);
            $stmt->bindParam(":checkin", $checkIn);
            $stmt->bindParam(":checkout", $checkOut);
            $stmt->bindParam(":totalAmount", $bookingDetails["totalAmount"]);
            $stmt->bindParam(":reference_no", $referenceNo);
            $stmt->bindParam(":payment_method_id", $paymentMethod);
            $stmt->execute();
            $bookingId = $conn->lastInsertId();

            // ✅ Step 4: Assign available rooms and handle charges
            foreach ($roomDetails as $room) {
                $roomTypeId = $room["roomTypeId"];
                // Find available room for this type and date range
                $availabilityStmt = $conn->prepare("
                SELECT r.roomnumber_id
                FROM tbl_rooms r
                WHERE r.roomtype_id = :roomtype_id
                AND r.room_status_id = 3
                AND r.roomnumber_id NOT IN (
                    SELECT br.roomnumber_id
                    FROM tbl_booking_room br
                    JOIN tbl_booking b ON br.booking_id = b.booking_id
                    WHERE br.roomtype_id = :roomtype_id
                        AND b.booking_isArchive = 0
                        AND (
                            b.booking_checkin_dateandtime < :check_out 
                            AND b.booking_checkout_dateandtime > :check_in
                        )
                        AND br.roomnumber_id IS NOT NULL
                )
                LIMIT 1
            ");
                $availabilityStmt->bindParam(":roomtype_id", $roomTypeId);
                $availabilityStmt->bindParam(":check_in", $checkIn);
                $availabilityStmt->bindParam(":check_out", $checkOut);
                $availabilityStmt->execute();

                $availableRoom = $availabilityStmt->fetch(PDO::FETCH_ASSOC);

                if (!$availableRoom) {
                    $conn->rollBack();
                    return -1; // No room available
                }

                $selectedRoomNumberId = $availableRoom['roomnumber_id'];

                // Insert booking room
                $sql = "INSERT INTO tbl_booking_room 
                (booking_id, roomtype_id, roomnumber_id, bookingRoom_adult, bookingRoom_children) 
                VALUES 
                (:booking_id, :roomtype_id, :roomnumber_id, :bookingRoom_adult, :bookingRoom_children)";
                $stmt = $conn->prepare($sql);
                $stmt->bindParam(":booking_id", $bookingId);
                $stmt->bindParam(":roomtype_id", $roomTypeId);
                $stmt->bindParam(":roomnumber_id", $selectedRoomNumberId);
                $stmt->bindParam(":bookingRoom_adult", $room["adultCount"]);
                $stmt->bindParam(":bookingRoom_children", $room["childrenCount"]);
                $stmt->execute();
                $bookingRoomId = $conn->lastInsertId(); // ✅ Keep this separate

                // Add extra bed charge if applicable
                if (isset($room["bedCount"]) && $room["bedCount"] > 0) {
                    // $totalCharges = $room["bedCount"] * 420;
                    $totalCharges = $numberOfNights * 420;
                    $sql = "INSERT INTO tbl_booking_charges(charges_master_id, booking_room_id, booking_charges_price, booking_charges_quantity, booking_charges_total, booking_charge_status)
                    VALUES (2, :booking_room_id, 420, :booking_charges_quantity, :booking_charges_total, 2)";
                    $stmt = $conn->prepare($sql);
                    $stmt->bindParam(":booking_room_id", $bookingRoomId);
                    $stmt->bindParam(":booking_charges_quantity", $numberOfNights);
                    $stmt->bindParam(":booking_charges_total", $totalCharges);
                    $stmt->execute();
                }

                // add extra guest

                if ($room["extraGuestCharges"] > 0) {
                    // $totalCharges = $room["extraGuestCharges"] * 420;
                    $totalCharges = $numberOfNights * 420;
                    $sql = "INSERT INTO tbl_booking_charges(charges_master_id, booking_room_id, booking_charges_price, booking_charges_quantity, booking_charges_total, booking_charge_status)
                    VALUES (12, :booking_room_id, 420, :booking_charges_quantity, :booking_charges_total, 2)";
                    $stmt = $conn->prepare($sql);
                    $stmt->bindParam(":booking_room_id", $bookingRoomId);
                    $stmt->bindParam(":booking_charges_quantity", $numberOfNights);
                    $stmt->bindParam(":booking_charges_total", $totalCharges);
                    $stmt->execute();
                }
            }

            // ✅ Step 5: Booking history (Pending)
            $sql = "INSERT INTO tbl_booking_history
            (booking_id, employee_id, status_id, updated_at) 
            VALUES 
            (:booking_id, NULL, 2, NOW())";
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(":booking_id", $bookingId);
            $stmt->execute();

            if ($bookingDetails["payment_method_id"] == 1) {
                $balance = $bookingDetails["totalAmount"] - $bookingDetails["totalPay"];
            } else {
                $balance = $bookingDetails["totalAmount"] - $bookingDetails["downpayment"];
            }

            // ✅ Step 6: Billing record
            $sql = "INSERT INTO tbl_billing
            (booking_id, payment_method_id, billing_total_amount, billing_dateandtime, billing_vat, billing_balance, billing_downpayment) 
            VALUES 
            (:booking_id, :payment_method_id, :total_amount, NOW(), :billing_vat, :billing_balance, :billing_downpayment)";
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(":booking_id", $bookingId);
            $stmt->bindParam(":payment_method_id", $bookingDetails["payment_method_id"]);
            $stmt->bindParam(":total_amount", $bookingDetails["totalAmount"]);
            $stmt->bindParam(":billing_vat", $bookingDetails["displayedVat"]);
            $stmt->bindParam(":billing_balance", $balance);
            $stmt->bindParam(":billing_downpayment", $bookingDetails["downpayment"]);
            $stmt->execute();

            $conn->commit();
            $emailSubject = 'Demiren Hotel — Booking Request Details';

            // Prepare dynamic values for summary
            $paymentMethodName = ($bookingDetails["payment_method_id"] == 1) ? "GCash" : "Paypal";
            $checkInFmt = date('F j, Y', strtotime($bookingDetails["checkIn"])) . ' 2:00 PM';
            $checkOutFmt = date('F j, Y', strtotime($bookingDetails["checkOut"])) . ' 12:00 PM';
            $nights = max(1, (int)ceil((strtotime($bookingDetails["checkOut"]) - strtotime($bookingDetails["checkIn"])) / 86400));
            $guestsTotal = (int)$bookingDetails["adult"] + (int)$bookingDetails["children"];
            $totalAmountFmt = number_format((float)$bookingDetails["totalAmount"], 2);


            // Build room list HTML with names
            $roomsListHtml = '';
            foreach ($roomDetails as $room) {
                $stmtRt = $conn->prepare("SELECT roomtype_name FROM tbl_roomtype WHERE roomtype_id = :id");
                $stmtRt->bindParam(':id', $room["roomTypeId"]);
                $stmtRt->execute();
                $rtName = $stmtRt->fetchColumn();
                if (!$rtName) {
                    $rtName = 'Room Type #' . (int)$room["roomTypeId"];
                }
                $roomsListHtml .= '<li><strong>' . htmlspecialchars($rtName) . '</strong> — Adults: ' . (int)$room["adultCount"] . ', Children: ' . (int)$room["childrenCount"] . ', Extra beds: ' . (int)$room["bedCount"] . '</li>';
            }

            $emailBody = '
                    <html>
                    <head>
                    <meta name="viewport" content="width=device-width, initial-scale=1.0">
                    <style>
                        body { margin: 0; padding: 0; font-family: "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background-color: #f4f6f8; color: #333; }
                        .email-wrapper { width: 100%; background-color: #f4f6f8; padding: 40px 0; }
                        .email-container { max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08); border: 1px solid #e6ebf1; }
                        .email-header { background: linear-gradient(135deg, #1a73e8, #4285f4); color: white; text-align: center; padding: 25px 20px; }
                        .email-header h1 { margin: 0; font-size: 22px; font-weight: 600; letter-spacing: 0.3px; }
                        .email-body { padding: 35px 40px; }
                        .email-body h2 { font-size: 20px; font-weight: 600; color: #1a73e8; margin-top: 0; margin-bottom: 15px; }
                        .email-body p { font-size: 15px; line-height: 1.7; color: #444; margin: 10px 0; }
                        .summary-card { background-color: #f8fbff; border: 1px solid #e6ebf1; border-radius: 8px; padding: 16px; margin: 18px 0; }
                        .summary-item { display: flex; justify-content: space-between; margin: 6px 0; font-size: 14px; }
                        .summary-item .label { color: #555; }
                        .rooms-list { margin: 10px 0 0; padding-left: 18px; }
                        .email-footer { font-size: 12px; color: #777; text-align: center; border-top: 1px solid #eaeaea; padding: 20px 10px; background-color: #fafafa; }
                        @media only screen and (max-width: 600px) { .email-body { padding: 25px 20px; } .email-header h1 { font-size: 20px; } }
                    </style>
                    </head>
                    <body>
                        <div class="email-wrapper">
                            <div class="email-container">
                                <div class="email-header">
                                    <h1>Demiren Hotel & Restaurant</h1>
                                </div>
                                <div class="email-body">
                                    <h2>Booking Request Received — Summary</h2>
                                    <p>Hi there,</p>
                                    <p>We’ve received your booking request. Below are the full details for your records:</p>

                                    <div class="summary-card">
                                        <div class="summary-item"><span class="label">Check-in:</span><span>' . $checkInFmt . '</span></div>
                                        <div class="summary-item"><span class="label">Check-out:</span><span>' . $checkOutFmt . '</span></div>
                                        <div class="summary-item"><span class="label">Nights:</span><span>' . $nights . '</span></div>
                                        <div class="summary-item"><span class="label">Guests:</span><span>' . $guestsTotal . ' (Adults: ' . (int)$bookingDetails["adult"] . ', Children: ' . (int)$bookingDetails["children"] . ')</span></div>
                                        <div class="summary-item"><span class="label">Payment Method:</span><span>' . $paymentMethodName . '</span></div>
                                        <div class="summary-item"><span class="label">Total Amount:</span><span>₱' . $totalAmountFmt . '</span></div>
                                        <div class="summary-item"><span class="label">Rooms:</span><span></span></div>
                                        <ul class="rooms-list">' . $roomsListHtml . '</ul>
                                    </div>

                                    <p class="email-highlight">Your booking request is currently under review by our front desk team. You’ll receive a confirmation message once approved. If needed, you can cancel within 24 hours. For assistance, contact <a href="mailto:info@demirenhotel.com" style="color:#1a73e8; text-decoration:none;">info@demirenhotel.com</a>.</p>

                                    <p>Warm regards,<br><strong>The Demiren Team</strong></p>
                                </div>
                                <div class="email-footer">This is an automated message from Demiren Hotel & Restaurant.<br>Please do not reply directly to this email.</div>
                            </div>
                        </div>
                    </body>
                    </html>';

            $sendEmail = new SendEmail();

            $sendEmail->sendEmail($json["email"], $emailSubject, $emailBody);
            return 1;
        } catch (PDOException $e) {
            $conn->rollBack();
            return $e->getMessage();
        }
    }


    function customerViewBookings($json)
    {
        // {"booking_customer_id": 1}
        include "connection.php";
        $json = json_decode($json, true);
        $bookingCustomerId = $json['booking_customer_id'] ?? 0;

        $sql = "SELECT a.*, b.*, c.*, d.*, f.payment_method_name
                    FROM tbl_booking a
                    LEFT JOIN tbl_booking_room b ON b.booking_id = a.booking_id
                    LEFT JOIN tbl_roomtype c ON c.roomtype_id = b.roomtype_id
                    LEFT JOIN tbl_rooms d ON d.roomnumber_id = b.roomnumber_id
                    LEFT JOIN tbl_booking_history e ON e.booking_id = a.booking_id
                    INNER JOIN tbl_payment_method f ON f.payment_method_id = a.booking_paymentMethod
                    WHERE (a.customers_id = :bookingCustomerId OR a.customers_walk_in_id = :bookingCustomerId)
                    AND e.status_id = 2
                    ORDER BY a.booking_created_at DESC
                ";

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
                $balance = $this->getCurrentBalance($bookingId);
                $bookings[$bookingId] = [
                    "booking_id" => $row['booking_id'],
                    "payment_method_name" => $row['payment_method_name'],
                    "balance" => $balance,
                    "booking_totalAmount" => $row['booking_totalAmount'],
                    "customers_id" => $row['customers_id'],
                    "customers_walk_in_id" => $row['customers_walk_in_id'],
                    "guests_amnt" => $row['guests_amnt'],
                    "booking_totalAmount" => $row['booking_totalAmount'],
                    "booking_payment" => $row['booking_payment'],
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
                "room_capacity" => $row['roomtype_capacity'],
                "room_beds" => $row['roomtype_beds'],
                "room_sizes" => $row['roomtype_sizes']
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
                c.booking_payment,
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
                c.booking_payment,
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
        $sql = "INSERT INTO tbl_customersreviews (customers_id, customersreviews_comment, customersreviews_rating, customersreviews_date) 
                VALUES (:customers_id, :customersreviews, :rating, NOW())";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":customersreviews", $json["customersreviews"]);
        $stmt->bindParam(":customers_id", $json["customers_id"]);
        $stmt->bindParam(":rating", $json["rating"]);
        $stmt->execute();
        return $stmt->rowCount() > 0 ? 1 : 0;
    }

    function customerCancelBooking($json)
    {
        include "connection.php";
        date_default_timezone_set('Asia/Manila');
        $json = json_decode($json, true);

        // 🔹 Get booking creation/check-in time
        $sql = "SELECT booking_created_at 
            FROM tbl_booking 
            WHERE booking_id = :booking_id";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":booking_id", $json["booking_id"]);
        $stmt->execute();
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$booking) {
            return 0; // booking not found
        }

        // 🔹 Compare current time with booking_created_at
        $bookingTime = strtotime($booking["booking_created_at"]);
        $currentTime = time();
        $hoursDiff   = ($currentTime - $bookingTime) / 3600;

        if ($hoursDiff >= 24) {
            return -1; // ❌ Cannot cancel, booking is already 24+ hours old
        }

        // 🔹 Insert cancellation record
        $sql = "UPDATE tbl_booking_history 
                SET status_id = 3, updated_at = NOW() 
                WHERE booking_id = :booking_id";
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
        $sql = "SELECT customers_online_status FROM tbl_customers_online WHERE customers_online_id = :customers_online_id";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":customers_online_id", $data["customers_online_id"]);
        $stmt->execute();
        $returnValue = $stmt->fetch(PDO::FETCH_ASSOC);
        return $returnValue["customers_online_status"] ?? 0;
    }
    function getRooms()
    {
        include "connection.php";

        $sql = "SELECT b.roomtype_id AS room_type,
        b.max_capacity,
        b.roomtype_description,
        b.roomtype_name, 
        b.roomtype_image,
        GROUP_CONCAT(DISTINCT a.imagesroommaster_filename) AS images,
        b.roomtype_price, 
        GROUP_CONCAT(DISTINCT c.roomnumber_id) AS room_ids,
        GROUP_CONCAT(DISTINCT  b.roomtype_beds) AS room_beds,
        GROUP_CONCAT(DISTINCT b.roomtype_capacity) AS room_capacity,
        GROUP_CONCAT(DISTINCT b.roomtype_sizes) AS room_sizes,
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

            // Map form data to database fields
            $username = $json['username'];
            $password = password_hash($json['password'], PASSWORD_DEFAULT); // Encrypt password
            $email = $json['email'];
            $phone = $json['phone'];
            $firstName = $json['firstName'];
            $lastName = $json['lastName'];
            $nationality = $json['nationality'];
            $dob = $json['dob'];

            // Insert into tbl_customers_online only
            $stmt = $conn->prepare("
                INSERT INTO tbl_customers_online (
                    customers_online_username, 
                    customers_online_password, 
                    customers_online_email,
                    customers_online_phone,
                    customers_online_created_at,
                    customers_online_status
                ) VALUES (
                    :customers_online_username, 
                    :customers_online_password, 
                    :customers_online_email,
                    :customers_online_phone,
                    NOW(),
                    'pending'
                )
            ");
            $stmt->bindParam(':customers_online_username', $username);
            $stmt->bindParam(':customers_online_password', $password);
            $stmt->bindParam(':customers_online_email', $email);
            $stmt->bindParam(':customers_online_phone', $phone);
            $stmt->execute();
            $customers_online_id = $conn->lastInsertId();

            // Insert into tbl_customers with reference to online account
            $stmt = $conn->prepare("
                INSERT INTO tbl_customers (
                    nationality_id,
                    customers_online_id,
                    customers_fname,
                    customers_lname,
                    customers_email,
                    customers_phone,
                    customers_birthdate,
                    customers_created_at,
                    customers_status
                ) VALUES (
                    :nationality_id,
                    :customers_online_id,
                    :customers_fname,
                    :customers_lname,
                    :customers_email,
                    :customers_phone,
                    :customers_birthdate,
                    NOW(),
                    'pending'
                )
            ");
            $stmt->bindParam(':nationality_id', $nationality);
            $stmt->bindParam(':customers_online_id', $customers_online_id);
            $stmt->bindParam(':customers_fname', $firstName);
            $stmt->bindParam(':customers_lname', $lastName);
            $stmt->bindParam(':customers_email', $email);
            $stmt->bindParam(':customers_phone', $phone);
            $stmt->bindParam(':customers_birthdate', $dob);
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
                c.booking_payment,
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

        $sql = "SELECT 
                b.roomtype_id, 
                b.roomtype_name, 
                b.roomtype_description, 
                b.roomtype_price, 
                b.roomtype_capacity, 
                b.roomtype_beds, 
                b.roomtype_sizes,
                b.max_capacity,
                b.roomtype_image,
                a.room_status_id AS status_id,
                b.roomtype_maxbeds,
                COUNT(a.roomnumber_id) AS available_count,
                MIN(a.roomnumber_id) AS sample_room_id
            FROM tbl_roomtype b
            LEFT JOIN tbl_rooms a ON b.roomtype_id = a.roomtype_id AND a.room_status_id = 3
            WHERE b.roomtype_capacity >= :guestNumber
            AND (
                a.roomnumber_id IS NULL 
                OR a.roomnumber_id NOT IN (
                    SELECT br.roomnumber_id
                    FROM tbl_booking_room br
                    INNER JOIN tbl_booking bk ON bk.booking_id = br.booking_id
                    WHERE bk.booking_isArchive = 0
                    AND br.roomnumber_id IS NOT NULL
                    AND (
                        (bk.booking_checkin_dateandtime < :checkOut AND bk.booking_checkout_dateandtime > :checkIn)
                    )
                )
            )
            GROUP BY b.roomtype_id
            HAVING available_count > 0 OR COUNT(a.roomnumber_id) = 0";

        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":guestNumber", $guestNumber);
        $stmt->bindParam(":checkIn", $checkIn);
        $stmt->bindParam(":checkOut", $checkOut);
        $stmt->execute();

        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Filter out room types that have no available rooms due to booking conflicts
        $availableRoomTypes = array();
        foreach ($result as $roomType) {
            if ($roomType['available_count'] > 0) {
                // Get images for this room type
                $imageSql = "SELECT imagesroommaster_filename FROM tbl_imagesroommaster WHERE roomtype_id = :roomTypeId";
                $imageStmt = $conn->prepare($imageSql);
                $imageStmt->bindParam(':roomTypeId', $roomType['roomtype_id']);
                $imageStmt->execute();
                $images = $imageStmt->rowCount() > 0 ? $imageStmt->fetchAll(PDO::FETCH_ASSOC) : [];

                // Add images to the room type data
                $roomType["images"] = $images;
                $availableRoomTypes[] = $roomType;
            }
        }

        return count($availableRoomTypes) > 0 ? $availableRoomTypes : 0;
    }



    function customerBookingWithAccount($json)
    {
        // {
        //     "customerId":2,
        //     "bookingDetails":{"checkIn":"2023-06-01 02:00:00","checkOut":"2025-06-02 03:00:00","downpayment":1000,"children":2,"adult":3, "totalAmount": 5000},
        //     "roomDetails":[ {"roomTypeId":1,"adultCount":2,"childrenCount":0}, {"roomTypeId":2,"adultCount":2,"childrenCount":1} ]
        // }
        // THANK YOU <333 😭 XOXO xD
        // okay?
        // yessssss
        include "connection.php";
        $json = json_decode($json, true);

        try {
            $conn->beginTransaction();
            $customerId = $json["customerId"];
            $bookingDetails = $json["bookingDetails"];
            $roomDetails = $json["roomDetails"];
            $totalGuests = $bookingDetails["adult"] + $bookingDetails["children"];
            $checkIn = $bookingDetails["checkIn"];
            $checkOut = $bookingDetails["checkOut"];
            $paymentMethod = $bookingDetails["payment_method_id"];
            $referenceNo = "REF" . date("YmdHis") . rand(100, 999);
            $numberOfNights = $bookingDetails["numberOfNights"];
            // ✅ First, create the booking master row
            $stmt = $conn->prepare("
            INSERT INTO tbl_booking 
                (customers_id, guests_amnt, customers_walk_in_id, booking_payment, 
                booking_checkin_dateandtime, booking_checkout_dateandtime, booking_created_at, 
                booking_totalAmount, booking_paymentMethod, reference_no) 
            VALUES 
                (:customers_id, :guestTotalAmount, NULL, :booking_downpayment, 
                :booking_checkin_dateandtime, :booking_checkout_dateandtime, NOW(), :totalAmount, :paymentMethod, :reference_no)");
            $stmt->bindParam(":customers_id", $customerId);
            $stmt->bindParam(":booking_downpayment", $bookingDetails["downpayment"]);
            $stmt->bindParam(":booking_checkin_dateandtime", $checkIn);
            $stmt->bindParam(":booking_checkout_dateandtime", $checkOut);
            $stmt->bindParam(":totalAmount", $bookingDetails["totalAmount"]);
            $stmt->bindParam(":guestTotalAmount", $totalGuests);
            $stmt->bindParam(":paymentMethod", $paymentMethod);
            $stmt->bindParam(":reference_no", $referenceNo);
            $stmt->execute();
            $bookingId = $conn->lastInsertId();

            // ✅ For each room requested, find an available physical room and insert
            foreach ($roomDetails as $room) {
                $roomTypeId = $room["roomTypeId"];

                // ✅ Find ONE available physical roomnumber_id that’s free for that date range
                $availabilityStmt = $conn->prepare("
                SELECT r.roomnumber_id
                FROM tbl_rooms r
                WHERE r.roomtype_id = :roomtype_id
                AND r.room_status_id = 3
                AND r.roomnumber_id NOT IN (
                    SELECT br.roomnumber_id
                    FROM tbl_booking_room br
                    JOIN tbl_booking b ON br.booking_id = b.booking_id
                    WHERE br.roomtype_id = :roomtype_id
                        AND b.booking_isArchive = 0
                        AND (
                            b.booking_checkin_dateandtime < :check_out 
                            AND b.booking_checkout_dateandtime > :check_in
                        )
                        AND br.roomnumber_id IS NOT NULL
                )
                LIMIT 1
            ");
                $availabilityStmt->bindParam(":roomtype_id", $roomTypeId);
                $availabilityStmt->bindParam(":check_in", $checkIn);
                $availabilityStmt->bindParam(":check_out", $checkOut);
                $availabilityStmt->execute();

                $availableRoom = $availabilityStmt->fetch(PDO::FETCH_ASSOC);

                if (!$availableRoom) {
                    $conn->rollBack();
                    return -1;
                }

                $selectedRoomNumberId = $availableRoom['roomnumber_id'];

                // ✅ Insert booking room row with the assigned roomnumber_id
                $sql = "INSERT INTO tbl_booking_room (booking_id, roomtype_id, roomnumber_id, bookingRoom_adult, bookingRoom_children) 
                    VALUES (:booking_id, :roomtype_id, :roomnumber_id, :bookingRoom_adult, :bookingRoom_children)";
                $stmt = $conn->prepare($sql);
                $stmt->bindParam(":booking_id", $bookingId);
                $stmt->bindParam(":roomtype_id", $roomTypeId);
                $stmt->bindParam(":roomnumber_id", $selectedRoomNumberId);
                $stmt->bindParam(":bookingRoom_adult", $room["adultCount"]);
                $stmt->bindParam(":bookingRoom_children", $room["childrenCount"]);
                $stmt->execute();
                $bookingRoomId = $conn->lastInsertId();

                // Add extra bed charge if applicable
                if (isset($room["bedCount"]) && $room["bedCount"] > 0) {
                    // $totalCharges = $room["bedCount"] * 420;
                    $totalCharges = $numberOfNights * 420;
                    $sql = "INSERT INTO tbl_booking_charges(charges_master_id, booking_room_id, booking_charges_price, booking_charges_quantity, booking_charges_total, booking_charge_status)
                    VALUES (2, :booking_room_id, 420, :booking_charges_quantity, :booking_charges_total, 2)";
                    $stmt = $conn->prepare($sql);
                    $stmt->bindParam(":booking_room_id", $bookingRoomId);
                    $stmt->bindParam(":booking_charges_quantity", $numberOfNights);
                    $stmt->bindParam(":booking_charges_total", $totalCharges);
                    $stmt->execute();
                }

                // add extra guest
                if ($room["extraGuestCharges"] > 0) {
                    // $totalCharges = $room["extraGuestCharges"] * 420;
                    $totalCharges = $numberOfNights * 420;
                    $sql = "INSERT INTO tbl_booking_charges(charges_master_id, booking_room_id, booking_charges_price, booking_charges_quantity, booking_charges_total, booking_charge_status)
                    VALUES (12, :booking_room_id, 420, :booking_charges_quantity, :booking_charges_total, 2)";
                    $stmt = $conn->prepare($sql);
                    $stmt->bindParam(":booking_room_id", $bookingRoomId);
                    $stmt->bindParam(":booking_charges_quantity", $numberOfNights);
                    $stmt->bindParam(":booking_charges_total", $totalCharges);
                    $stmt->execute();
                }
            }

            $sql = "INSERT INTO tbl_booking_history
            (booking_id, employee_id, status_id, updated_at) 
            VALUES 
            (:booking_id, NULL, 2, NOW())";
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(":booking_id", $bookingId);
            $stmt->execute();

            if ($bookingDetails["payment_method_id"] == 1) {
                $balance = $bookingDetails["totalAmount"] - $bookingDetails["totalPay"];
            } else {
                $balance = $bookingDetails["totalAmount"] - $bookingDetails["downpayment"];
            }
            $sql = "INSERT INTO tbl_billing(booking_id, payment_method_id, billing_total_amount, billing_dateandtime, billing_vat, billing_balance, billing_downpayment) 
                VALUES (:booking_id, :payment_method_id, :total_amount, NOW(), :billing_vat, :billing_balance, :billing_downpayment)";
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(":booking_id", $bookingId);
            $stmt->bindParam(":payment_method_id", $bookingDetails["payment_method_id"]);
            $stmt->bindParam(":total_amount", $bookingDetails["totalAmount"]);
            $stmt->bindParam(":billing_vat", $bookingDetails["displayedVat"]);
            $stmt->bindParam(":billing_balance", $balance);
            $stmt->bindParam(":billing_downpayment", $bookingDetails["downpayment"]);
            $stmt->execute();

            $conn->commit();
            return 1; // Success
        } catch (PDOException $e) {
            $conn->rollBack();
            return $e->getMessage();
        }
    }

    function customerBookingWithoutAccount($json)
    {
        // {
        //     "customerId":2,
        //     "bookingDetails":{"checkIn":"2023-06-01 02:00:00","checkOut":"2025-06-02 03:00:00","downpayment":1000,"children":2,"adult":3, "totalAmount": 5000},
        //     "roomDetails":[ {"roomTypeId":1}, {"roomTypeId":2} ]
        // }
        //THANK YOU <333 😭 XOXO xD
        //okay?
        //yessssss

        include "connection.php";
        $json = json_decode($json, true);

        try {
            $conn->beginTransaction();
            $customerId = $json["customerId"];
            $bookingDetails = $json["bookingDetails"];
            $roomDetails = $json["roomDetails"];
            $totalGuests = $bookingDetails["adult"] + $bookingDetails["children"];
            $checkIn = $bookingDetails["checkIn"];
            $checkOut = $bookingDetails["checkOut"];

            // Check room availability for each requested room type
            foreach ($roomDetails as $room) {
                $roomTypeId = $room["roomTypeId"];

                $availabilityStmt = $conn->prepare("
                SELECT COUNT(*) as available_rooms
                FROM tbl_rooms r
                WHERE r.roomtype_id = :roomtype_id
                AND r.room_status_id = 3 
                AND r.roomnumber_id NOT IN (
                    SELECT br.roomnumber_id
                    FROM tbl_booking_room br
                    JOIN tbl_booking b ON br.booking_id = b.booking_id
                    WHERE br.roomtype_id = :roomtype_id
                    AND b.booking_isArchive = 0
                    AND (
                        (b.booking_checkin_dateandtime < :check_out AND b.booking_checkout_dateandtime > :check_in)
                    )
                    AND br.roomnumber_id IS NOT NULL
                )
            ");

                $availabilityStmt->bindParam(":roomtype_id", $roomTypeId);
                $availabilityStmt->bindParam(":check_in", $checkIn);
                $availabilityStmt->bindParam(":check_out", $checkOut);
                $availabilityStmt->execute();

                $availabilityResult = $availabilityStmt->fetch(PDO::FETCH_ASSOC);

                // If no rooms available for this type, return -1
                if ($availabilityResult['available_rooms'] == 0) {
                    $conn->rollBack();
                    return -1; // Room not available
                }
            }

            // If all rooms are available, proceed with booking
            $stmt = $conn->prepare("
            INSERT INTO tbl_booking 
                (customers_id, guests_amnt, customers_walk_in_id, booking_payment, 
                 booking_checkin_dateandtime, booking_checkout_dateandtime, booking_created_at, 
                 children, adult, booking_totalAmount) 
            VALUES 
                (:customers_id, :guestTotalAmount, NULL, :booking_downpayment, 
                 :booking_checkin_dateandtime, :booking_checkout_dateandtime, NOW(), 
                 :children, :adult, :totalAmount)
        ");
            $stmt->bindParam(":customers_id", $customerId);
            $stmt->bindParam(":booking_downpayment", $bookingDetails["downpayment"]);
            $stmt->bindParam(":booking_checkin_dateandtime", $checkIn);
            $stmt->bindParam(":booking_checkout_dateandtime", $checkOut);
            $stmt->bindParam(":totalAmount", $bookingDetails["totalAmount"]);
            $stmt->bindParam(":guestTotalAmount", $totalGuests);
            $stmt->bindParam(":children", $bookingDetails["children"]);
            $stmt->bindParam(":adult", $bookingDetails["adult"]);
            $stmt->execute();
            $bookingId = $conn->lastInsertId();



            // Insert booking room records without assigning specific room numbers
            $sql = "INSERT INTO tbl_booking_room (booking_id, roomtype_id, roomnumber_id) 
                VALUES (:booking_id, :roomtype_id, NULL)";
            foreach ($roomDetails as $room) {
                $stmt = $conn->prepare($sql);
                $stmt->bindParam(":booking_id", $bookingId);
                $stmt->bindParam(":roomtype_id", $room["roomTypeId"]);
                $stmt->execute();
            }

            return 1; // Success
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
        $sql = "SELECT a.*, b.*, c.*, d.*, g.payment_method_name,
            IFNULL(f.booking_status_name, 'Pending') AS booking_status_name
            FROM tbl_booking a
            LEFT JOIN tbl_booking_room b ON b.booking_id = a.booking_id
            LEFT JOIN tbl_roomtype c ON c.roomtype_id = b.roomtype_id
            LEFT JOIN tbl_rooms d ON d.roomnumber_id = b.roomnumber_id
            LEFT JOIN tbl_booking_history e ON e.booking_id = a.booking_id
            INNER JOIN tbl_booking_status f ON f.booking_status_id = e.status_id
            INNER JOIN tbl_payment_method g ON g.payment_method_id = a.booking_paymentMethod

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
                    "payment_method_name" => $row["payment_method_name"],
                    "booking_checkin_dateandtime" => $row["booking_checkin_dateandtime"],
                    "booking_checkout_dateandtime" => $row["booking_checkout_dateandtime"],
                    "customers_id" => $row["customers_id"],
                    "booking_date" => $row["booking_created_at"],
                    "booking_status" => $row["booking_status_name"],
                    "guests_amnt" => $row["guests_amnt"],
                    "booking_payment" => $row["booking_payment"],
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
        $sql = "SELECT a.*, b.*, c.*, d.*, f.booking_status_name, g.payment_method_name
            FROM tbl_booking a
            LEFT JOIN tbl_booking_room b ON b.booking_id = a.booking_id
            LEFT JOIN tbl_roomtype c ON c.roomtype_id = b.roomtype_id
            LEFT JOIN tbl_rooms d ON d.roomnumber_id = b.roomnumber_id
            LEFT JOIN tbl_booking_history e ON e.booking_id = a.booking_id
            LEFT JOIN tbl_booking_status f ON f.booking_status_id = e.status_id
            INNER JOIN tbl_payment_method g ON g.payment_method_id = a.booking_paymentMethod
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
                    "payment_method_name" => $row["payment_method_name"],
                    "booking_checkin_dateandtime" => $row["booking_checkin_dateandtime"],
                    "booking_checkout_dateandtime" => $row["booking_checkout_dateandtime"],
                    "customers_id" => $row["customers_id"],
                    "booking_date" => $row["booking_created_at"],
                    "booking_status" => $row["booking_status_name"],
                    "guests_amnt" => $row["guests_amnt"],
                    "booking_payment" => $row["booking_payment"],
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
                    booking_payment = :newDownpayment
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

    function login($json)
    {
        // {"username":"sabils","password":"sabils"}
        include "connection.php";
        $data = json_decode($json, true);

        // First, try to find user in tbl_customers_online (Customer login)
        $sql = "SELECT a.customers_online_id, a.customers_online_password, b.*
        FROM tbl_customers_online a 
        INNER JOIN tbl_customers b ON b.customers_online_id = a.customers_online_id
        WHERE a.customers_online_username = :customers_online_username";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":customers_online_username", $data["username"]);
        $stmt->execute();

        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            $storedPassword = $user["customers_online_password"];
            $inputPassword = $data["password"];

            // Check if the stored password is hashed (starts with $2y$)
            if (password_get_info($storedPassword)['algo'] !== null) {
                // Password is hashed, use password_verify
                if (password_verify($inputPassword, $storedPassword)) {
                    // Remove password from returned data for security
                    unset($user["customers_online_password"]);
                    return [
                        "success" => true,
                        "user" => $user,
                        "user_type" => "customer"
                    ];
                }
            } else {
                // Password is plain text (legacy), compare directly
                if ($inputPassword === $storedPassword) {
                    // Remove password from returned data for security
                    unset($user["customers_online_password"]);
                    return [
                        "success" => true,
                        "user" => $user,
                        "user_type" => "customer"
                    ];
                }
            }
        }

        // If not found in customers, try to find in tbl_employee (Employee/Admin login)
        $sql = "SELECT e.*, ul.userlevel_name 
                FROM tbl_employee e 
                LEFT JOIN tbl_user_level ul ON e.employee_user_level_id = ul.userlevel_id 
                WHERE e.employee_username = :employee_username AND (e.employee_status = 'Active' OR e.employee_status = 'Offline')";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":employee_username", $data["username"]);
        $stmt->execute();

        $employee = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($employee) {
            $storedPassword = $employee["employee_password"];
            $inputPassword = $data["password"];

            // Check if the stored password is hashed (starts with $2y$)
            if (password_get_info($storedPassword)['algo'] !== null) {
                // Password is hashed, use password_verify
                if (password_verify($inputPassword, $storedPassword)) {
                    // Update employee status to 'Active' when logging in
                    $updateSql = "UPDATE tbl_employee SET employee_status = 'Active', employee_updated_at = NOW() WHERE employee_id = :employee_id";
                    $updateStmt = $conn->prepare($updateSql);
                    $updateStmt->bindParam(":employee_id", $employee["employee_id"]);
                    $updateStmt->execute();

                    // Remove password from returned data for security
                    unset($employee["employee_password"]);
                    return [
                        "success" => true,
                        "user" => $employee,
                        "user_type" => $employee["userlevel_name"] === "Admin" ? "admin" : "employee"
                    ];
                }
            } else {
                // Password is plain text (legacy), compare directly
                if ($inputPassword === $storedPassword) {
                    // Update employee status to 'Active' when logging in
                    $updateSql = "UPDATE tbl_employee SET employee_status = 'Active', employee_updated_at = NOW() WHERE employee_id = :employee_id";
                    $updateStmt = $conn->prepare($updateSql);
                    $updateStmt->bindParam(":employee_id", $employee["employee_id"]);
                    $updateStmt->execute();

                    // Remove password from returned data for security
                    unset($employee["employee_password"]);
                    return [
                        "success" => true,
                        "user" => $employee,
                        "user_type" => $employee["userlevel_name"] === "Admin" ? "admin" : "employee"
                    ];
                }
            }
        }

        // Check if username exists in customers_online table but no online account
        $checkCustomerSql = "SELECT customers_online_id FROM tbl_customers_online WHERE customers_online_username = :username";
        $checkCustomerStmt = $conn->prepare($checkCustomerSql);
        $checkCustomerStmt->bindParam(":username", $data["username"]);
        $checkCustomerStmt->execute();
        $existingCustomer = $checkCustomerStmt->fetch(PDO::FETCH_ASSOC);

        if ($existingCustomer) {
            return [
                "success" => false,
                "message" => "User does not exist, Please Register first"
            ];
        }

        return [
            "success" => false,
            "message" => "Invalid username or password"
        ];
    }

    function getBookingSummary($json)
    {
        include "connection.php";
        $json = json_decode($json, true);
        $bookingCustomerId = $json['booking_customer_id'] ?? 0;
        $today = date("Y-m-d");

        $sql = "SELECT a.*, b.*, c.*, d.*, 
                   f.booking_charges_id,
                   f.booking_charges_quantity,
                   f.booking_charges_price,
                   g.charges_master_id,
                   g.charges_master_name,
                   g.charges_master_price,
                   h.charges_category_id,
                   h.charges_category_name,
                   IFNULL(i.charges_status_name, 'Pending') AS charges_status_name,
                   IFNULL(f.booking_charge_status, 1) AS booking_charge_status
            FROM tbl_booking a
            INNER JOIN tbl_booking_room b ON b.booking_id = a.booking_id
            INNER JOIN tbl_roomtype c ON c.roomtype_id = b.roomtype_id
            INNER JOIN tbl_rooms d ON d.roomnumber_id = b.roomnumber_id
            LEFT JOIN tbl_booking_charges f ON f.booking_room_id = b.booking_room_id
            LEFT JOIN tbl_charges_master g ON g.charges_master_id = f.charges_master_id
            LEFT JOIN tbl_charges_category h ON h.charges_category_id = g.charges_category_id
            LEFT JOIN tbl_charges_status i ON i.charges_status_id = f.booking_charge_status
            WHERE (a.customers_id = :bookingCustomerId OR a.customers_walk_in_id = :bookingCustomerId)
              AND :today BETWEEN DATE(a.booking_checkin_dateandtime) AND DATE(a.booking_checkout_dateandtime)
            ORDER BY a.booking_created_at DESC";

        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':today', $today);
        $stmt->bindParam(':bookingCustomerId', $bookingCustomerId);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $bookings = [];

        foreach ($rows as $row) {
            $bookingId = $row['booking_id'];
            $roomKey = $row['booking_room_id'];

            if (!isset($bookings[$bookingId])) {
                $bookings[$bookingId] = [
                    "booking_id" => $row['booking_id'],
                    "customers_id" => $row['customers_id'],
                    "customers_walk_in_id" => $row['customers_walk_in_id'],
                    "guests_amnt" => $row['guests_amnt'],
                    "booking_payment" => $row['booking_payment'],
                    "reference_no" => $row['reference_no'],
                    "booking_checkin_dateandtime" => $row['booking_checkin_dateandtime'],
                    "booking_checkout_dateandtime" => $row['booking_checkout_dateandtime'],
                    "booking_created_at" => $row['booking_created_at'],
                    "booking_isArchive" => $row['booking_isArchive'],
                    "chargesTotal" => 0,
                    "roomsTotal" => 0,
                    "booking_totalAmount" => $row['booking_totalAmount'],
                    "roomsList" => []
                ];
            }

            if (!isset($bookings[$bookingId]['roomsList'][$roomKey])) {
                $bookings[$bookingId]['roomsList'][$roomKey] = [
                    "booking_room_id" => $row['booking_room_id'],
                    "roomtype_id" => $row['roomtype_id'],
                    "roomtype_name" => $row['roomtype_name'],
                    "roomtype_description" => $row['roomtype_description'],
                    "roomtype_price" => (float)$row['roomtype_price'],
                    "roomnumber_id" => $row['roomnumber_id'],
                    "roomfloor" => $row['roomfloor'],
                    "room_status_id" => $row['room_status_id'],
                    "charges_raw" => [],
                    "isAddBed" => 0 // default
                ];
                $bookings[$bookingId]['roomsTotal'] += (float)$row['roomtype_price'];
            }

            if ($row['booking_charges_id']) {
                $bookings[$bookingId]['roomsList'][$roomKey]['charges_raw'][] = [
                    "charges_master_id" => $row['charges_master_id'],
                    "qty" => $row['booking_charges_quantity'],
                    "price" => $row['booking_charges_price'],
                    "category" => $row['charges_category_name'],
                    "name" => $row['charges_master_name'],
                    "status_id" => $row['booking_charge_status'],
                    "status_name" => $row['charges_status_name'],
                    "booking_charges_id" => $row['booking_charges_id']
                ];
                $bookings[$bookingId]['chargesTotal'] += $row['booking_charges_price'];
            }

            // ✅ Only count Bed if not cancelled
            if ($row['charges_master_id'] == 2 && $row['booking_charge_status'] != 3) {
                $bookings[$bookingId]['roomsList'][$roomKey]['isAddBed'] = 1;
            }
        }

        foreach ($bookings as &$booking) {
            foreach ($booking['roomsList'] as &$room) {
                $room['charges'] = array_map(function ($c) {
                    return [
                        "charges_master_id" => $c['charges_master_id'],
                        "charges_master_name" => $c['name'],
                        "charges_category_name" => $c['category'],
                        "charges_master_status_id" => $c['status_id'],
                        "charges_status_name" => $c['status_name'],
                        "booking_charges_id" => $c['booking_charges_id'],
                        "booking_charges_quantity" => $c['qty'],
                        "booking_charges_price" => $c['price'],
                        "total" => $c['price'] * $c['qty']
                    ];
                }, $room['charges_raw']);
                unset($room['charges_raw']);
            }
            $booking['roomsList'] = array_values($booking['roomsList']);
        }

        return array_values($bookings);
    }


    function checkAndSendOTP($json)
    {
        include "connection.php";
        include "send_email.php";
        $data = json_decode($json, true);
        try {
            // 1. Check if email already exists in tbl_customers (with online account)
            $sql = "SELECT c.customers_id 
                    FROM tbl_customers c
                    WHERE c.customers_email = :email 
                    AND c.customers_online_id IS NOT NULL 
                    LIMIT 1";
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(":email", $data["guest_email"]);
            $stmt->execute();

            // check if username exists in tbl_customers
            if (recordExists($data["username"], "tbl_customers_online", "customers_online_username")) {
                return json_encode([
                    "success" => false,
                    "message" => "Username already exists."
                ]);
            }

            if ($stmt->rowCount() > 0) {
                return json_encode([
                    "success" => false,
                    "message" => "Email already registered as an online customer."
                ]);
            }

            // 2. Also check tbl_customers_online (in case of direct registration record)
            $sql = "SELECT customers_online_id 
                    FROM tbl_customers_online 
                    WHERE customers_online_email = :email 
                    LIMIT 1";
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(":email", $data["guest_email"]);
            $stmt->execute();

            if ($stmt->rowCount() > 0) {
                return json_encode([
                    "success" => false,
                    "message" => "Email already exists in online accounts."
                ]);
            }

            // 3. Get OTP from frontend
            $otp = $data["otp_code"];

            // 4. Send email with OTP
            $emailTo = $data["guest_email"];
            $emailSubject = "Demiren Hotel - Registration OTP";
            $emailBody = '
            <html>
            <head>
            <style>
                body { font-family: Arial, sans-serif; color: #333; background-color: #f9f9f9; padding: 20px; }
                .container { background-color: #fff; border-radius: 10px; padding: 30px; max-width: 600px; margin: auto; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
                h2 { color: #1a73e8; text-align: center; }
                .otp-code { font-size: 32px; font-weight: bold; color: #1a73e8; background: #f0f8ff; padding: 20px; border-radius: 8px; text-align: center; margin: 20px 0; letter-spacing: 5px; }
                .info { background-color: #f8f9fa; padding: 15px; border-radius: 5px; margin: 15px 0; }
                .footer { font-size: 12px; color: #777; margin-top: 30px; text-align: center; }
            </style>
            </head>
            <body>
            <div class="container">
                <h2>Registration Verification</h2>
                <p>Thank you for registering with <strong>Demiren Hotel & Restaurant</strong>.</p>
                <p>Please use the following OTP code to complete your registration:</p>
                
                <div class="otp-code">' . $otp . '</div>
                
                <div class="info">
                    <p><strong>Important:</strong></p>
                    <ul>
                        <li>This OTP is valid for 5 minutes only</li>
                        <li>Do not share this code with anyone</li>
                        <li>If you did not request this registration, please ignore this email</li>
                    </ul>
                </div>
                
                <p>If you have any questions, please contact our support team.</p>
                
                <div class="footer">
                    This is an automated message from Demiren Hotel & Restaurant.<br>
                    Please do not reply to this email.
                </div>
            </div>
            </body>
            </html>';

            // Use the existing sendEmail function
            $sendEmail = new SendEmail();
            $emailResult = $sendEmail->sendEmail($emailTo, $emailSubject, $emailBody);

            if ($emailResult === true) {
                // Log successful OTP sending
                error_log("OTP sent successfully to: " . $emailTo);
                return json_encode([
                    "success" => true,
                    "message" => "OTP sent successfully to your email."
                ]);
            } else {
                // Log failed OTP sending with more details
                error_log("Failed to send OTP to: " . $emailTo);
                error_log("Email result: " . print_r($emailResult, true));
                error_log("OTP code: " . $otp);
                error_log("Email subject: " . $emailSubject);

                return json_encode([
                    "success" => false,
                    "message" => "Failed to send OTP email. Please check your email address and try again."
                ]);
            }
        } catch (PDOException $e) {
            return json_encode([
                "success" => false,
                "message" => "Database error: " . $e->getMessage()
            ]);
        } catch (Exception $e) {
            return json_encode([
                "success" => false,
                "message" => "Error: " . $e->getMessage()
            ]);
        }
    }

    function checkUsernameExists($json)
    {
        include "connection.php";
        $data = json_decode($json, true);

        try {
            // Check if username already exists in tbl_customers_online
            $sql = "SELECT customers_online_id 
                    FROM tbl_customers_online 
                    WHERE customers_online_username = :username 
                    LIMIT 1";
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(":username", $data["username"]);
            $stmt->execute();

            if ($stmt->rowCount() > 0) {
                return json_encode([
                    "success" => false,
                    "exists" => true,
                    "message" => "Username already exists. Please choose a different username."
                ]);
            }

            // Also check if username exists in employee table (to avoid conflicts)
            $sql = "SELECT employee_id 
                    FROM tbl_employee 
                    WHERE employee_username = :username 
                    LIMIT 1";
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(":username", $data["username"]);
            $stmt->execute();

            if ($stmt->rowCount() > 0) {
                return json_encode([
                    "success" => false,
                    "exists" => true,
                    "message" => "Username already exists. Please choose a different username."
                ]);
            }

            return json_encode([
                "success" => true,
                "exists" => false,
                "message" => "Username is available."
            ]);
        } catch (PDOException $e) {
            return json_encode([
                "success" => false,
                "message" => "Database error: " . $e->getMessage()
            ]);
        } catch (Exception $e) {
            return json_encode([
                "success" => false,
                "message" => "Error: " . $e->getMessage()
            ]);
        }
    }

    function getAmenitiesMaster()
    {
        include "connection.php";
        $sql = "SELECT a.*, b.charges_category_name FROM tbl_charges_master a 
                INNER JOIN tbl_charges_category b ON b.charges_category_id = a.charges_category_id
                WHERE a.charges_category_id = 1";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        return $stmt->rowCount() > 0 ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    }

    function addBookingCharges($json)
    {
        // {"bookingId": 41, 
        //  "charges": [
        //    {"booking_room_id":32, "charges_master_id":1, "charges_quantity":2, "booking_charges_price": 200}, 
        //    {"booking_room_id":32, "charges_master_id":2, "charges_quantity":1, "booking_charges_price": 400}
        //  ]
        // }
        include "connection.php";

        $data = json_decode($json, true);

        $charges = $data["charges"];
        $bookingId = $data["bookingId"];
        $notes = $data["notes"];

        try {
            $conn->beginTransaction();

            $sql = "INSERT INTO tbl_booking_charges_notes(booking_c_notes)
                    VALUES (:booking_c_notes)";
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(":booking_c_notes", $notes);
            $stmt->execute();
            $lastInsertId = $conn->lastInsertId();
            $sqlInsert = "INSERT INTO tbl_booking_charges 
            (booking_room_id, charges_master_id, booking_charges_quantity, booking_charges_price, booking_charges_notes_id )
            VALUES 
            (:booking_room_id, :charges_master_id, :charges_quantity, :booking_charges_price, :booking_c_notes_id)";
            $stmtInsert = $conn->prepare($sqlInsert);

            $totalPrice = 0;
            foreach ($charges as $charge) {
                $stmtInsert->bindParam(":booking_room_id", $charge["booking_room_id"]);
                $stmtInsert->bindParam(":charges_master_id", $charge["charges_master_id"]);
                $stmtInsert->bindParam(":charges_quantity", $charge["charges_quantity"]);
                $stmtInsert->bindParam(":booking_charges_price", $charge["booking_charges_price"]);
                $stmtInsert->bindParam(":booking_c_notes_id", $lastInsertId);
                $stmtInsert->execute();

                $totalPrice += $charge["booking_charges_price"];
            }

            // $sqlSelect = "SELECT booking_totalAmount FROM tbl_booking WHERE booking_id = :bookingId";
            // $stmtSelect = $conn->prepare($sqlSelect);
            // $stmtSelect->bindParam(":bookingId", $bookingId);
            // $stmtSelect->execute();
            // $currentTotal = $stmtSelect->fetchColumn();

            // $newTotal = $currentTotal + $totalPrice;

            // $sqlUpdate = "UPDATE tbl_booking SET booking_totalAmount = :newTotal WHERE booking_id = :bookingId";
            // $stmtUpdate = $conn->prepare($sqlUpdate);
            // $stmtUpdate->bindParam(":newTotal", $newTotal);
            // $stmtUpdate->bindParam(":bookingId", $bookingId);
            // $stmtUpdate->execute();

            $conn->commit();
            return 1;
        } catch (PDOException $e) {
            $conn->rollBack();
            return $e;
        }
    }

    function getRoomTypeDetails($json)
    {
        // {"roomTypeId": 1}
        include "connection.php";
        $json = json_decode($json, true);
        $roomTypeId = $json['roomTypeId'];
        $sql = "SELECT * FROM tbl_roomtype WHERE roomtype_id = :roomTypeId";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':roomTypeId', $roomTypeId);
        $stmt->execute();
        $roomTypeMaster = $stmt->rowCount() > 0 ? $stmt->fetch(PDO::FETCH_ASSOC) : [];
        $sql = "SELECT imagesroommaster_filename FROM tbl_imagesroommaster WHERE roomtype_id = :roomTypeId";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':roomTypeId', $roomTypeId);
        $stmt->execute();
        $roomTypeImages = $stmt->rowCount() > 0 ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $roomTypeMaster["images"] = $roomTypeImages;
        return $roomTypeMaster;
    }

    function getPaymentMethod()
    {
        include "connection.php";
        $sql = "SELECT * FROM tbl_payment_method";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        return $stmt->rowCount() > 0 ? $stmt->fetchAll(PDO::FETCH_ASSOC) : 0;
    }

    function getCustomerLogs($json)
    {
        // {"customerId": 1}
        include "connection.php";
        $json = json_decode($json, true);
        $customerId = $json['customerId'];
        $sql = "SELECT * FROM tbl_activitylogs WHERE user_type = 'customer' AND user_id = :customerId";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':customerId', $customerId);
        $stmt->execute();
        return $stmt->rowCount() > 0 ? $stmt->fetchAll(PDO::FETCH_ASSOC) : 0;
    }

    function cancelReqAmenities($json)
    {
        include "connection.php";
        date_default_timezone_set('Asia/Manila');
        $data = json_decode($json, true);

        // 🔹 Step 1: Get the request date/time
        $sql = "SELECT booking_charge_date 
            FROM tbl_booking_charges 
            WHERE booking_charges_id = :bookingChargesId";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":bookingChargesId", $data["bookingChargesId"]);
        $stmt->execute();
        $charge = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$charge) {
            return 0; // ❌ Request not found
        }

        // 🔹 Step 2: Check if more than 10 minutes have passed
        $requestTime = strtotime($charge["booking_charge_date"]);
        $currentTime = time();
        $minutesDiff = ($currentTime - $requestTime) / 60;

        if ($minutesDiff > 10) {
            // ❌ Too late to cancel
            return -1;
        }

        // 🔹 Step 3: Proceed with cancellation
        $sql = "UPDATE tbl_booking_charges 
            SET booking_charge_status = 3 
            WHERE booking_charges_id = :bookingChargesId";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":bookingChargesId", $data["bookingChargesId"]);
        $stmt->execute();

        return $stmt->rowCount() > 0 ? 1 : 0;
    }
    //     function getRoomImages($json) {
    //     try {     
    //         include "connection.php";
    //         $stmt = $pdo->prepare("
    //             SELECT 
    //                 rt.roomtype_id,
    //                 rt.roomtype_name,
    //                 GROUP_CONCAT(img.imagesroommaster_filename ORDER BY img.imagesroommaster_id ASC) as image_filenames,
    //                 COUNT(img.imagesroommaster_id) as image_count
    //             FROM tbl_roomtype rt
    //             LEFT JOIN tbl_imagesroommaster img ON rt.roomtype_id = img.roomtype_id
    //             WHERE img.imagesroommaster_filename IS NOT NULL
    //             GROUP BY rt.roomtype_id, rt.roomtype_name
    //             ORDER BY rt.roomtype_id ASC
    //         ");

    //         $stmt->execute();
    //         $rowCount = $stmt->rowCount();

    //         if ($rowCount > 0) {
    //             $result = array();
    //             while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    //                 $images = explode(',', $row['image_filenames']);
    //                 $result[] = array(
    //                     'roomtype_id' => $row['roomtype_id'],
    //                     'roomtype_name' => $row['roomtype_name'],
    //                     'images' => $images,
    //                     'image_count' => $row['image_count']
    //                 );
    //             }
    //             return json_encode($result);
    //         } else {
    //             return 0;
    //         }

    //     } catch(PDOException $e) {
    //         return 0;
    //     }
    // }

    function getCurrentBalance($bookingId)
    {
        include "connection.php";

        $sql = "
        SELECT billing_balance 
        FROM tbl_billing 
        WHERE booking_id = :bookingId 
        ORDER BY billing_id DESC 
        LIMIT 1
    ";

        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':bookingId', $bookingId, PDO::PARAM_INT);
        $stmt->execute();

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['billing_balance'] : 0;
    }


    function sendMessageEmail($json)
    {
        include "connection.php";
        include "send_email.php";
        $data = json_decode($json, true);
        $emailToSent = "xmelmacario@gmail.com";
        $guestEmail = $data["email"];
        $message = $data["message"];
        $name = $data["name"];

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

            <div class="details">
            <p><span class="label">Customer Name:</span> ' . $name . '</p>
            <p><span class="label">Guest Email:</span> ' . $guestEmail . '</p>
            <p><span class="label">Message:</span> ' . $message . '</p>
            </div>

            <div class="footer">
            This is an automated message. Please do not reply to this email.
            </div>
        </div>
        </body>
        </html>';
        $sendEmail = new SendEmail();
        return $sendEmail->sendEmail($emailToSent, "Customer Question", $emailBody);
    }

    function isRoomAvailable($json)
    {
        include "connection.php";
        $json = json_decode($json, true);
        $bookingDetails = $json["bookingDetails"];
        $roomDetails = $json["roomDetails"];
        $checkIn = $bookingDetails["checkIn"];
        $checkOut = $bookingDetails["checkOut"];
        foreach ($roomDetails as $room) {
            $roomTypeId = $room["roomTypeId"];
            $availabilityStmt = $conn->prepare("
                SELECT r.roomnumber_id
                FROM tbl_rooms r
                WHERE r.roomtype_id = :roomtype_id
                AND r.room_status_id = 3
                AND r.roomnumber_id NOT IN (
                    SELECT br.roomnumber_id
                    FROM tbl_booking_room br
                    JOIN tbl_booking b ON br.booking_id = b.booking_id
                    WHERE br.roomtype_id = :roomtype_id
                        AND b.booking_isArchive = 0
                        AND (
                            b.booking_checkin_dateandtime < :check_out 
                            AND b.booking_checkout_dateandtime > :check_in
                        )
                        AND br.roomnumber_id IS NOT NULL
                )
                LIMIT 1
            ");
            $availabilityStmt->bindParam(":roomtype_id", $roomTypeId);
            $availabilityStmt->bindParam(":check_in", $checkIn);
            $availabilityStmt->bindParam(":check_out", $checkOut);
            $availabilityStmt->execute();

            $availableRoom = $availabilityStmt->fetch(PDO::FETCH_ASSOC);

            if (!$availableRoom) {
                return -1;
            }
        }
        return 1;
    }

    function hasCustomerCheckOuted($json)
    {
        include "connection.php";
        $data = json_decode($json, true);
        $sql = "
            SELECT 
            CASE 
                WHEN COUNT(*) > 0 THEN 1
                ELSE 0
            END AS has_checked_out
            FROM tbl_booking b
            JOIN tbl_booking_history bh ON b.booking_id = bh.booking_id
            JOIN tbl_booking_status bs ON bh.status_id = bs.booking_status_id
            WHERE b.customers_id = :customerId
            AND bs.booking_status_name = 'Checked-Out'
        ";

        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":customerId", $data["customerId"]);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result["has_checked_out"];
    }

    function getCustomerFeedback($json)
    {
        include "connection.php";
        $data = json_decode($json, true);
        $sql = "SELECT * FROM tbl_customersreviews WHERE customers_id = :customerId";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":customerId", $data["customerId"]);
        $stmt->execute();
        return $stmt->rowCount() > 0 ? $stmt->fetch(PDO::FETCH_ASSOC) : 0;
    }

    function getBedPrice()
    {
        include "connection.php";
        $sql = "SELECT charges_master_price FROM tbl_charges_master WHERE charges_master_id = 2";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC)["charges_master_price"];
    }

    function getExtraGuestPrice()
    {
        include "connection.php";
        $sql = "SELECT charges_master_price FROM tbl_charges_master WHERE charges_master_id = 12";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC)["charges_master_price"];
    }
} //customer

function recordExists($value, $table, $column)
{
    include "connection.php";
    $sql = "SELECT COUNT(*) FROM $table WHERE $column = :value";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(":value", $value);
    $stmt->execute();
    $count = $stmt->fetchColumn();
    return $count > 0;
}

function uploadImage()
{
    if (isset($_FILES["file"])) {
        $file = $_FILES['file'];
        // print_r($file);
        $fileName = $_FILES['file']['name'];
        $fileTmpName = $_FILES['file']['tmp_name'];
        $fileSize = $_FILES['file']['size'];
        $fileError = $_FILES['file']['error'];
        // $fileType = $_FILES['file']['type'];

        $fileExt = explode(".", $fileName);
        $fileActualExt = strtolower(end($fileExt));

        $allowed = ["jpg", "jpeg", "png"];

        if (in_array($fileActualExt, $allowed)) {
            if ($fileError === 0) {
                if ($fileSize < 25000000) {
                    $fileNameNew = uniqid("", true) . "." . $fileActualExt;
                    $fileDestination =  'images/' . $fileNameNew;
                    move_uploaded_file($fileTmpName, $fileDestination);
                    return $fileNameNew;
                } else {
                    return 4;
                }
            } else {
                return 3;
            }
        } else {
            return 2;
        }
    } else {
        return "";
    }

    // $returnValueImage = uploadImage();
    // switch ($returnValueImage) {
    //   case 2:
    //     return 2; // invalid file type
    //   case 3:
    //     return 3; // upload error
    //   case 4:
    //     return 4; // file too big
    //   default:
    //     break;
    // }
}


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
    case "getBookingSummary":
        echo json_encode($demiren_customer->getBookingSummary($json));
        break;
    case "checkAndSendOTP";
        echo $demiren_customer->checkAndSendOTP($json);
        break;
    case "getAmenitiesMaster":
        echo json_encode($demiren_customer->getAmenitiesMaster());
        break;
    case "addBookingCharges":
        echo json_encode($demiren_customer->addBookingCharges($json));
        break;
    case "getRoomTypeDetails";
        echo json_encode($demiren_customer->getRoomTypeDetails($json));
        break;
    case "getPaymentMethod":
        echo json_encode($demiren_customer->getPaymentMethod());
        break;
    case "getCustomerLogs":
        echo json_encode($demiren_customer->getCustomerLogs($json));
        break;
    case "cancelReqAmenities":
        echo json_encode($demiren_customer->cancelReqAmenities($json));
        break;
    case "sendMessageEmail":
        echo $demiren_customer->sendMessageEmail($json);
        break;
    case "isRoomAvailable":
        echo json_encode($demiren_customer->isRoomAvailable($json));
        break;
    // case "getRoomImages":
    //     echo json_encode($demiren_customer->getRoomImages($json));
    //     break;
    case "hasCustomerCheckOuted":
        echo json_encode($demiren_customer->hasCustomerCheckOuted($json));
        break;
    case "getCustomerFeedback":
        echo json_encode($demiren_customer->getCustomerFeedback($json));
        break;
    case "getBedPrice":
        echo json_encode($demiren_customer->getBedPrice());
        break;
    case "getExtraGuestPrice":
        echo json_encode($demiren_customer->getExtraGuestPrice());
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
//ayoko na
//tama na
