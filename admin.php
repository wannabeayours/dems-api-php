<?php

include 'headers.php';

class Admin_Functions
{

    // ------------------------------------------------------- No Table For Admin Yet, needs Changes ------------------------------------------------------- //
    // Login & Signup ???
    function admin_login($data)
    {
        include "connection.php";

        // Only allow login for Admins (userlevel_id = 1)
        $sql = "SELECT employee_id, employee_fname, employee_lname, employee_username, employee_email
                FROM tbl_employee
                WHERE employee_user_level_id = 1
                  AND employee_email = :email
                  AND employee_password = :password
                LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":email", $data["email"]);
        $stmt->bindParam(":password", $data["password"]);
        $stmt->execute();
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($admin) {
            return json_encode([
                "success" => true,
                "admin" => $admin
            ]);
        } else {
            return json_encode([
                "success" => false,
                "message" => "Invalid credentials or not an admin."
            ]);
        }
    }

    // Rooms
    function getAvailableRooms()
    {
        include "connection.php";
        $sql = "SELECT a.roomnumber_id, a.roomfloor, a.roomtype_id, 
                   b.roomtype_name, c.status_name
            FROM tbl_rooms AS a
            INNER JOIN tbl_roomtype AS b ON b.roomtype_id = a.roomtype_id
            INNER JOIN tbl_status_types AS c ON c.status_id = a.room_status_id
            WHERE a.room_status_id = 3
            ORDER BY b.roomtype_id, a.roomnumber_id ASC";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $grouped = [];
        foreach ($rooms as $room) {
            $typeId = $room['roomtype_id'];
            if (!isset($grouped[$typeId])) {
                $grouped[$typeId] = [
                    "roomtype_id" => $typeId,
                    "roomtype_name" => $room['roomtype_name'],
                    "room_count" => 0,
                    "vacant_rooms" => []
                ];
            }
            $grouped[$typeId]["room_count"]++;
            $grouped[$typeId]["vacant_rooms"][] = [
                "roomnumber_id" => $room["roomnumber_id"],
                "roomfloor" => $room["roomfloor"]
            ];
        }

        echo json_encode(array_values($grouped));
    }

    function viewAvailRooms()
    {
        include "connection.php";

        $sql = "SELECT 
                    r.roomnumber_id,
                    r.roomfloor,
                    r.room_capacity,
                    r.room_beds,
                    r.room_sizes,
                    rt.roomtype_name,
                    rt.roomtype_description,
                    rt.roomtype_price,
                    GROUP_CONCAT(img.imagesroommaster_filename ORDER BY img.imagesroommaster_filename ASC) AS images,
                    st.status_name
                FROM tbl_rooms r
                JOIN tbl_roomtype rt 
                    ON r.roomtype_id = rt.roomtype_id
                JOIN tbl_status_types st 
                    ON r.room_status_id = st.status_id
                LEFT JOIN tbl_imagesroommaster img 
                    ON r.roomtype_id = img.roomtype_id
                WHERE st.status_name = 'Vacant'
                GROUP BY 
                    r.roomnumber_id,
                    r.roomfloor,
                    r.room_capacity,
                    r.room_beds,
                    r.room_sizes,
                    rt.roomtype_name,
                    rt.roomtype_description,
                    rt.roomtype_price,
                    st.status_name;";

        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $rowCount = $stmt->rowCount();
        unset($stmt, $conn);

        return $rowCount > 0 ? json_encode($result) : 0;
    }

    // ------------------------------------------------------- Booking Functions ------------------------------------------------------- //
    function viewBookingList()
    {
        include 'connection.php';

        $sql = "SELECT 
                    b.reference_no,
                    b.booking_id,
                    COALESCE(CONCAT(c.customers_fname, ' ', c.customers_lname),
                             CONCAT(w.customers_walk_in_fname, ' ', w.customers_walk_in_lname)) AS customer_name,
                    b.booking_checkin_dateandtime,
                    b.booking_checkout_dateandtime,
                    GROUP_CONCAT(br.roomnumber_id ORDER BY br.booking_room_id ASC) AS room_numbers,
                    COALESCE(bs.booking_status_name, 'Pending') AS booking_status
                FROM tbl_booking b
                LEFT JOIN tbl_customers c ON b.customers_id = c.customers_id
                LEFT JOIN tbl_customers_walk_in w ON b.customers_walk_in_id = w.customers_walk_in_id
                LEFT JOIN tbl_booking_room br ON b.booking_id = br.booking_id
                LEFT JOIN (
                    SELECT bh.booking_id, bs.booking_status_name
                    FROM tbl_booking_history bh
                    INNER JOIN tbl_booking_status bs ON bh.status_id = bs.booking_status_id
                    WHERE bh.status_book_id IN (
                        SELECT MAX(status_book_id)
                        FROM tbl_booking_history
                        GROUP BY booking_id
                    )
                ) bs ON bs.booking_id = b.booking_id
                GROUP BY b.booking_id
                ORDER BY b.booking_created_at DESC";

        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $rowAmnt = $stmt->rowCount();
        unset($conn, $stmt);

        return $rowAmnt > 0 ? json_encode($result) : "Data Not Fetched";
    }

    // Walk-in


    function change_bookStatus($data)
    {
        include 'connection.php';

        try {
            // Reminder to accept Employee ID
            // $emp_id = intval($data["emp_id"]);
            $book_id = intval($data["booking_id"]);
            $status_id = intval($data["booking_status_id"]);

            $stmt  = $conn->prepare(
                "INSERT INTO tbl_booking_history (booking_id, employee_id, status_id,updated_at)
                VALUES (:booking_id, 2, :status_id, NOW())"
            );

            $stmt->bindParam(":booking_id", $book_id);
            // $stmt->bindParam(":employee_id", $emp_id);
            $stmt->bindParam(":status_id", $status_id);
            $stmt->execute();

            $rowCount = $stmt->rowCount();
            unset($stmt, $conn);

            return $rowCount > 0 ? json_encode(["success" => true]) : json_encode(["success" => false]);
        } catch (PDOException $e) {
            return json_encode(["success" => false, "error" => $e->getMessage()]);
        }
    }

    function getAllNationalities()
    {
        include 'connection.php'; // $pdo is your PDO connection

        try {
            $sql = "SELECT * FROM tbl_nationality";
            $stmt = $conn->prepare($sql);
            $stmt->execute();

            $nationalities = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode($nationalities);
        } catch (PDOException $e) {
            return false; // Or you could echo json_encode([]) if you want an empty array
        }
    }

    function getAllStatus()
    {
        include 'connection.php';

        try {
            $sql = "SELECT * FROM tbl_status_types";
            $stmt = $conn->prepare($sql);
            $stmt->execute();

            $statuses = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode($statuses);
        } catch (PDOException $e) {
            return false;
        }
    }

    function insertWalkInBooking($data)
    {
        include 'connection.php';

        try {
            $conn->beginTransaction();

            // 1. Insert customer identification if provided
            $identificationId = null;
            if (!empty($data['identification_id'])) {
                $stmt = $conn->prepare("INSERT INTO tbl_customer_identification (customer_identification_attachment_filename) VALUES (:filename)");
                $stmt->execute([':filename' => $data['identification_id']]);
                $identificationId = $conn->lastInsertId();
            }

            // 2. Insert into tbl_customers
            $stmt = $conn->prepare("INSERT INTO tbl_customers 
                (nationality_id, identification_id, customers_fname, customers_lname, customers_email, customers_phone_number, customers_date_of_birth)
                VALUES (:nationality_id, :identification_id, :fname, :lname, :email, :phone, :dob)");
            $stmt->execute([
                ':nationality_id' => $data['nationality_id'],
                ':identification_id' => $identificationId,
                ':fname' => $data['customers_fname'],
                ':lname' => $data['customers_lname'],
                ':email' => $data['customers_email'],
                ':phone' => $data['customers_phone_number'],
                ':dob' => $data['customers_date_of_birth']
            ]);
            $customerId = $conn->lastInsertId();

            // 3. Insert into tbl_customers_walk_in
            $stmt = $conn->prepare("INSERT INTO tbl_customers_walk_in 
                (customers_id, customers_walk_in_fname, customers_walk_in_lname, customers_walk_in_phone_number, customers_walk_in_email, customers_walk_in_address)
                VALUES (:customers_id, :fname, :lname, :phone, :email, :address)");
            $stmt->execute([
                ':customers_id' => $customerId,
                ':fname' => $data['customers_fname'],
                ':lname' => $data['customers_lname'],
                ':phone' => $data['customers_phone_number'],
                ':email' => $data['customers_email'],
                ':address' => $data['customers_address']
            ]);
            $walkInId = $conn->lastInsertId();

            // 4. Insert into tbl_booking
            $stmt = $conn->prepare("INSERT INTO tbl_booking
                (customers_id, customers_walk_in_id, adult, children, guests_amnt, booking_totalAmount, booking_downpayment, reference_no, booking_checkin_dateandtime, booking_checkout_dateandtime, booking_created_at, booking_isArchive)
                VALUES (:customers_id, :walkin_id, :adult, :children, :guests, :total_amount, :downpayment, :ref_no, :checkin, :checkout, NOW(), 0)");
            $stmt->execute([
                ':customers_id' => $customerId,
                ':walkin_id' => $walkInId,
                ':adult' => $data['adult'] ?? 1,
                ':children' => $data['children'] ?? 0,
                ':guests' => $data['guests_amnt'],
                ':total_amount' => $data['booking_totalAmount'],
                ':downpayment' => $data['booking_downpayment'],
                ':ref_no' => $data['reference_no'],
                ':checkin' => $data['booking_checkin_dateandtime'],
                ':checkout' => $data['booking_checkout_dateandtime']
            ]);
            $bookingId = $conn->lastInsertId();

            // 5. Insert booking history (status_id = 2 for Approved)
            $stmt = $conn->prepare("INSERT INTO tbl_booking_history
                (booking_id, employee_id, status_id, updated_at)
                VALUES (:booking_id, :employee_id, :status_id, NOW())");
            $stmt->execute([
                ':booking_id' => $bookingId,
                ':employee_id' => $data['employee_id'] ?? 1,
                ':status_id' => 2 // Approved
            ]);

            // 6. Assign rooms and update their status to Occupied
            foreach ($data['selectedRooms'] as $room) {
                // Insert into tbl_booking_room
                $stmt = $conn->prepare("INSERT INTO tbl_booking_room (booking_id, roomtype_id, roomnumber_id)
                    VALUES (:booking_id, :roomtype_id, :roomnumber_id)");
                $stmt->execute([
                    ':booking_id' => $bookingId,
                    ':roomtype_id' => $room['roomtype_id'],
                    ':roomnumber_id' => $room['roomnumber_id']
                ]);

                // Update room status to Occupied (status_id = 1)
                $stmt = $conn->prepare("UPDATE tbl_rooms SET room_status_id = 1 WHERE roomnumber_id = :room_id");
                $stmt->execute([':room_id' => $room['roomnumber_id']]);
            }

            $conn->commit();
            return json_encode(['status' => 'success', 'message' => 'Walk-in booking inserted successfully.']);
        } catch (Exception $e) {
            $conn->rollBack();
            return json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    // Online
    function customerBookingReqs()
    {
        include 'connection.php';

        // Only show bookings whose latest status is "Pending"
        $sql = "SELECT 
                    b.reference_no,
                    b.booking_id,
                    c.customers_id,
                    CONCAT(c.customers_fname, ' ', c.customers_lname) AS customer_name,
                    b.guests_amnt,
                    b.booking_downpayment,
                    b.booking_checkin_dateandtime,
                    b.booking_checkout_dateandtime,
                    b.booking_created_at,
                    GROUP_CONCAT(br.roomtype_id ORDER BY br.booking_room_id ASC) AS roomtype_ids,
                    GROUP_CONCAT(rt.roomtype_name ORDER BY br.booking_room_id ASC) AS roomtype_names,
                    GROUP_CONCAT(br.roomnumber_id ORDER BY br.booking_room_id ASC) AS roomnumber_ids,
                    COALESCE(bs.booking_status_name, 'Pending') AS booking_status
                FROM tbl_booking b
                JOIN tbl_customers c ON b.customers_id = c.customers_id
                JOIN tbl_customers_online co ON c.customers_online_id = co.customers_online_id
                JOIN tbl_booking_room br ON br.booking_id = b.booking_id
                JOIN tbl_roomtype rt ON br.roomtype_id = rt.roomtype_id
                LEFT JOIN (
                    SELECT bh.booking_id, bs.booking_status_name
                    FROM tbl_booking_history bh
                    INNER JOIN tbl_booking_status bs ON bh.status_id = bs.booking_status_id
                    WHERE bh.status_book_id IN (
                        SELECT MAX(status_book_id)
                        FROM tbl_booking_history
                        GROUP BY booking_id
                    )
                ) bs ON bs.booking_id = b.booking_id
                WHERE COALESCE(bs.booking_status_name, 'Pending') = 'Pending'
                GROUP BY b.booking_id
                ORDER BY b.booking_created_at DESC";

        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($requests as &$req) {
            $roomTypeIds = isset($req['roomtype_ids']) ? explode(',', $req['roomtype_ids']) : [];
            $roomTypeNames = isset($req['roomtype_names']) ? explode(',', $req['roomtype_names']) : [];
            $roomNumberIds = isset($req['roomnumber_ids']) ? explode(',', $req['roomnumber_ids']) : [];

            $rooms = [];
            for ($i = 0; $i < count($roomTypeIds); $i++) {
                $roomnumber_id = $roomNumberIds[$i] ?? null;
                $status_name = 'Pending';

                if ($roomnumber_id) {
                    // Fetch the room status from tbl_rooms
                    $roomStmt = $conn->prepare("SELECT st.status_name FROM tbl_rooms r INNER JOIN tbl_status_types st ON r.room_status_id = st.status_id WHERE r.roomnumber_id = :roomnumber_id");
                    $roomStmt->bindParam(':roomnumber_id', $roomnumber_id);
                    $roomStmt->execute();
                    $roomStatus = $roomStmt->fetchColumn();
                    if ($roomStatus) {
                        $status_name = $roomStatus;
                    }
                }

                $rooms[] = [
                    'roomnumber_id' => $roomnumber_id,
                    'roomtype_id'   => $roomTypeIds[$i],
                    'roomtype_name' => $roomTypeNames[$i],
                    'status_name'   => $status_name
                ];
            }

            unset($req['roomtype_ids'], $req['roomtype_names'], $req['roomnumber_ids']);
            $req['rooms'] = $rooms;
        }

        echo json_encode($requests);
    }

    function approveCustomerBooking($data)
    {
        include 'connection.php';

        $bookingId = $data['booking_id'];
        $roomIds   = $data['room_ids']; // array of room IDs
        $adminId   = $data['admin_id']; // placeholder

        try {
            $conn->beginTransaction();

            // 1️⃣ Get "Occupied" status_id for rooms
            $sqlStatus = "SELECT status_id FROM tbl_status_types WHERE status_name = 'Occupied' LIMIT 1";
            $statusId = $conn->query($sqlStatus)->fetchColumn();
            if (!$statusId) {
                throw new Exception("Status 'Occupied' not found.");
            }

            // 2️⃣ Insert into tbl_booking_room
            $sqlInsertBookingRoom = "INSERT IGNORE INTO tbl_booking_room (booking_id, roomnumber_id, roomtype_id)
                                     SELECT :booking_id, r.roomnumber_id, r.roomtype_id
                                     FROM tbl_rooms r
                                     WHERE r.roomnumber_id = :room_id";
            $stmtInsert = $conn->prepare($sqlInsertBookingRoom);
            foreach ($roomIds as $roomId) {
                $stmtInsert->execute([
                    ':booking_id' => $bookingId,
                    ':room_id'    => $roomId
                ]);
            }

            // 3️⃣ Update room statuses to Occupied
            $sqlUpdateRoom = "UPDATE tbl_rooms SET room_status_id = :status_id WHERE roomnumber_id = :room_id";
            $stmtUpdate = $conn->prepare($sqlUpdateRoom);
            foreach ($roomIds as $roomId) {
                $stmtUpdate->execute([
                    ':status_id' => $statusId,
                    ':room_id'   => $roomId
                ]);
            }

            // 4️⃣ Insert booking history (Approved = 2)
            $sqlHistory = "INSERT INTO tbl_booking_history (booking_id, employee_id, status_id, updated_at)
                           VALUES (:booking_id, :admin_id, 2, NOW())";
            $stmtHistory = $conn->prepare($sqlHistory);
            $stmtHistory->execute([
                ':booking_id' => $bookingId,
                ':admin_id'   => $adminId
            ]);

            $conn->commit();
            echo json_encode(["success" => true, "message" => "Booking approved successfully."]);
        } catch (Exception $e) {
            $conn->rollBack();
            echo json_encode(["success" => false, "message" => $e->getMessage()]);
        }
    }

    function declineCustomerBooking($data)
    {
        include 'connection.php';

        $bookingId = $data['booking_id'];
        $roomIds   = $data['room_ids']; // array of room IDs
        $adminId   = $data['admin_id']; // placeholder

        try {
            $conn->beginTransaction();

            // 1️⃣ Get "Vacant" status_id
            $sqlStatus = "SELECT status_id FROM tbl_status_types WHERE status_name = 'Vacant' LIMIT 1";
            $statusId = $conn->query($sqlStatus)->fetchColumn();
            if (!$statusId) {
                throw new Exception("Status 'Vacant' not found.");
            }

            // 2️⃣ Update room statuses back to Vacant
            $sqlUpdateRoom = "UPDATE tbl_rooms SET room_status_id = :status_id WHERE roomnumber_id = :room_id";
            $stmtUpdate = $conn->prepare($sqlUpdateRoom);
            foreach ($roomIds as $roomId) {
                $stmtUpdate->execute([
                    ':status_id' => $statusId,
                    ':room_id'   => $roomId
                ]);
            }

            // 3️⃣ Remove booking-room associations
            // $sqlDeleteBookingRoom = "DELETE FROM tbl_booking_room WHERE booking_id = :booking_id";
            // $stmtDelete = $conn->prepare($sqlDeleteBookingRoom);
            // $stmtDelete->execute([':booking_id' => $bookingId]);

            // 4️⃣ Insert booking history (Declined = 3)
            $sqlHistory = "INSERT INTO tbl_booking_history (booking_id, employee_id, status_id, updated_at)
                           VALUES (:booking_id, :admin_id, 3, NOW())";
            $stmtHistory = $conn->prepare($sqlHistory);
            $stmtHistory->execute([
                ':booking_id' => $bookingId,
                ':admin_id'   => $adminId
            ]);

            // 5️⃣ Mark booking as archived
            // $sqlArchive = "UPDATE tbl_booking SET booking_isArchive = 1 WHERE booking_id = :booking_id";
            // $stmtArchive = $conn->prepare($sqlArchive);
            // $stmtArchive->execute([':booking_id' => $bookingId]);

            $conn->commit();
            echo json_encode(["success" => true, "message" => "Booking declined successfully."]);
        } catch (Exception $e) {
            $conn->rollBack();
            echo json_encode(["success" => false, "message" => $e->getMessage()]);
        }
    }


    // This is for Customer Side, just putting here to test
    function countAvailableRooms()
    {
        include "connection.php";

        $sql = "SELECT 
                    rt.roomtype_name,
                    COALESCE(rooms.total_rooms, 0) AS total_rooms,
                    COALESCE(req.total_requested, 0) AS total_requested,
                    (COALESCE(rooms.total_rooms, 0) - COALESCE(req.total_requested, 0)) AS available_rooms
                FROM tbl_roomtype rt
                LEFT JOIN (
                    SELECT roomtype_id, COUNT(*) AS total_rooms
                    FROM tbl_rooms
                    GROUP BY roomtype_id
                ) rooms ON rooms.roomtype_id = rt.roomtype_id
                LEFT JOIN (
                    SELECT br.roomtype_id, COUNT(*) AS total_requested
                    FROM tbl_booking_room br
                    JOIN tbl_booking b ON b.booking_id = br.booking_id
                    WHERE b.booking_isArchive = 0
                    GROUP BY br.roomtype_id
                ) req ON req.roomtype_id = rt.roomtype_id
                ORDER BY rt.roomtype_name;";

        try {
            $stmt = $conn->prepare($sql);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as &$row) {
                $row['total_rooms'] = (int)$row['total_rooms'];
                $row['total_requested'] = (int)$row['total_requested'];
                $row['available_rooms'] = (int)$row['available_rooms'];
            }

            return json_encode([
                "success" => true,
                "data" => $rows
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (PDOException $e) {
            return json_encode([
                "success" => false,
                "message" => $e->getMessage()
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }


    // ------------------------------------------------------- Payment Functions ------------------------------------------------------- //
    function getPaymentMethods()
    {
        include 'connection.php'; // This should define $pdo

        $sql = "SELECT * FROM tbl_payment_method";
        $stmt = $conn->prepare($sql);
        $stmt->execute(); // You must execute before fetching
        $methods = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode($methods);
    }


    function createInvoice($json)
    {
        include "connection.php";
        $json = json_decode($json, true);
        $billing_ids = $json["billing_ids"];
        $employee_id = $json["employee_id"];
        $payment_method_id = $json["payment_method_id"];
        $invoice_status_id = $json["invoice_status_id"] ?? 1;

        $invoice_date = date("Y-m-d");
        $invoice_time = date("H:i:s");

        foreach ($billing_ids as $billing_id) {
            // 1. Get the booking_id linked to this billing_id
            $stmt = $conn->prepare("SELECT booking_id FROM tbl_billing WHERE billing_id = :billing_id");
            $stmt->bindParam(':billing_id', $billing_id);
            $stmt->execute();
            $billingRow = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$billingRow) continue;

            $booking_id = $billingRow["booking_id"];

            // 2. Calculate room charges
            $roomQuery = $conn->prepare("
            SELECT SUM(c.room_price) AS room_total
            FROM tbl_booking_room b
            JOIN tbl_rooms c ON b.roomnumber_id = c.room_id
            WHERE b.booking_id = :booking_id
        ");
            $roomQuery->bindParam(':booking_id', $booking_id);
            $roomQuery->execute();
            $room_total = $roomQuery->fetchColumn() ?: 0;

            // 3. Calculate additional/other charges
            $chargesQuery = $conn->prepare("
            SELECT SUM(d.booking_charges_price * d.booking_charges_quantity) AS charge_total
            FROM tbl_booking_charges d
            JOIN tbl_booking_room b ON d.booking_room_id = b.booking_room_id
            WHERE b.booking_id = :booking_id
        ");
            $chargesQuery->bindParam(':booking_id', $booking_id);
            $chargesQuery->execute();
            $charge_total = $chargesQuery->fetchColumn() ?: 0;

            // 4. Final total = rooms + other charges
            $final_total = $room_total + $charge_total;

            // 5. Update billing total and balance
            $updateBilling = $conn->prepare("
            UPDATE tbl_billing 
            SET billing_total_amount = :total, billing_balance = :total
            WHERE billing_id = :billing_id
        ");
            $updateBilling->bindParam(':total', $final_total);
            $updateBilling->bindParam(':billing_id', $billing_id);
            $updateBilling->execute();

            // 6. Create invoice
            $insert = $conn->prepare("
            INSERT INTO tbl_invoice (
                billing_id, employee_id, payment_method_id,
                invoice_date, invoice_time, invoice_total_amount, invoice_status_id
            ) VALUES (
                :billing_id, :employee_id, :payment_method_id,
                :invoice_date, :invoice_time, :invoice_total_amount, :invoice_status_id
            )
        ");

            $insert->bindParam(':billing_id', $billing_id);
            $insert->bindParam(':employee_id', $employee_id);
            $insert->bindParam(':payment_method_id', $payment_method_id);
            $insert->bindParam(':invoice_date', $invoice_date);
            $insert->bindParam(':invoice_time', $invoice_time);
            $insert->bindParam(':invoice_total_amount', $final_total);
            $insert->bindParam(':invoice_status_id', $invoice_status_id);
            $insert->execute();

            // 7. Optionally zero out billing balance (if paid in full)
            $conn->prepare("UPDATE tbl_billing SET billing_balance = 0 WHERE billing_id = :billing_id")
                ->execute([':billing_id' => $billing_id]);
        }

        echo json_encode(["success" => true, "message" => "Invoices created with updated charges."]);
    }

    function getVacantRoomsByBooking($json)
    {
        include "connection.php";
        $json = json_decode($json, true);
        $reference_no = $json['reference_no'] ?? null;

        if (!$reference_no) {
            echo json_encode(['error' => 'Missing reference_no']);
            return;
        }

        // Step 1: Get booking ID
        $stmt = $conn->prepare("SELECT booking_id FROM tbl_booking WHERE reference_no = :ref");
        $stmt->bindParam(':ref', $reference_no);
        $stmt->execute();
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$booking) {
            echo json_encode(['error' => 'Booking not found']);
            return;
        }

        $booking_id = $booking['booking_id'];

        // Step 2: Get roomtype(s) and count(s)
        $stmt = $conn->prepare("SELECT br.roomtype_id, rt.roomtype_name, COUNT(*) AS room_count
        FROM tbl_booking_room br
        JOIN tbl_roomtype rt ON rt.roomtype_id = br.roomtype_id
        WHERE br.booking_id = :booking_id
        GROUP BY br.roomtype_id");
        $stmt->bindParam(':booking_id', $booking_id);
        $stmt->execute();
        $roomGroups = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Step 3: Get all available rooms
        $data = [];
        foreach ($roomGroups as $group) {
            $stmt = $conn->prepare("SELECT r.roomnumber_id, r.roomfloor
            FROM tbl_rooms r
            WHERE r.roomtype_id = :roomtype_id AND r.room_status_id = 3");
            $stmt->bindParam(':roomtype_id', $group['roomtype_id']);
            $stmt->execute();
            $vacant_rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $data[] = [
                'roomtype_id' => $group['roomtype_id'],
                'roomtype_name' => $group['roomtype_name'],
                'room_count' => $group['room_count'],
                'vacant_rooms' => $vacant_rooms
            ];
        }

        echo json_encode($data);
    }

    function finalizeBookingApproval($json)
    {
        include "connection.php";
        $json = json_decode($json, true);
        $reference_no = $json['reference_no'] ?? '';
        $selected_room_ids = $json['assigned_rooms'] ?? [];

        if (!$reference_no || empty($selected_room_ids)) {
            echo 'invalid';
            return;
        }

        $stmt = $conn->prepare("SELECT booking_id FROM tbl_booking WHERE reference_no = :ref");
        $stmt->bindParam(':ref', $reference_no);
        $stmt->execute();
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$booking) {
            echo 'not_found';
            return;
        }

        $booking_id = $booking['booking_id'];
        $employee_id = 1; // Replace with session

        // Assign rooms to booking_room
        foreach ($selected_room_ids as $room_id) {
            $stmt = $conn->prepare("
            UPDATE tbl_booking_room 
            SET roomnumber_id = :room_id 
            WHERE booking_id = :booking_id AND roomnumber_id IS NULL 
            LIMIT 1
        ");
            $stmt->bindParam(':room_id', $room_id);
            $stmt->bindParam(':booking_id', $booking_id);
            $stmt->execute();

            // Update room status to occupied (1)
            $stmt = $conn->prepare("UPDATE tbl_rooms SET room_status_id = 1 WHERE roomnumber_id = :room_id");
            $stmt->bindParam(':room_id', $room_id);
            $stmt->execute();
        }

        // Insert into history
        $stmt = $conn->prepare("INSERT INTO tbl_booking_history (booking_id, employee_id, status_id, updated_at) VALUES (:id, :emp, 2, NOW())");
        $stmt->bindParam(':id', $booking_id);
        $stmt->bindParam(':emp', $employee_id);
        $result = $stmt->execute();

        echo $result ? 'success' : 'fail';
    }

    function getBookingsWithBillingStatus()
    {
        include "connection.php";

        $query = "SELECT 
            b.booking_id,
            b.reference_no,
            b.booking_checkin_dateandtime,
            b.booking_checkout_dateandtime,
            CONCAT(c.customers_fname, ' ', c.customers_lname) AS customer_name,
            bi.billing_id,
            i.invoice_id,
            i.invoice_status_id
        FROM tbl_booking b
        LEFT JOIN tbl_customers c ON b.customers_id = c.customers_id
        LEFT JOIN tbl_billing bi ON b.booking_id = bi.booking_id
        LEFT JOIN tbl_invoice i ON bi.billing_id = i.billing_id
        ORDER BY b.booking_created_at DESC";

        $stmt = $conn->prepare($query);
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode($results);
    }

    function getBookingInvoice($data)
    {
        include "connection.php";


        // Get invoice details
        $query = "SELECT 
            a.booking_id,
            a.reference_no,
            CONCAT(b.customers_fname, ' ', b.customers_lname) AS customer_name,
            c.billing_id,
            c.billing_total_amount,
            c.billing_balance,
            c.billing_downpayment,
            d.invoice_id,
            d.invoice_date,
            d.invoice_time,
            d.invoice_total_amount,
            e.payment_method_name,
            f.employee_fname
        FROM tbl_booking a
        LEFT JOIN tbl_customers b ON a.customers_id = b.customers_id
        LEFT JOIN tbl_billing c ON a.booking_id = c.booking_id
        LEFT JOIN tbl_invoice d ON c.billing_id = d.billing_id
        LEFT JOIN tbl_payment_method e ON d.payment_method_id = e.payment_method_id
        LEFT JOIN tbl_employee f ON d.employee_id = f.employee_id
        WHERE a.booking_id = :booking_id
        LIMIT 1";

        $stmt = $conn->prepare($query);
        $stmt->bindParam(":booking_id", $data["booking_id"], PDO::PARAM_INT);
        $stmt->execute();
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($invoice) {
            return json_encode($invoice);
        } else {
            return json_encode(["error" => "Invoice not found."]);
        }
    }

    function getInvoicesData()
    {
        include "connection.php"; // Assumes $conn is your PDO connection

        $sql = "SELECT a.invoice_id, a.invoice_date, a.invoice_total_amount AS total_invoice, 
                b.billing_total_amount AS total_billing FROM tbl_invoice a
                INNER JOIN tbl_billing b ON a.billing_id = b.billing_id
                WHERE invoice_status_id = 1";
        try {
            $stmt = $conn->prepare($sql);

            $stmt->execute();
            $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return count($result) > 0 ? json_encode($result) : 0;
        } catch (PDOException $e) {
            return json_encode(["error" => $e->getMessage()]);
        }
    }

    function getAllCustomersRooms($data)
    {
        include 'connection.php';

        $sql = "SELECT a.*, c.roomtype_name
            FROM tbl_booking_room a
            INNER JOIN tbl_booking b ON a.booking_id = b.booking_id
            INNER JOIN tbl_roomtype c ON a.roomtype_id = c.roomtype_id
            WHERE a.booking_id = :booking_id AND a.roomnumber_id IS NOT NULL";

        try {
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(':booking_id', $data['booking_id']);
            $stmt->execute();

            $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode($rooms);
        } catch (PDOException $e) {
            echo json_encode([
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    function getCustomerBills($data)
    {
        include 'connection.php';

        $sql = "SELECT 
                a.billing_id, 
                f.roomnumber_id, 
                e.charges_master_name AS item_name, 
                e.charges_master_price AS item_price,
                c.booking_charges_quantity AS item_amount
            FROM tbl_billing a
            INNER JOIN tbl_booking b ON a.booking_id = b.booking_id
            INNER JOIN tbl_booking_charges c ON a.booking_charges_id = c.booking_charges_id
            INNER JOIN tbl_booking_room d ON c.booking_room_id = d.booking_room_id
            INNER JOIN tbl_charges_master e ON c.charges_master_id = e.charges_master_id
            INNER JOIN tbl_rooms f ON d.roomnumber_id = f.roomnumber_id
            WHERE b.booking_id = :booking_id";

        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':booking_id', $data['booking_id']);
        $stmt->execute();

        $result = $stmt->fetchAll(PDO::FETCH_ASSOC); // this is the PDO version of get_result()

        return json_encode($result);
    }

    function addNewCustomerCharges($data)
    {
        include 'connection.php';

        $room_id = $data['room']['id'];            // booking_room_id
        $charge_id = $data['charge']['id'];        // charges_master_id
        $charge_price = $data['total_price'];      // booking_charges_price
        $quantity = $data['quantity'];             // booking_charges_quantity
        $booking_id = $data['booking_id'];         // booking_id
        // $employee_id = $data['employee_id'];       // employee_id
        $payment_method_id = $data['payment_method_id']; // payment_method_id

        try {
            // Start transaction
            $conn->beginTransaction();

            // Step 1: Insert into tbl_booking_charges
            $sql1 = "INSERT INTO tbl_booking_charges (
                    charges_master_id,
                    booking_room_id,
                    booking_charges_price,
                    booking_charges_quantity
                ) VALUES (
                    :charge_id,
                    :room_id,
                    :charge_price,
                    :quantity
                )";

            $stmt1 = $conn->prepare($sql1);
            $stmt1->execute([
                ':charge_id'    => $charge_id,
                ':room_id'      => $room_id,
                ':charge_price' => $charge_price,
                ':quantity'     => $quantity
            ]);

            $booking_charges_id = $conn->lastInsertId();

            // Step 2: Insert into tbl_billing
            $sql2 = "INSERT INTO tbl_billing (
                    booking_id,
                    booking_charges_id,
                    employee_id,
                    payment_method_id,
                    billing_dateandtime,
                    billing_invoice_number,
                    billing_downpayment,
                    billing_vat,
                    billing_total_amount,
                    billing_balance
                ) VALUES (
                    :booking_id,
                    :booking_charges_id,
                    :employee_id,
                    :payment_method_id,
                    NOW(),
                    :invoice_number,
                    :downpayment,
                    :vat,
                    :total_amount,
                    :balance
                )";

            $stmt2 = $conn->prepare($sql2);
            $stmt2->execute([
                ':booking_id'          => $booking_id,
                ':booking_charges_id'  => $booking_charges_id,
                ':employee_id'         => 1,
                ':payment_method_id'   => $payment_method_id,
                ':invoice_number'      => "0001", // or generate dynamically
                ':downpayment'         => $charge_price, // can be customized
                ':vat'                 => 12, // static or calculated
                ':total_amount'        => $charge_price,
                ':balance'             => 0
            ]);

            // Commit
            $conn->commit();

            echo json_encode([
                "status" => "success",
                "message" => "Booking charge and billing added successfully."
            ]);
        } catch (PDOException $e) {
            $conn->rollBack();
            echo json_encode([
                "status" => "error",
                "message" => $e->getMessage()
            ]);
        }
    }

    function paymentCustomerBills($data) {}

    // ------------------------------------------------------- Master File Functions ------------------------------------------------------- //
    // ----- Amenity Master ----- //
    function view_Amenities()
    {
        include "connection.php";

        $sql = "SELECT * FROM tbl_room_amenities_master";

        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $rowCount = $stmt->rowCount();
        unset($stmt, $conn);

        return $rowCount > 0 ? json_encode($result) : 0;
    }

    function add_NewAmenity($data)
    {
        include "connection.php";

        $sql = "INSERT INTO tbl_room_amenities_master (room_amenities_master_name)
        VALUES (:amenityName)";

        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":amenityName", $data["amenity_name"]);
        $stmt->execute();

        $rowCount = $stmt->rowCount();
        unset($stmt, $conn);

        return $rowCount > 0 ? 1 : 0;
    }

    function update_CurrAmenities($data)
    {
        include "connection.php";

        $sql = "UPDATE tbl_room_amenities_master SET room_amenities_master_name=:amenityName 
        WHERE room_amenities_master_id=:amenityID";
        $stmt = $conn->prepare($sql);

        $stmt->bindParam(":amenityID", $data["amenity_id"]);
        $stmt->bindParam(":amenityName", $data["amenity_name"]);
        $stmt->execute();

        $rowCount = $stmt->rowCount();
        unset($stmt, $conn);

        return $rowCount > 0 ? 1 : 0;
    }

    function remove_Amenitiy($data)
    {
        include "connection.php";

        $sql = "SELECT * FROM tbl_room_amenities_master ";

        $rowCount = $stmt->rowCount();
        unset($stmt, $conn);

        return $rowCount > 0 ? 1 : 0;
    }

    // ----- Charges Master ----- //
    function view_AllCharges()
    {
        include "connection.php";

        $sql = "SELECT * FROM tbl_charges_master";

        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $rowCount = $stmt->rowCount();
        unset($stmt, $conn);

        return $rowCount > 0 ? json_encode($result) : 0;
    }

    function add_NewCharges($data)
    {
        include "connection.php";

        $sql = "INSERT INTO tbl_charges_master (charges_category_id, charges_master_name, charges_master_price)
        VALUES (:categoryID, :chargeName, :chargePrice)";

        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":categoryID", $data["charge_category"]);
        $stmt->bindParam(":chargeName", $data["charge_name"]);
        $stmt->bindParam(":chargePrice", $data["charge_price"]);
        $stmt->execute();

        $rowCount = $stmt->rowCount();
        unset($stmt, $conn);

        return $rowCount > 0 ? 1 : 0;
    }

    function update_CurrCharges($data)
    {
        include "connection.php";

        $sql = "UPDATE tbl_charges_master 
        SET 'charges_category_id' = :categoryID, 'charges_master_name' = :chargeName, 'charges_master_price' = :chargePrice
        WHERE room_amenities_master_id = :amenityID";

        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":categoryID", $data["charge_category"]);
        $stmt->bindParam(":chargeName", $data["charge_name"]);
        $stmt->bindParam(":chargePrice", $data["charge_price"]);
        $stmt->execute();

        $rowCount = $stmt->rowCount();
        unset($stmt, $conn);

        return $rowCount > 0 ? 1 : 0;
    }

    function remove_Charges($data)
    {
        include "connection.php";

        $sql = "SELECT * FROM tbl_room_amenities_master ";

        $rowCount = $stmt->rowCount();
        unset($stmt, $conn);

        return $rowCount > 0 ? 1 : 0;
    }

    // ----- Charges Category Master ----- //
    function view_AllChargeCategory()
    {
        include "connection.php";

        $sql = "SELECT * FROM tbl_charges_category";

        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $rowCount = $stmt->rowCount();
        unset($stmt, $conn);

        return $rowCount > 0 ? json_encode($result) : 0;
    }

    function add_NewChargeCategory($data)
    {
        include "connection.php";

        $sql = "INSERT INTO tbl_charges_category (charges_category_name)
        VALUES (:chargeCategoryName)";

        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":chargeCategoryName", $data["charge_category_name"]);
        $stmt->execute();

        $rowCount = $stmt->rowCount();
        unset($stmt, $conn);

        return $rowCount > 0 ? 1 : 0;
    }

    function update_CurrChargeCategory($data)
    {
        include "connection.php";

        $sql = "UPDATE tbl_charges_category 
        SET 'charges_category_name' = :chargeCategoryName
        WHERE charges_category_id = :chargeCategoryID";

        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":chargeCategoryName", $data["charge_category_name"]);
        $stmt->execute();

        $rowCount = $stmt->rowCount();
        unset($stmt, $conn);

        return $rowCount > 0 ? 1 : 0;
    }

    function remove_ChargeCategory($data)
    {
        include "connection.php";

        $sql = "SELECT * FROM tbl_room_amenities_master ";

        $rowCount = $stmt->rowCount();
        unset($stmt, $conn);

        return $rowCount > 0 ? 1 : 0;
    }

    // ----- Discount Master ----- //
    function view_AllDiscounts()
    {
        include "connection.php";

        $sql = "SELECT * FROM tbl_discounts";

        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $rowCount = $stmt->rowCount();
        unset($stmt, $conn);

        return $rowCount > 0 ? json_encode($result) : 0;
    }

    function add_NewDiscounts($data)
    {
        include "connection.php";

        $sql = "INSERT INTO tbl_discounts (discounts_type, discounts_datestart, discounts_dateend, discounts_percent)
        VALUES (:discountType, :discountDateStart, :discountDateEnd, :discountPercent)";

        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":discountType", $data["discount_type"]);
        $stmt->bindParam(":discountDateStart", $data["discount_date_start"]);
        $stmt->bindParam(":discountDateEnd", $data["discount_date_end"]);
        $stmt->bindParam(":discountPercent", $data["discount_percent"]);
        $stmt->execute();

        $rowCount = $stmt->rowCount();
        unset($stmt, $conn);

        return $rowCount > 0 ? 1 : 0;
    }

    function update_CurrDiscounts($data)
    {
        include "connection.php";

        $sql = "UPDATE tbl_discounts 
        SET 'discounts_type' = :discountType, 'discounts_datestart' = :discountDateStart, 
            'discounts_dateend' = :discountDateEnd, 'discounts_percent' = :discountPercent
        WHERE discounts_id = :discountID";

        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":discountID", $data["discount_id"]);
        $stmt->bindParam(":discountType", $data["discount_type"]);
        $stmt->bindParam(":discountDateStart", $data["discount_date_start"]);
        $stmt->bindParam(":discountDateEnd", $data["discount_date_end"]);
        $stmt->bindParam(":discountPercent", $data["discount_percent"]);
        $stmt->execute();

        $rowCount = $stmt->rowCount();
        unset($stmt, $conn);

        return $rowCount > 0 ? 1 : 0;
    }

    function remove_Discounts($data)
    {
        include "connection.php";

        $sql = "SELECT * FROM tbl_room_amenities_master ";

        $rowCount = $stmt->rowCount();
        unset($stmt, $conn);

        return $rowCount > 0 ? 1 : 0;
    }

    // ----- Room Type Master ----- //
    function view_AllRoomTypes()
    {
        include "connection.php";

        $sql = "SELECT * FROM tbl_roomtype";

        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $rowCount = $stmt->rowCount();
        unset($stmt, $conn);

        return $rowCount > 0 ? json_encode($result) : 0;
    }

    function add_NewRoomTypes($data)
    {
        include "connection.php";

        $sql = "INSERT INTO tbl_roomtype (roomtype_name, roomtype_description, roomtype_price)
        VALUES (:roomTypeName, :discountDateStart, :discountDateEnd, :discountPercent)";

        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":discountType", $data["discount_type"]);
        $stmt->bindParam(":discountDateStart", $data["discount_date_start"]);
        $stmt->bindParam(":discountDateEnd", $data["discount_date_end"]);
        $stmt->bindParam(":discountPercent", $data["discount_percent"]);
        $stmt->execute();

        $rowCount = $stmt->rowCount();
        unset($stmt, $conn);

        return $rowCount > 0 ? 1 : 0;
    }

    function update_CurrRoomTypes($data)
    {
        include "connection.php";

        $sql = "UPDATE  tbl_roomtype 
        SET 'discounts_type' = :discountType, 'discounts_datestart' = :discountDateStart, 
            'discounts_dateend' = :discountDateEnd, 'discounts_percent' = :discountPercent
        WHERE discounts_id = :discountID";

        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":discountID", $data["discount_id"]);
        $stmt->bindParam(":discountType", $data["discount_type"]);
        $stmt->bindParam(":discountDateStart", $data["discount_date_start"]);
        $stmt->bindParam(":discountDateEnd", $data["discount_date_end"]);
        $stmt->bindParam(":discountPercent", $data["discount_percent"]);
        $stmt->execute();

        $rowCount = $stmt->rowCount();
        unset($stmt, $conn);

        return $rowCount > 0 ? 1 : 0;
    }

    function remove_RoomTypes($data)
    {
        include "connection.php";

        $sql = "SELECT * FROM  tbl_roomtype";

        $rowCount = $stmt->rowCount();
        unset($stmt, $conn);

        return $rowCount > 0 ? 1 : 0;
    }
}


$AdminClass = new Admin_Functions();

$methodType = isset($_POST["method"]) ? $_POST["method"] : 0;
$jsonData = isset($_POST["json"]) ? json_decode($_POST["json"], true) : 0;


switch ($methodType) {

    // --------------------------------- For Dashboard --------------------------------- //
    case "getInvoiceDatas":
        echo $AdminClass->getInvoicesData();
        break;

    // --------------------------------- Approving Customer Bookings --------------------------------- //

    // View All Bookings
    case "viewBookings":
        echo $AdminClass->viewBookingList();
        break;

    // WalkIn
    case "finalizeBooking":
        echo $AdminClass->insertWalkInBooking($jsonData);
        break;

    // Online
    case "reqBookingList":
        $AdminClass->customerBookingReqs();
        break;

    case "approveCustomerBooking":
        $AdminClass->approveCustomerBooking($jsonData);
        break;

    case "declineCustomerBooking":
        $AdminClass->declineCustomerBooking($jsonData);
        break;

    // --------------------------------- For Billings and Invoices --------------------------------- //
    case "getBookingsWithBillingStatus":
        echo $AdminClass->getBookingsWithBillingStatus();
        break;

    case "getAllPayMethods":
        echo $AdminClass->getPaymentMethods();
        break;

    case 'finalizeBookingApproval':
        $transactions->finalizeBookingApproval($jsonData);
        break;

    case "getCustomerInvoice":
        echo $AdminClass->getBookingInvoice($jsonData);
        break;

    case "getCustomerBilling":
        echo $AdminClass->getCustomerBills($jsonData);
        break;

    case "requestCustomerRooms":
        echo $AdminClass->getAllCustomersRooms($jsonData);
        break;

    case "addCustomerCharges":
        echo $AdminClass->addNewCustomerCharges($jsonData);
        break;

    // --------------------------------- For Viewing Data or Login --------------------------------- //
    case "login":
        echo $AdminClass->admin_login($jsonData);
        break;

    case "viewCustomers":
        echo json_encode(["message" => "Successfully Retrieved Data"]);
        break;

    case "changeStatus":
        echo $AdminClass->change_bookStatus($jsonData);
        break;

    // THis should reflect to customer booking page
    case "countAvailableRooms":
        echo $AdminClass->countAvailableRooms();
        break;

    case "reqAvailRooms":
        echo $AdminClass->getAvailableRooms();
        break;

    case "viewNationalities":
        echo $AdminClass->getAllNationalities();
        break;

    // Room Management or Something?
    case "view_rooms":
        echo $AdminClass->viewAvailRooms();
        break;

    case "getAllStatus":
        echo $AdminClass->getAllStatus();
        break;

    // --------------------------------- Master Files Manager --------------------------------- //

    // -------- -FM Amenities -------- //
    case "view_amenities":
        echo $AdminClass->view_Amenities();
        break;

    case "add_amenities":
        echo $AdminClass->add_NewAmenity($jsonData);
        break;

    case "update_amenities":
        echo $AdminClass->update_CurrAmenities($jsonData);
        break;

    case "delete_amenities":
        echo $AdminClass->remove_Amenitiy($jsonData);
        break;


    // -------- -FM Charges -------- //
    case "view_charges":
        echo $AdminClass->view_AllCharges();
        break;

    case "add_charges":
        echo $AdminClass->add_NewCharges($jsonData);
        break;

    case "update_charges":
        echo $AdminClass->update_CurrAmenities($jsonData);
        break;

    case "delete_charges":
        echo $AdminClass->remove_Charges($jsonData);
        break;

    // -------- -FM Charge Categories -------- //
    case "view_charge_category":
        echo $AdminClass->view_AllChargeCategory();
        break;

    case "add_charge_category":
        echo $AdminClass->add_NewChargeCategory($jsonData);
        break;

    case "update_charge_category":
        echo $AdminClass->update_CurrChargeCategory($jsonData);
        break;

    case "delete_charge_category":
        echo $AdminClass->remove_ChargeCategory($jsonData);
        break;


    // -------- -FM Discounts -------- //
    case "view_discount":
        echo $AdminClass->view_AllDiscounts();
        break;

    case "add_discount":
        echo $AdminClass->add_NewDiscounts($jsonData);
        break;

    case "update_discount":
        echo $AdminClass->update_CurrDiscounts($jsonData);
        break;

    case "delete_discount":
        echo $AdminClass->remove_Discounts($jsonData);
        break;


    // -------- -FM Room Types -------- //
    case "view_room_types":
        echo $AdminClass->view_AllRoomTypes();
        break;

    case "add_room_types":
        echo $AdminClass->add_NewRoomTypes($jsonData);
        break;

    case "update_room_types":
        echo $AdminClass->update_CurrRoomTypes($jsonData);
        break;

    case "delete_room_types":
        echo $AdminClass->remove_RoomTypes($jsonData);
        break;
}


// Needs fixing/update
// 1. approveCustomerBooking and declineCustomerBooking need to upgrade their way of calling status
// - Situation: PK of each status might get switched up
//bea gwapa so much