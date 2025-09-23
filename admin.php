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

    function viewAllRooms()
    {
        include "connection.php";

        try {
            // First, get all rooms with their basic information
            $sql = "SELECT 
                        r.roomnumber_id,
                        r.roomfloor,
                        rt.roomtype_capacity,
                        rt.roomtype_beds,
                        rt.roomtype_sizes,
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
                    GROUP BY 
                        r.roomnumber_id,
                        r.roomfloor,
                        rt.roomtype_name,
                        rt.roomtype_description,
                        rt.roomtype_price,
                        rt.roomtype_capacity,
                        rt.roomtype_beds,
                        rt.roomtype_sizes,
                        st.status_name
                    ORDER BY r.roomnumber_id ASC";

            $stmt = $conn->prepare($sql);
            $stmt->execute();
            $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Now get booking information for each room
            $sql_bookings = "SELECT 
                                br.roomnumber_id,
                                DATE_FORMAT(b.booking_checkin_dateandtime, '%Y-%m-%d') AS checkin_date,
                                DATE_FORMAT(b.booking_checkout_dateandtime, '%Y-%m-%d') AS checkout_date,
                                CONCAT(
                                    DATE_FORMAT(b.booking_checkin_dateandtime, '%Y-%m-%d'), 
                                    ' to ', 
                                    DATE_FORMAT(b.booking_checkout_dateandtime, '%Y-%m-%d')
                                ) AS booking_period,
                                b.booking_id,
                                COALESCE(CONCAT(c.customers_fname, ' ', c.customers_lname),
                                         CONCAT(w.customers_walk_in_fname, ' ', w.customers_walk_in_lname)) AS customer_name
                            FROM tbl_booking_room br
                            JOIN tbl_booking b 
                                ON br.booking_id = b.booking_id 
                            LEFT JOIN tbl_customers c 
                                ON b.customers_id = c.customers_id
                            LEFT JOIN tbl_customers_walk_in w 
                                ON b.customers_walk_in_id = w.customers_walk_in_id
                            WHERE b.booking_isArchive = 0
                                AND b.booking_checkin_dateandtime >= CURDATE()
                            ORDER BY br.roomnumber_id, b.booking_checkin_dateandtime ASC";

            $stmt_bookings = $conn->prepare($sql_bookings);
            $stmt_bookings->execute();
            $bookings = $stmt_bookings->fetchAll(PDO::FETCH_ASSOC);

            // Group bookings by room number
            $bookings_by_room = [];
            foreach ($bookings as $booking) {
                $room_id = $booking['roomnumber_id'];
                if (!isset($bookings_by_room[$room_id])) {
                    $bookings_by_room[$room_id] = [];
                }
                $bookings_by_room[$room_id][] = [
                    'booking_id' => $booking['booking_id'],
                    'checkin_date' => $booking['checkin_date'],
                    'checkout_date' => $booking['checkout_date'],
                    'booking_period' => $booking['booking_period'],
                    'customer_name' => $booking['customer_name']
                ];
            }

            // Combine room information with booking data
            $result = [];
            foreach ($rooms as $room) {
                $room_id = $room['roomnumber_id'];
                $room_data = $room;

                // Add booking information for this room
                if (isset($bookings_by_room[$room_id])) {
                    $room_data['bookings'] = $bookings_by_room[$room_id];
                    $room_data['booking_dates'] = implode('; ', array_column($bookings_by_room[$room_id], 'booking_period'));
                } else {
                    $room_data['bookings'] = [];
                    $room_data['booking_dates'] = '';
                }

                $result[] = $room_data;
            }

            unset($stmt, $stmt_bookings, $conn);
            return json_encode($result);
        } catch (PDOException $e) {
            return json_encode(["error" => $e->getMessage()]);
        }
    }

    // ------------------------------------------------------- Booking Functions ------------------------------------------------------- //
    function viewBookingList()
    {
        include 'connection.php';

        try {
            $sql = "SELECT 
                        b.booking_id,
                        b.reference_no,
                        b.booking_checkin_dateandtime,
                        b.booking_checkout_dateandtime,
                        b.booking_created_at,
                        -- Customer core
                        COALESCE(CONCAT(c.customers_fname, ' ', c.customers_lname),
                                 CONCAT(w.customers_walk_in_fname, ' ', w.customers_walk_in_lname)) AS customer_name,
                        COALESCE(c.customers_email, w.customers_walk_in_email) AS customer_email,
                        COALESCE(c.customers_phone, w.customers_walk_in_phone) AS customer_phone,
                        n.nationality_name AS nationality,
                        -- Rooms
                        GROUP_CONCAT(br.roomnumber_id ORDER BY br.booking_room_id ASC) AS room_numbers,
                        -- Latest status
                        COALESCE(bs.booking_status_name, 'Pending') AS booking_status,
                        -- Amounts
                        COALESCE(bill.billing_total_amount, b.booking_totalAmount) AS total_amount,
                        COALESCE(bill.billing_downpayment, b.booking_downpayment) AS downpayment
                    FROM tbl_booking b
                    LEFT JOIN tbl_customers c 
                        ON b.customers_id = c.customers_id
                    LEFT JOIN tbl_nationality n 
                        ON c.nationality_id = n.nationality_id
                    LEFT JOIN tbl_customers_walk_in w 
                        ON b.customers_walk_in_id = w.customers_walk_in_id
                    LEFT JOIN tbl_booking_room br 
                        ON b.booking_id = br.booking_id
                    LEFT JOIN (
                        SELECT bh1.booking_id, bs.booking_status_name
                        FROM tbl_booking_history bh1
                        INNER JOIN (
                            SELECT booking_id, MAX(booking_history_id) AS latest_history_id
                            FROM tbl_booking_history
                            GROUP BY booking_id
                        ) last ON last.booking_id = bh1.booking_id AND last.latest_history_id = bh1.booking_history_id
                        INNER JOIN tbl_booking_status bs ON bh1.status_id = bs.booking_status_id
                    ) bs ON bs.booking_id = b.booking_id
                    LEFT JOIN (
                        SELECT bi.booking_id,
                               MAX(bi.billing_id) AS latest_billing_id
                        FROM tbl_billing bi
                        GROUP BY bi.booking_id
                    ) lb ON lb.booking_id = b.booking_id
                    LEFT JOIN tbl_billing bill 
                        ON bill.billing_id = lb.latest_billing_id
                    GROUP BY b.booking_id
                    ORDER BY b.booking_created_at DESC";

            $stmt = $conn->prepare($sql);
            $stmt->execute();
            $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

            unset($conn, $stmt);

            return !empty($result) ? json_encode($result) : json_encode([]);
        } catch (PDOException $e) {
            return json_encode(["error" => $e->getMessage()]);
        }
    }

    function changeBookingStatus($data)
    {
        include 'connection.php';

        try {
            $conn->beginTransaction();

            $book_id = intval($data["booking_id"]);
            $status_id = intval($data["booking_status_id"]);
            $employee_id = intval($data["employee_id"] ?? 1); // Default to admin if not provided
            $room_ids = $data["room_ids"] ?? []; // Array of room IDs to update

            // 1. Insert booking history record
            $stmt = $conn->prepare(
                "INSERT INTO tbl_booking_history (booking_id, employee_id, status_id, updated_at)
                VALUES (:booking_id, :employee_id, :status_id, NOW())"
            );
            $stmt->bindParam(":booking_id", $book_id);
            $stmt->bindParam(":employee_id", $employee_id);
            $stmt->bindParam(":status_id", $status_id);
            $stmt->execute();

            // 2. Handle room status changes based on booking status
            if (!empty($room_ids)) {
                $room_status_id = null;

                // Determine room status based on booking status (aligned with updated tbl_booking_status)
                switch ($status_id) {
                    case 5: // Checked-In
                        $room_status_id = 1; // Occupied
                        break;
                    case 4: // Checked-Out
                        $room_status_id = 5; // Dirty (needs cleaning)
                        break;
                    case 3: // Cancelled
                        $room_status_id = 3; // Vacant
                        break;
                    case 2: // Approved
                        $room_status_id = 1; // Occupied
                        break;
                }

                // Update room statuses if we have a valid room status
                if ($room_status_id !== null) {
                    $room_stmt = $conn->prepare(
                        "UPDATE tbl_rooms SET room_status_id = :room_status_id 
                         WHERE roomnumber_id = :room_id"
                    );

                    foreach ($room_ids as $room_id) {
                        $room_stmt->bindParam(":room_status_id", $room_status_id);
                        $room_stmt->bindParam(":room_id", $room_id);
                        $room_stmt->execute();
                    }
                }
            }

            // 3. If booking is approved and no specific rooms provided, get rooms from booking_room table
            if ($status_id == 2 && empty($room_ids)) {
                // Get all rooms associated with this booking
                $room_query = $conn->prepare(
                    "SELECT roomnumber_id FROM tbl_booking_room 
                     WHERE booking_id = :booking_id AND roomnumber_id IS NOT NULL"
                );
                $room_query->bindParam(":booking_id", $book_id);
                $room_query->execute();
                $booking_rooms = $room_query->fetchAll(PDO::FETCH_COLUMN);

                // Update all booking rooms to Occupied
                if (!empty($booking_rooms)) {
                    $room_stmt = $conn->prepare(
                        "UPDATE tbl_rooms SET room_status_id = 1 
                         WHERE roomnumber_id = :room_id"
                    );

                    foreach ($booking_rooms as $room_id) {
                        $room_stmt->bindParam(":room_id", $room_id);
                        $room_stmt->execute();
                    }
                }
            }

            $conn->commit();
            return json_encode([
                "success" => true,
                "message" => "Booking status updated successfully",
                "booking_id" => $book_id,
                "status_id" => $status_id,
                "rooms_updated" => count($room_ids)
            ]);
        } catch (PDOException $e) {
            $conn->rollBack();
            return json_encode([
                "success" => false,
                "error" => $e->getMessage()
            ]);
        }
    }

    function changeCustomerRoomsNumber($data)
    {
        include 'connection.php';

        try {
            $conn->beginTransaction();

            // Parse and validate input data
            $booking_id = intval($data["booking_id"]);
            $employee_id = intval($data["employee_id"]);
            $room_numbers = $data["room_numbers"]; // Can be single room or comma-separated multiple rooms

            // Validate required fields
            if (empty($booking_id) || empty($room_numbers) || empty($employee_id)) {
                throw new Exception("Missing required fields: booking_id, room_numbers, or employee_id");
            }

            // Parse room numbers (handle both single room and multiple rooms)
            $new_room_ids = array_map('intval', explode(',', $room_numbers));
            $new_room_ids = array_filter($new_room_ids); // Remove empty values

            if (empty($new_room_ids)) {
                throw new Exception("No valid room numbers provided");
            }

            // Get current booking rooms to update
            $current_rooms_stmt = $conn->prepare(
                "SELECT booking_room_id, roomnumber_id, roomtype_id 
                 FROM tbl_booking_room 
                 WHERE booking_id = :booking_id AND roomnumber_id IS NOT NULL"
            );
            $current_rooms_stmt->bindParam(":booking_id", $booking_id);
            $current_rooms_stmt->execute();
            $current_booking_rooms = $current_rooms_stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($current_booking_rooms)) {
                throw new Exception("No existing rooms found for this booking");
            }

            // Validate that we have enough new rooms for the booking
            if (count($new_room_ids) < count($current_booking_rooms)) {
                throw new Exception("Not enough new rooms provided. Booking has " . count($current_booking_rooms) . " rooms but only " . count($new_room_ids) . " new rooms provided");
            }

            // Check if new rooms are available (vacant OR already assigned to this booking)
            $placeholders = str_repeat('?,', count($new_room_ids) - 1) . '?';
            $room_check_stmt = $conn->prepare(
                "SELECT r.roomnumber_id 
                 FROM tbl_rooms r
                 LEFT JOIN tbl_booking_room br ON r.roomnumber_id = br.roomnumber_id AND br.booking_id = ?
                 WHERE r.roomnumber_id IN ($placeholders) 
                 AND (r.room_status_id = 3 OR br.booking_id = ?)"
            );
            $params = array_merge([$booking_id], $new_room_ids, [$booking_id]);
            $room_check_stmt->execute($params);
            $available_rooms = $room_check_stmt->fetchAll(PDO::FETCH_COLUMN);

            if (count($available_rooms) < count($new_room_ids)) {
                $unavailable_rooms = array_diff($new_room_ids, $available_rooms);
                throw new Exception("Rooms " . implode(', ', $unavailable_rooms) . " are not available (not vacant or already occupied by another booking)");
            }

            // Store old room IDs for status update
            $old_room_ids = array_column($current_booking_rooms, 'roomnumber_id');

            // Update booking rooms with new room numbers
            $update_booking_room_stmt = $conn->prepare(
                "UPDATE tbl_booking_room 
                 SET roomnumber_id = :new_room_id 
                 WHERE booking_room_id = :booking_room_id"
            );

            $updated_rooms = [];
            for ($i = 0; $i < count($current_booking_rooms); $i++) {
                $booking_room_id = $current_booking_rooms[$i]['booking_room_id'];
                $new_room_id = $new_room_ids[$i];

                $update_booking_room_stmt->bindParam(":new_room_id", $new_room_id);
                $update_booking_room_stmt->bindParam(":booking_room_id", $booking_room_id);
                $update_booking_room_stmt->execute();

                $updated_rooms[] = [
                    'booking_room_id' => $booking_room_id,
                    'old_room_id' => $current_booking_rooms[$i]['roomnumber_id'],
                    'new_room_id' => $new_room_id
                ];
            }

            // Update room statuses: set old rooms to vacant (status = 3) - only if they're not in the new list
            $rooms_to_free = array_diff($old_room_ids, $new_room_ids);
            if (!empty($rooms_to_free)) {
                $old_room_status_stmt = $conn->prepare(
                    "UPDATE tbl_rooms 
                     SET room_status_id = 3 
                     WHERE roomnumber_id IN (" . str_repeat('?,', count($rooms_to_free) - 1) . "?)"
                );
                $old_room_status_stmt->execute(array_values($rooms_to_free));
            }

            // Update room statuses: set new rooms to occupied (status = 1) - only if they're not already occupied by this booking
            $rooms_to_occupy = array_diff($new_room_ids, $old_room_ids);
            if (!empty($rooms_to_occupy)) {
                $new_room_status_stmt = $conn->prepare(
                    "UPDATE tbl_rooms 
                     SET room_status_id = 1 
                     WHERE roomnumber_id IN (" . str_repeat('?,', count($rooms_to_occupy) - 1) . "?)"
                );
                $new_room_status_stmt->execute(array_values($rooms_to_occupy));
            }

            $conn->commit();

            return json_encode([
                "success" => true,
                "message" => "Customer room numbers updated successfully",
                "booking_id" => $booking_id,
                "updated_rooms" => $updated_rooms,
                "old_rooms_freed" => $old_room_ids,
                "new_rooms_assigned" => $new_room_ids
            ]);
        } catch (PDOException $e) {
            $conn->rollBack();
            return json_encode([
                "success" => false,
                "error" => "Database error: " . $e->getMessage()
            ]);
        } catch (Exception $e) {
            $conn->rollBack();
            return json_encode([
                "success" => false,
                "error" => $e->getMessage()
            ]);
        }
    }

    function getAllBookingStatus()
    {
        include 'connection.php';

        try {
            $sql = "SELECT * FROM tbl_booking_status ";
            $stmt = $conn->prepare($sql);
            $stmt->execute();

            $statuses = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode($statuses);
        } catch (PDOException $e) {
            return false;
        }
    }

    function getAllRoomStatus()
    {
        include 'connection.php';

        try {
            $sql = "SELECT * FROM  tbl_status_types";
            $stmt = $conn->prepare($sql);
            $stmt->execute();

            $statuses = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode($statuses);
        } catch (PDOException $e) {
            return false;
        }
    }

    // Walk-in

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



    function insertWalkInBooking($data)
    {
        include 'connection.php';

        try {
            $conn->beginTransaction();

            // 1. Insert customer identification if provided
            $identificationId = null;
            if (!empty($data['identification_id'])) {
                $stmt = $conn->prepare("INSERT INTO tbl_customer_identification (identification_name) VALUES (:name)");
                $stmt->execute([':name' => $data['identification_id']]);
                $identificationId = $conn->lastInsertId();
            }

            // 2. Insert into tbl_customers
            $stmt = $conn->prepare("INSERT INTO tbl_customers 
                (nationality_id, identification_id, customers_fname, customers_lname, customers_email, customers_phone, customers_birthdate, customers_address, customers_created_at, customers_status)
                VALUES (:nationality_id, :identification_id, :fname, :lname, :email, :phone, :birthdate, :address, NOW(), 'Active')");
            $stmt->execute([
                ':nationality_id' => $data['nationality_id'],
                ':identification_id' => $identificationId,
                ':fname' => $data['customers_fname'],
                ':lname' => $data['customers_lname'],
                ':email' => $data['customers_email'],
                ':phone' => $data['customers_phone_number'],
                ':birthdate' => $data['customers_date_of_birth'],
                ':address' => $data['customers_address']
            ]);
            $customerId = $conn->lastInsertId();

            // 3. Insert into tbl_customers_walk_in
            $stmt = $conn->prepare("INSERT INTO tbl_customers_walk_in 
                (customers_id, customers_walk_in_fname, customers_walk_in_lname, customers_walk_in_phone, customers_walk_in_email, customers_walk_in_address, customers_walk_in_birthdate, customers_walk_in_created_at, customers_walk_in_status)
                VALUES (:customers_id, :fname, :lname, :phone, :email, :address, :birthdate, NOW(), 'Active')");
            $stmt->execute([
                ':customers_id' => $customerId,
                ':fname' => $data['customers_fname'],
                ':lname' => $data['customers_lname'],
                ':phone' => $data['customers_phone_number'],
                ':email' => $data['customers_email'],
                ':address' => $data['customers_address'],
                ':birthdate' => $data['customers_date_of_birth']
            ]);
            $walkInId = $conn->lastInsertId();

            // 4. Insert into tbl_booking
            $reference_no = "REF" . date("YmdHis") . rand(100, 999); // Generate unique reference number
            // Use Asia/Manila timezone and set check-in time to current local time; check-out = same time minus 2 hours (22-hour stay)
            date_default_timezone_set('Asia/Manila');
            $time_part = date('H:i:s');
            $stmt = $conn->prepare("INSERT INTO tbl_booking
                (customers_id, customers_walk_in_id, adult, children, guests_amnt, booking_totalAmount, booking_downpayment, reference_no, booking_checkin_dateandtime, booking_checkout_dateandtime, booking_created_at, booking_isArchive)
                VALUES (:customers_id, :walkin_id, :adult, :children, :guests, :total_amount, :downpayment, :ref_no, CONCAT(:checkin, ' ', :time_part), DATE_SUB(CONCAT(:checkout, ' ', :time_part), INTERVAL 2 HOUR), NOW(), 0)");
            $stmt->execute([
                ':customers_id' => $customerId,
                ':walkin_id' => $walkInId,
                ':adult' => $data['adult'],
                ':children' => $data['children'],
                ':guests' => $data['adult'] + $data['children'],
                ':total_amount' => $data['billing']['total'],
                ':downpayment' => $data['payment']['amountPaid'],
                ':ref_no' => $reference_no,
                ':checkin' => $data['checkIn'],
                ':checkout' => $data['checkOut'],
                ':time_part' => $time_part
            ]);
            $bookingId = $conn->lastInsertId();

            // 5. Insert booking history (status_id = 5 for Checked-In)
            $stmt = $conn->prepare("INSERT INTO tbl_booking_history
                (booking_id, employee_id, status_id, updated_at)
                VALUES (:booking_id, :employee_id, :status_id, NOW())");
            $stmt->execute([
                ':booking_id' => $bookingId,
                ':employee_id' => 1, // Default admin/employee, can be customized
                ':status_id' => 5 // Checked-In
            ]);

            // 6. Assign rooms and update their status to Occupied
            foreach ($data['selectedRooms'] as $room) {
                // Get roomtype_id from tbl_rooms
                $stmtRoomType = $conn->prepare("SELECT roomtype_id FROM tbl_rooms WHERE roomnumber_id = :roomnumber_id LIMIT 1");
                $stmtRoomType->execute([':roomnumber_id' => $room['roomnumber_id']]);
                $roomtype_id = $stmtRoomType->fetchColumn();

                // Insert into tbl_booking_room
                $stmt = $conn->prepare("INSERT INTO tbl_booking_room (booking_id, roomtype_id, roomnumber_id)
                    VALUES (:booking_id, :roomtype_id, :roomnumber_id)");
                $stmt->execute([
                    ':booking_id' => $bookingId,
                    ':roomtype_id' => $roomtype_id,
                    ':roomnumber_id' => $room['roomnumber_id']
                ]);

                // Update room status to Occupied (status_id = 1)
                $stmt = $conn->prepare("UPDATE tbl_rooms SET room_status_id = 1 WHERE roomnumber_id = :room_id");
                $stmt->execute([':room_id' => $room['roomnumber_id']]);
            }

            // 7. Insert billing record
            $stmt = $conn->prepare("INSERT INTO tbl_billing (
                booking_id, employee_id, payment_method_id, billing_dateandtime, billing_invoice_number, billing_downpayment, billing_vat, billing_total_amount, billing_balance
            ) VALUES (
                :booking_id, :employee_id, :payment_method_id, NOW(), :invoice_number, :downpayment, :vat, :total_amount, :balance
            )");
            $stmt->execute([
                ':booking_id' => $bookingId,
                ':employee_id' => 1, // Default admin/employee, can be customized
                ':payment_method_id' => 2, // Cash (from tbl_payment_method, adjust if needed)
                ':invoice_number' => $reference_no,
                ':downpayment' => $data['payment']['amountPaid'],
                ':vat' => $data['billing']['vat'],
                ':total_amount' => $data['billing']['total'],
                ':balance' => $data['billing']['total'] - $data['payment']['amountPaid'] // Calculate remaining balance
            ]);

            $conn->commit();
            return json_encode([
                'status' => 'success', 
                'message' => 'Walk-in booking inserted successfully.',
                'booking_id' => $bookingId,
                'reference_no' => $reference_no,
                'customer_id' => $customerId
            ]);
        } catch (Exception $e) {
            $conn->rollBack();
            return json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    // Online
    function customerBookingReqs()
    {
        include 'connection.php';
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        try {
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
                JOIN tbl_customers c 
                    ON b.customers_id = c.customers_id
                LEFT JOIN tbl_customers_online co 
                    ON c.customers_online_id = co.customers_online_id
                JOIN tbl_booking_room br 
                    ON br.booking_id = b.booking_id
                JOIN tbl_roomtype rt 
                    ON br.roomtype_id = rt.roomtype_id
                LEFT JOIN (
                    SELECT bh.booking_id, bs.booking_status_name
                    FROM tbl_booking_history bh
                    INNER JOIN tbl_booking_status bs 
                        ON bh.status_id = bs.booking_status_id
                    WHERE bh.booking_history_id IN (
                        SELECT MAX(booking_history_id)
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

            if (empty($requests)) {
                echo json_encode([
                    "error" => false,
                    "message" => "No pending booking requests found"
                ]);
                return;
            }

            foreach ($requests as &$req) {
                $roomTypeIds   = !empty($req['roomtype_ids']) ? explode(',', $req['roomtype_ids']) : [];
                $roomTypeNames = !empty($req['roomtype_names']) ? explode(',', $req['roomtype_names']) : [];
                $roomNumberIds = !empty($req['roomnumber_ids']) ? explode(',', $req['roomnumber_ids']) : [];

                $rooms = [];
                for ($i = 0; $i < count($roomTypeIds); $i++) {
                    $roomnumber_id = $roomNumberIds[$i] ?? null;
                    $status_name = 'Pending';

                    if ($roomnumber_id) {
                        $roomStmt = $conn->prepare("
                        SELECT st.status_name 
                        FROM tbl_rooms r 
                        INNER JOIN tbl_status_types st 
                            ON r.room_status_id = st.status_id 
                        WHERE r.roomnumber_id = :roomnumber_id
                    ");
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
        } catch (PDOException $e) {
            echo json_encode([
                "error" => true,
                "message" => $e->getMessage()
            ]);
        }
    }

    function approveCustomerBooking($data)
    {
        include 'connection.php';
        include_once 'send_email.php';

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

            // Prepare statements in advance
            $sqlUpdateBookingRoom = "UPDATE tbl_booking_room 
                                    SET roomnumber_id = :room_id
                                    WHERE booking_id = :booking_id 
                                    AND roomtype_id = (SELECT roomtype_id FROM tbl_rooms WHERE roomnumber_id = :room_id)
                                    AND roomnumber_id IS NULL
                                    LIMIT 1";
            $stmtUpdateBookingRoom = $conn->prepare($sqlUpdateBookingRoom);

            $sqlInsertBookingRoom = "INSERT INTO tbl_booking_room (booking_id, roomtype_id, roomnumber_id)
                                    SELECT :booking_id, r.roomtype_id, r.roomnumber_id
                                    FROM tbl_rooms r
                                    WHERE r.roomnumber_id = :room_id";
            $stmtInsertBookingRoom = $conn->prepare($sqlInsertBookingRoom);

            $sqlUpdateRoom = "UPDATE tbl_rooms SET room_status_id = :status_id WHERE roomnumber_id = :room_id";
            $stmtUpdateRoom = $conn->prepare($sqlUpdateRoom);

            // 0️⃣ Normalize times to Asia/Manila and enforce 22-hour stay
            date_default_timezone_set('Asia/Manila');
            $time_part = date('H:i:s');
            $stmtTime = $conn->prepare("UPDATE tbl_booking 
                SET 
                    booking_checkin_dateandtime = CONCAT(DATE(booking_checkin_dateandtime), ' ', :time_part),
                    booking_checkout_dateandtime = DATE_SUB(CONCAT(DATE(booking_checkout_dateandtime), ' ', :time_part), INTERVAL 2 HOUR)
                WHERE booking_id = :booking_id");
            $stmtTime->execute([':booking_id' => $bookingId, ':time_part' => $time_part]);

            foreach ($roomIds as $roomId) {
                // 2️⃣ Try updating an existing placeholder row
                $stmtUpdateBookingRoom->execute([
                    ':booking_id' => $bookingId,
                    ':room_id'    => $roomId
                ]);

                if ($stmtUpdateBookingRoom->rowCount() === 0) {
                    // 3️⃣ If no row was updated, insert a new one
                    $stmtInsertBookingRoom->execute([
                        ':booking_id' => $bookingId,
                        ':room_id'    => $roomId
                    ]);
                }

                // 4️⃣ Update room status to Occupied
                $stmtUpdateRoom->execute([
                    ':status_id' => $statusId,
                    ':room_id'   => $roomId
                ]);
            }

            // 5️⃣ Insert booking history (Approved = 2)
            $sqlHistory = "INSERT INTO tbl_booking_history (booking_id, employee_id, status_id, updated_at)
                             VALUES (:booking_id, :admin_id, 2, NOW())";
            $stmtHistory = $conn->prepare($sqlHistory);
            $stmtHistory->execute([
                ':booking_id' => $bookingId,
                ':admin_id'   => $adminId
            ]);

            $conn->commit();

            // Send approval email to the customer (best-effort; errors are ignored)
            try {
                // Get customer contact, booking info, and payment details
                $infoStmt = $conn->prepare("SELECT 
                        COALESCE(c.customers_email, w.customers_walk_in_email) AS email,
                        COALESCE(CONCAT(c.customers_fname, ' ', c.customers_lname),
                                 CONCAT(w.customers_walk_in_fname, ' ', w.customers_walk_in_lname)) AS customer_name,
                        b.reference_no,
                        b.booking_checkin_dateandtime,
                        b.booking_checkout_dateandtime,
                        b.booking_totalAmount,
                        b.booking_downpayment,
                        b.adult,
                        b.children,
                        b.guests_amnt,
                        -- Billing details
                        bill.billing_total_amount,
                        bill.billing_downpayment AS billing_downpayment,
                        bill.billing_vat,
                        bill.billing_balance,
                        bill.billing_invoice_number,
                        bill.billing_dateandtime,
                        -- Payment method
                        pm.payment_method_name,
                        -- Invoice details
                        inv.invoice_id,
                        inv.invoice_date,
                        inv.invoice_time,
                        inv.invoice_total_amount
                    FROM tbl_booking b
                    LEFT JOIN tbl_customers c ON b.customers_id = c.customers_id
                    LEFT JOIN tbl_customers_walk_in w ON b.customers_walk_in_id = w.customers_walk_in_id
                    LEFT JOIN (
                        SELECT booking_id, 
                               MAX(billing_id) AS latest_billing_id
                        FROM tbl_billing 
                        GROUP BY booking_id
                    ) lb ON lb.booking_id = b.booking_id
                    LEFT JOIN tbl_billing bill ON bill.billing_id = lb.latest_billing_id
                    LEFT JOIN tbl_payment_method pm ON bill.payment_method_id = pm.payment_method_id
                    LEFT JOIN tbl_invoice inv ON bill.billing_id = inv.billing_id
                    WHERE b.booking_id = :booking_id LIMIT 1");
                $infoStmt->execute([':booking_id' => $bookingId]);
                $info = $infoStmt->fetch(PDO::FETCH_ASSOC) ?: [];

                $customerEmail = $info['email'] ?? null;
                $customerName  = $info['customer_name'] ?? 'Guest';
                $refNo         = $info['reference_no'] ?? '';
                $checkInRaw    = $info['booking_checkin_dateandtime'] ?? '';
                $checkOutRaw   = $info['booking_checkout_dateandtime'] ?? '';

                // Payment details
                $totalAmount = $info['billing_total_amount'] ?? $info['booking_totalAmount'] ?? 0;
                $downpayment = $info['billing_downpayment'] ?? $info['booking_downpayment'] ?? 0;
                $vat = $info['billing_vat'] ?? 0;
                $balance = $info['billing_balance'] ?? 0;
                $paymentMethod = $info['payment_method_name'] ?? 'Not specified';
                $invoiceNumber = $info['billing_invoice_number'] ?? $refNo;
                $billingDate = $info['billing_dateandtime'] ?? '';
                $invoiceDate = $info['invoice_date'] ?? '';
                $invoiceTime = $info['invoice_time'] ?? '';

                // Format dates to be more readable (e.g., "June 3, 2025")
                $checkIn = '';
                $checkOut = '';
                if (!empty($checkInRaw)) {
                    $checkIn = date('F j, Y', strtotime($checkInRaw));
                }
                if (!empty($checkOutRaw)) {
                    $checkOut = date('F j, Y', strtotime($checkOutRaw));
                }

                // Determine assigned rooms (prefer provided list; otherwise read from DB)
                $assignedRooms = $roomIds;
                if (empty($assignedRooms)) {
                    $roomsStmt = $conn->prepare("SELECT roomnumber_id FROM tbl_booking_room WHERE booking_id = :booking_id AND roomnumber_id IS NOT NULL ORDER BY booking_room_id ASC");
                    $roomsStmt->execute([':booking_id' => $bookingId]);
                    $assignedRooms = $roomsStmt->fetchAll(PDO::FETCH_COLUMN);
                }

                if (!empty($customerEmail)) {
                    $subject = 'Booking Confirmation - Demiren Hotel';
                    $roomsText = !empty($assignedRooms) ? implode(', ', array_map('strval', $assignedRooms)) : 'To be assigned at check-in';

                    // Format payment date
                    $paymentDate = '';
                    if (!empty($billingDate)) {
                        $paymentDate = date('F j, Y', strtotime($billingDate));
                    }

                    // Get room type information for better display
                    $roomTypeInfo = '';
                    if (!empty($assignedRooms)) {
                        $roomTypeStmt = $conn->prepare("
                            SELECT DISTINCT rt.roomtype_name 
                            FROM tbl_booking_room br 
                            JOIN tbl_rooms r ON br.roomnumber_id = r.roomnumber_id 
                            JOIN tbl_roomtype rt ON r.roomtype_id = rt.roomtype_id 
                            WHERE br.booking_id = :booking_id AND br.roomnumber_id IS NOT NULL
                        ");
                        $roomTypeStmt->execute([':booking_id' => $bookingId]);
                        $roomTypes = $roomTypeStmt->fetchAll(PDO::FETCH_COLUMN);
                        $roomTypeInfo = !empty($roomTypes) ? implode(', ', $roomTypes) : 'Standard Room';
                    }

                    // Get guest count information
                    $guestInfo = '';
                    $adultCount = $info['adult'] ?? 1;
                    $childrenCount = $info['children'] ?? 0;
                    $guestInfo = $adultCount . ' adult' . ($adultCount > 1 ? 's' : '');
                    if ($childrenCount > 0) {
                        $guestInfo .= ', ' . $childrenCount . ' child' . ($childrenCount > 1 ? 'ren' : '');
                    }

                    $emailBody = "<div style=\"font-family:Arial,sans-serif;color:#000;max-width:600px;margin:0 auto;background:#fff;\">"
                        . "<div style=\"padding:20px;\">"

                        // Greeting
                        . "<p style=\"margin:0 0 16px;font-size:16px;\">Dear " . htmlspecialchars($customerName) . ",</p>"
                        . "<p style=\"margin:0 0 20px;font-size:16px;line-height:1.5;\">Thank you for booking your stay with us. We are looking forward to your visit.</p>"

                        // Booking Details Section
                        . "<p style=\"margin:0 0 12px;font-size:16px;\">Your booking details are as follows:</p>"
                        . "<div style=\"background:#f9f9f9;border:1px solid #ddd;border-radius:8px;padding:20px;margin:20px 0;\">"

                        // Two-column layout for booking details
                        . "<table style=\"width:100%;border-collapse:collapse;\">"
                        . "<tr><td style=\"padding:8px 0;border-bottom:1px solid #eee;font-size:14px;\">Check in</td><td style=\"padding:8px 0;border-bottom:1px solid #eee;font-size:14px;font-weight:bold;\">" . htmlspecialchars($checkIn) . "</td></tr>"
                        . "<tr><td style=\"padding:8px 0;border-bottom:1px solid #eee;font-size:14px;\">Check out</td><td style=\"padding:8px 0;border-bottom:1px solid #eee;font-size:14px;font-weight:bold;\">" . htmlspecialchars($checkOut) . "</td></tr>"
                        . "<tr><td style=\"padding:8px 0;border-bottom:1px solid #eee;font-size:14px;\">Room</td><td style=\"padding:8px 0;border-bottom:1px solid #eee;font-size:14px;\">" . htmlspecialchars($roomTypeInfo) . "</td></tr>"
                        . "<tr><td style=\"padding:8px 0;border-bottom:1px solid #eee;font-size:14px;\">Breakfast</td><td style=\"padding:8px 0;border-bottom:1px solid #eee;font-size:14px;\">included</td></tr>"
                        . "<tr><td style=\"padding:8px 0;border-bottom:1px solid #eee;font-size:14px;\"># of Guests</td><td style=\"padding:8px 0;border-bottom:1px solid #eee;font-size:14px;\">" . htmlspecialchars($guestInfo) . "</td></tr>"
                        . "<tr><td style=\"padding:8px 0;border-bottom:1px solid #eee;font-size:14px;\">Booked by</td><td style=\"padding:8px 0;border-bottom:1px solid #eee;font-size:14px;\">" . htmlspecialchars($customerName) . " (" . htmlspecialchars($customerEmail) . ")</td></tr>"
                        . "<tr><td style=\"padding:8px 0;font-size:14px;\">Total</td><td style=\"padding:8px 0;font-size:14px;font-weight:bold;\">₱" . number_format($totalAmount, 2) . "</td></tr>"
                        . "</table>"
                        . "</div>"

                        // Closing remarks
                        . "<p style=\"margin:20px 0 0;font-size:16px;line-height:1.5;\">If you have any questions please don't hesitate to contact us.</p>"
                        . "<p style=\"margin:16px 0 0;font-size:16px;line-height:1.5;\">We hope you enjoy your stay with us!</p>"

                        // Sign-off
                        . "<div style=\"margin-top:30px;\">"
                        . "<p style=\"margin:0 0 4px;font-size:16px;\">Best Regards,</p>"
                        . "<p style=\"margin:0;font-size:16px;font-weight:bold;\">Demiren Hotel</p>"
                        . "</div>"

                        . "</div>"
                        . "</div>";

                    $mailer = new SendEmail();
                    $mailer->sendEmail($customerEmail, $subject, $emailBody);
                }
            } catch (Exception $e) {
                // Ignore email issues; the booking approval already succeeded
            }

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

            // 3️⃣ Do NOT delete from tbl_booking_room and do NOT archive booking

            // 4️⃣ Insert booking history (Declined = 3)
            $sqlHistory = "INSERT INTO tbl_booking_history (booking_id, employee_id, status_id, updated_at)
                           VALUES (:booking_id, :admin_id, 3, NOW())";
            $stmtHistory = $conn->prepare($sqlHistory);
            $stmtHistory->execute([
                ':booking_id' => $bookingId,
                ':admin_id'   => $adminId
            ]);

            $conn->commit();
            echo json_encode(["success" => true, "message" => "Booking declined and history updated."]);
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
            SELECT SUM(rt.roomtype_price) AS room_total
            FROM tbl_booking_room br
            JOIN tbl_rooms r ON br.roomnumber_id = r.roomnumber_id
            JOIN tbl_roomtype rt ON r.roomtype_id = rt.roomtype_id
            WHERE br.booking_id = :booking_id
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

        $sql = "DELETE FROM tbl_room_amenities_master WHERE room_amenities_master_id = :amenityID";

        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":amenityID", $data["amenity_id"]);
        $stmt->execute();

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
        SET charges_category_id = :categoryID, charges_master_name = :chargeName, charges_master_price = :chargePrice
        WHERE charges_master_id = :chargeID";

        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":chargeID", $data["charges_master_id"]);
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

        $sql = "DELETE FROM tbl_charges_master WHERE charges_master_id = :chargeID";

        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":chargeID", $data["charges_master_id"]);
        $stmt->execute();

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
        SET charges_category_name = :chargeCategoryName
        WHERE charges_category_id = :chargeCategoryID";

        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":chargeCategoryID", $data["charges_category_id"]);
        $stmt->bindParam(":chargeCategoryName", $data["charge_category_name"]);
        $stmt->execute();

        $rowCount = $stmt->rowCount();
        unset($stmt, $conn);

        return $rowCount > 0 ? 1 : 0;
    }

    function remove_ChargeCategory($data)
    {
        include "connection.php";

        $sql = "DELETE FROM tbl_charges_category WHERE charges_category_id = :categoryID";

        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":categoryID", $data["charges_category_id"]);
        $stmt->execute();

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
        SET discounts_type = :discountType, discounts_datestart = :discountDateStart, 
            discounts_dateend = :discountDateEnd, discounts_percent = :discountPercent
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

        $sql = "DELETE FROM tbl_discounts WHERE discounts_id = :discountID";

        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":discountID", $data["discounts_id"]);
        $stmt->execute();

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

        $sql = "INSERT INTO tbl_roomtype (roomtype_name, max_capacity, roomtype_description, roomtype_price, roomtype_beds, roomtype_capacity, roomtype_sizes)
        VALUES (:roomTypeName, :maxCapacity, :roomTypeDescription, :roomTypePrice, :roomTypeBeds, :roomTypeCapacity, :roomTypeSizes)";

        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":roomTypeName", $data["roomtype_name"]);
        $stmt->bindParam(":maxCapacity", $data["max_capacity"]);
        $stmt->bindParam(":roomTypeDescription", $data["roomtype_description"]);
        $stmt->bindParam(":roomTypePrice", $data["roomtype_price"]);
        $stmt->bindParam(":roomTypeBeds", $data["roomtype_beds"]);
        $stmt->bindParam(":roomTypeCapacity", $data["roomtype_capacity"]);
        $stmt->bindParam(":roomTypeSizes", $data["roomtype_sizes"]);
        $stmt->execute();

        $rowCount = $stmt->rowCount();
        unset($stmt, $conn);

        return $rowCount > 0 ? 1 : 0;
    }

    function update_CurrRoomTypes($data)
    {
        include "connection.php";

        $sql = "UPDATE tbl_roomtype 
        SET roomtype_name = :roomTypeName, max_capacity = :maxCapacity, roomtype_description = :roomTypeDescription, 
            roomtype_price = :roomTypePrice, roomtype_beds = :roomTypeBeds, roomtype_capacity = :roomTypeCapacity, 
            roomtype_sizes = :roomTypeSizes
        WHERE roomtype_id = :roomTypeID";

        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":roomTypeID", $data["roomtype_id"]);
        $stmt->bindParam(":roomTypeName", $data["roomtype_name"]);
        $stmt->bindParam(":maxCapacity", $data["max_capacity"]);
        $stmt->bindParam(":roomTypeDescription", $data["roomtype_description"]);
        $stmt->bindParam(":roomTypePrice", $data["roomtype_price"]);
        $stmt->bindParam(":roomTypeBeds", $data["roomtype_beds"]);
        $stmt->bindParam(":roomTypeCapacity", $data["roomtype_capacity"]);
        $stmt->bindParam(":roomTypeSizes", $data["roomtype_sizes"]);
        $stmt->execute();

        $rowCount = $stmt->rowCount();
        unset($stmt, $conn);

        return $rowCount > 0 ? 1 : 0;
    }

    function remove_RoomTypes($data)
    {
        include "connection.php";

        $sql = "DELETE FROM tbl_roomtype WHERE roomtype_id = :roomTypeID";

        $stmt = $conn->prepare($sql);
        $stmt->bindParam(":roomTypeID", $data["roomtype_id"]);
        $stmt->execute();

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

    // For Bookings Management
    case "viewBookings":
        echo $AdminClass->viewBookingList();
        break;

    case "changeCustomerRoomsNumber":
        echo $AdminClass->changeCustomerRoomsNumber($jsonData);
        break;

    case "changeBookingStatus":
        echo $AdminClass->changeBookingStatus($jsonData);
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
        echo $AdminClass->finalizeBookingApproval($jsonData);
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

    case "getAllStatus":
        echo $AdminClass->getAllBookingStatus();
        break;

    // Room Management or Something?
    case "viewRooms":
        echo $AdminClass->viewAllRooms();
        break;

    case "viewAllRooms":
        echo $AdminClass->viewAllRooms();
        break;

    case "getAllBookingStatus":
        echo $AdminClass->getAllBookingStatus();
        break;

    case "getAllRoomStatus":
        echo $AdminClass->getAllRoomStatus();
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
        echo $AdminClass->update_CurrCharges($jsonData);
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