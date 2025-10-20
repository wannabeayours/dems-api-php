<?php

include "headers.php";

class Transactions
{
    function bookingList()
    {
        include "connection.php";

        $sql = "
    SELECT 
        b.reference_no AS 'Ref No',
        COALESCE(CONCAT(c.customers_fname, ' ', c.customers_lname),
                 CONCAT(w.customers_walk_in_fname, ' ', w.customers_walk_in_lname)) AS 'Name',
        b.booking_checkin_dateandtime AS 'Check-in',
        b.booking_checkout_dateandtime AS 'Check-out',
        GROUP_CONCAT(DISTINCT rt.roomtype_name SEPARATOR ', ') AS 'Room Type',
        'Pending' AS 'Status'
    FROM 
        tbl_booking b
    LEFT JOIN tbl_customers c ON b.customers_id = c.customers_id
    LEFT JOIN tbl_customers_walk_in w ON b.customers_walk_in_id = w.customers_walk_in_id
    LEFT JOIN tbl_booking_room br ON b.booking_id = br.booking_id
    LEFT JOIN tbl_roomtype rt ON br.roomtype_id = rt.roomtype_id
    WHERE 
        b.booking_id NOT IN (
            SELECT booking_id
            FROM tbl_booking_history
            WHERE status_id IN (1, 2, 3)
        )
    GROUP BY b.reference_no;
    ";

        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($result);
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
        $employee_id = isset($json['employee_id']) ? intval($json['employee_id']) : null;

        // Safeguards: require valid employee_id and validate active
        if (empty($employee_id) || $employee_id <= 0) {
            echo 'invalid_employee';
            return;
        }
        $empStmt = $conn->prepare("SELECT employee_status FROM tbl_employee WHERE employee_id = :employee_id");
        $empStmt->bindParam(':employee_id', $employee_id, PDO::PARAM_INT);
        $empStmt->execute();
        $empRow = $empStmt->fetch(PDO::FETCH_ASSOC);
        if (!$empRow || $empRow["employee_status"] == 0 || $empRow["employee_status"] === 'Inactive' || $empRow["employee_status"] === 'Disabled') {
            echo 'invalid_employee';
            return;
        }

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
        $stmt = $conn->prepare("
        SELECT br.roomtype_id, rt.roomtype_name, COUNT(*) AS room_count
        FROM tbl_booking_room br
        JOIN tbl_roomtype rt ON rt.roomtype_id = br.roomtype_id
        WHERE br.booking_id = :booking_id
        GROUP BY br.roomtype_id
    ");
        $stmt->bindParam(':booking_id', $booking_id);
        $stmt->execute();
        $roomGroups = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Step 3: Get all available rooms
        $data = [];
        foreach ($roomGroups as $group) {
            $stmt = $conn->prepare("
            SELECT r.roomnumber_id, r.roomfloor
            FROM tbl_rooms r
            WHERE r.roomtype_id = :roomtype_id AND r.room_status_id = 3
        ");
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

    function getRooms()
    {
        include "connection.php";
        $sql = "SELECT a.roomnumber_id, b.roomtype_name, c.status_name
                FROM tbl_rooms AS a
                INNER JOIN tbl_roomtype AS b ON b.roomtype_id = a.roomtype_id
                INNER JOIN tbl_status_types AS c ON c.status_id = a.room_status_id
                WHERE a.room_status_id = 3
                ORDER BY a.roomnumber_id ASC";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($result);
    }

    function chargesMasterList()
    {
        include "connection.php";

        $sql = "
        SELECT 
            c.charges_category_name AS 'Category',
            m.charges_master_id AS 'Charge ID',
            m.charges_master_name AS 'Charge Name',
            m.charges_master_price AS 'Price'
        FROM tbl_charges_master m
        JOIN tbl_charges_category c ON m.charges_category_id = c.charges_category_id
        ORDER BY c.charges_category_name, m.charges_master_name;
    ";

        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($result);
    }

    function bookingChargesList()
    {
        include "connection.php";

        $sql = "
        SELECT 
            bc.booking_charges_id AS 'Charge ID',
            bc.booking_room_id AS 'Room Booking ID',
            cc.charges_category_name AS 'Category',
            cm.charges_master_name AS 'Charge Name',
            bc.booking_charges_price AS 'Price',
            bc.booking_charges_quantity AS 'Quantity',
            (bc.booking_charges_price * bc.booking_charges_quantity) AS 'Total Amount'
        FROM tbl_booking_charges bc
        JOIN tbl_charges_master cm ON bc.charges_master_id = cm.charges_master_id
        JOIN tbl_charges_category cc ON cm.charges_category_id = cc.charges_category_id
        ORDER BY bc.booking_charges_id;
    ";

        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($result);
    }

    function addChargesAmenities()
    {
        include "connection.php";

        // Check if JSON exists in POST
        if (!isset($_POST['json'])) {
            echo json_encode(['status' => 'error', 'message' => 'No data sent']);
            return;
        }

        // Decode the incoming JSON
        $json = json_decode($_POST['json'], true);

        if (!isset($json['charges_category_id'], $json['charges_master_name'], $json['charges_master_price'])) {
            echo json_encode(['status' => 'error', 'message' => 'Incomplete data']);
            return;
        }

        $categoryId = $json['charges_category_id'];
        $amenityName = $json['charges_master_name'];
        $price = $json['charges_master_price'];

        try {
            $stmt = $conn->prepare("INSERT INTO tbl_charges_master (charges_category_id, charges_master_name, charges_master_price) VALUES (:categoryId, :name, :price)");
            $stmt->bindParam(':categoryId', $categoryId);
            $stmt->bindParam(':name', $amenityName);
            $stmt->bindParam(':price', $price);
            $success = $stmt->execute();

            echo json_encode($success ? 'success' : 'fail');
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    function getChargesCategory()
    {
        include "connection.php";

        try {
            $sql = "SELECT charges_category_id, charges_category_name FROM tbl_charges_category ORDER BY charges_category_name ASC";
            $stmt = $conn->prepare($sql);
            $stmt->execute();
            $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode($result);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    function saveAmenitiesCharges()
    {
        include "connection.php";

        if (!isset($_POST['json'])) {
            echo json_encode(['status' => 'error', 'message' => 'No data sent']);
            return;
        }

        $json = json_decode($_POST['json'], true);

        if (!isset($json['items']) || !is_array($json['items'])) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid data format']);
            return;
        }

        try {
            $conn->beginTransaction();

            foreach ($json['items'] as $item) {
                if (!isset($item['charges_category_id'], $item['charges_master_name'], $item['charges_master_price'])) {
                    throw new Exception('Missing required fields');
                }

                $stmt = $conn->prepare("INSERT INTO tbl_charges_master (charges_category_id, charges_master_name, charges_master_price) VALUES (:categoryId, :name, :price)");
                $stmt->bindParam(':categoryId', $item['charges_category_id']);
                $stmt->bindParam(':name', $item['charges_master_name']);
                $stmt->bindParam(':price', $item['charges_master_price']);
                $stmt->execute();
            }

            $conn->commit();
            echo 'success';
        } catch (Exception $e) {
            $conn->rollBack();
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    function updateAmenityCharges()
    {
        include "connection.php";

        if (!isset($_POST['json'])) {
            echo json_encode(['status' => 'error', 'message' => 'No data sent']);
            return;
        }

        $json = json_decode($_POST['json'], true);

        if (!isset($json['charges_master_id'], $json['charges_master_name'], $json['charges_master_price'])) {
            echo json_encode(['status' => 'error', 'message' => 'Missing required fields']);
            return;
        }

        try {
            $stmt = $conn->prepare("UPDATE tbl_charges_master SET charges_master_name = :name, charges_master_price = :price WHERE charges_master_id = :id");
            $stmt->bindParam(':name', $json['charges_master_name']);
            $stmt->bindParam(':price', $json['charges_master_price']);
            $stmt->bindParam(':id', $json['charges_master_id']);

            $result = $stmt->execute();

            if ($result && $stmt->rowCount() > 0) {
                echo 'success';
            } else {
                echo json_encode(['status' => 'error', 'message' => 'No records updated']);
            }
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }



    function createInvoice($json)
    {
        include "connection.php";
        $json = json_decode($json, true);
        $billing_ids = isset($json["billing_ids"]) && is_array($json["billing_ids"]) ? $json["billing_ids"] : [];
        $employee_id = isset($json["employee_id"]) ? intval($json["employee_id"]) : null;
        $payment_method_id = $json["payment_method_id"] ?? 2; // Default to Cash
        $invoice_status_id = 1; // Always set to Complete for checkout scenarios
        $discount_id = $json["discount_id"] ?? null;
        $vat_rate = $json["vat_rate"] ?? 0.0; // Default 0% VAT
        $downpayment = $json["downpayment"] ?? 0;
    
        // Safeguards: ensure billing_ids present and employee_id valid; validate employee exists and is active
        if (empty($billing_ids)) {
            echo json_encode(["success" => false, "message" => "Missing required field: billing_ids"]);
            return;
        }
        if (empty($employee_id) || $employee_id <= 0) {
            echo json_encode(["success" => false, "message" => "Missing or invalid employee_id"]);
            return;
        }
        $empStmt = $conn->prepare("SELECT employee_status FROM tbl_employee WHERE employee_id = :employee_id");
        $empStmt->bindParam(':employee_id', $employee_id, PDO::PARAM_INT);
        $empStmt->execute();
        $empRow = $empStmt->fetch(PDO::FETCH_ASSOC);
        if (!$empRow) {
            echo json_encode(["success" => false, "message" => "Employee not found"]);
            return;
        }
        $status = $empRow["employee_status"];
        if ($status === 'Inactive' || $status === 'Disabled' || $status === '0' || $status === 0) {
            echo json_encode(["success" => false, "message" => "Employee is not active"]);
            return;
        }
    
        $invoice_date = date("Y-m-d");
        $invoice_time = date("H:i:s");
        $results = [];

        try {
            $conn->beginTransaction();

        foreach ($billing_ids as $billing_id) {
            // 1. Get the booking_id and booking downpayment linked to this billing_id
            $stmt = $conn->prepare("
                SELECT b.booking_id, b.booking_downpayment 
                FROM tbl_billing bi 
                JOIN tbl_booking b ON bi.booking_id = b.booking_id 
                WHERE bi.billing_id = :billing_id
            ");
            $stmt->bindParam(':billing_id', $billing_id);
            $stmt->execute();
            $billingRow = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$billingRow) {
                    $results[] = ["billing_id" => $billing_id, "status" => "error", "message" => "Billing not found"];
                    continue;
                }

            $booking_id = $billingRow["booking_id"];
            // Use booking's downpayment if not manually specified, otherwise use the provided downpayment
            $actual_downpayment = $downpayment > 0 ? $downpayment : ($billingRow["booking_downpayment"] ?? 0);

                // 2. Calculate comprehensive billing breakdown
                $billingBreakdown = $this->calculateComprehensiveBillingInternal($conn, $booking_id, $discount_id, $vat_rate, $actual_downpayment);

                if (!$billingBreakdown["success"]) {
                    $results[] = ["billing_id" => $billing_id, "status" => "error", "message" => $billingBreakdown["message"]];
                    continue;
                }

                // 3. Update billing with comprehensive totals
            $updateBilling = $conn->prepare("
            UPDATE tbl_billing 
                    SET billing_total_amount = :total, 
                        billing_balance = :balance,
                        billing_downpayment = :downpayment,
                        billing_vat = :vat,
                        discounts_id = :discount_id
            WHERE billing_id = :billing_id
        ");
                $updateBilling->bindParam(':total', $billingBreakdown["final_total"]);
                $updateBilling->bindParam(':balance', $billingBreakdown["balance"]);
                $updateBilling->bindParam(':downpayment', $actual_downpayment);
                $updateBilling->bindParam(':vat', $billingBreakdown["vat_amount"]);
                $updateBilling->bindParam(':discount_id', $discount_id);
            $updateBilling->bindParam(':billing_id', $billing_id);
            $updateBilling->execute();

                // 4. Create invoice
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
                $insert->bindParam(':invoice_total_amount', $billingBreakdown["final_total"]);
            $insert->bindParam(':invoice_status_id', $invoice_status_id);
            $insert->execute();

                $invoice_id = $conn->lastInsertId();

                // 5. Log billing activity
                $this->logBillingActivity($conn, $billing_id, $invoice_id, $employee_id, "INVOICE_CREATED", $billingBreakdown);

                $results[] = [
                    "billing_id" => $billing_id,
                    "invoice_id" => $invoice_id,
                    "status" => "success",
                    "breakdown" => $billingBreakdown
                ];
            }

            $conn->commit();
            echo json_encode([
                "success" => true, 
                "message" => "Invoices created successfully with comprehensive billing validation.",
                "results" => $results
            ]);

        } catch (Exception $e) {
            $conn->rollBack();
            echo json_encode([
                "success" => false, 
                "message" => "Error creating invoices: " . $e->getMessage()
            ]);
        }
    }

    function calculateComprehensiveBillingInternal($conn, $booking_id, $discount_id = null, $vat_rate = 0.0, $downpayment = 0)
    {
        try {
            // 1. Calculate room charges (fixed the room price query)
            $roomQuery = $conn->prepare("
                SELECT 
                    SUM(rt.roomtype_price) AS room_total,
                    COUNT(br.booking_room_id) AS room_count
                FROM tbl_booking_room br
                JOIN tbl_roomtype rt ON br.roomtype_id = rt.roomtype_id
                WHERE br.booking_id = :booking_id
            ");
            $roomQuery->bindParam(':booking_id', $booking_id);
            $roomQuery->execute();
            $roomData = $roomQuery->fetch(PDO::FETCH_ASSOC);
            $room_total = $roomData['room_total'] ?: 0;
            // Recompute room_total to account for number of nights per room
            try {
                $roomQueryNights = $conn->prepare("
                    SELECT 
                        SUM(rt.roomtype_price * GREATEST(DATEDIFF(b.booking_checkout_dateandtime, b.booking_checkin_dateandtime), 1)) AS room_total
                    FROM tbl_booking_room br
                    JOIN tbl_booking b ON br.booking_id = b.booking_id
                    JOIN tbl_rooms r ON br.roomnumber_id = r.roomnumber_id
                    JOIN tbl_roomtype rt ON r.roomtype_id = rt.roomtype_id
                    WHERE br.booking_id = :booking_id
                ");
                $roomQueryNights->bindParam(':booking_id', $booking_id);
                $roomQueryNights->execute();
                $room_total = $roomQueryNights->fetchColumn() ?: $room_total;
            } catch (Exception $_) {}

            // 2. Calculate additional charges (amenities, services, etc.)
            $chargesQuery = $conn->prepare("
                SELECT 
                    SUM(bc.booking_charges_price * bc.booking_charges_quantity) AS charge_total,
                    COUNT(bc.booking_charges_id) AS charge_count
                FROM tbl_booking_charges bc
                JOIN tbl_booking_room br ON bc.booking_room_id = br.booking_room_id
                WHERE br.booking_id = :booking_id
            ");
            $chargesQuery->bindParam(':booking_id', $booking_id);
            $chargesQuery->execute();
            $chargeData = $chargesQuery->fetch(PDO::FETCH_ASSOC);
            $charge_total = $chargeData['charge_total'] ?: 0;

            // 3. Calculate subtotal
            $subtotal = $room_total + $charge_total;

            // 4. Apply discount if provided
            $discount_amount = 0;
            if ($discount_id) {
                $discountQuery = $conn->prepare("
                    SELECT discounts_percentage, discounts_amount 
                    FROM tbl_discounts 
                    WHERE discounts_id = :discount_id
                ");
                $discountQuery->bindParam(':discount_id', $discount_id);
                $discountQuery->execute();
                $discount = $discountQuery->fetch(PDO::FETCH_ASSOC);
                
                if ($discount) {
                    if ($discount['discounts_percentage']) {
                        $discount_amount = $subtotal * ($discount['discounts_percentage'] / 100);
                    } else {
                        $discount_amount = $discount['discounts_amount'];
                    }
                }
            }

            // 5. Calculate amount after discount
            $amount_after_discount = $subtotal - $discount_amount;

            // 6. Calculate final total (VAT removed)
            $final_total = $amount_after_discount;

            // 7. Calculate balance after downpayment
            $balance = $final_total - $downpayment;

            return [
                "success" => true,
                "room_total" => $room_total,
                "charge_total" => $charge_total,
                "subtotal" => $subtotal,
                "discount_amount" => $discount_amount,
                "amount_after_discount" => $amount_after_discount,
                "final_total" => $final_total,
                "downpayment" => $downpayment,
                "balance" => $balance,
                "room_count" => $roomData['room_count'],
                "charge_count" => $chargeData['charge_count']
            ];

        } catch (Exception $e) {
            return [
                "success" => false,
                "message" => "Error calculating billing: " . $e->getMessage()
            ];
        }
    }

    function logBillingActivity($conn, $billing_id, $invoice_id, $employee_id, $activity_type, $data = null)
    {
        try {
            // Check if the table exists before trying to insert
            $checkTable = $conn->prepare("SHOW TABLES LIKE 'tbl_billing_activity_log'");
            $checkTable->execute();
            
            if ($checkTable->rowCount() > 0) {
                $stmt = $conn->prepare("
                    INSERT INTO tbl_billing_activity_log (
                        billing_id, invoice_id, employee_id, activity_type, 
                        activity_data, created_at
                    ) VALUES (
                        :billing_id, :invoice_id, :employee_id, :activity_type,
                        :activity_data, NOW()
                    )
                ");
                $stmt->bindParam(':billing_id', $billing_id);
                $stmt->bindParam(':invoice_id', $invoice_id);
                $stmt->bindParam(':employee_id', $employee_id);
                $stmt->bindParam(':activity_type', $activity_type);
                $stmt->bindParam(':activity_data', json_encode($data));
                $stmt->execute();
            }
        } catch (Exception $e) {
            // Log error but don't fail the main operation
            error_log("Failed to log billing activity: " . $e->getMessage());
        }
    }

    function validateBillingCompleteness($json)
    {
        include "connection.php";
        $json = json_decode($json, true);
        $booking_id = $json["booking_id"];

        try {
            // Check if there are any charges that need billing processing
            // This should check for charges that exist but aren't properly included in billing calculations
            $pendingChargesQuery = $conn->prepare("
                SELECT COUNT(*) as pending_count
                FROM tbl_booking_charges bc
                JOIN tbl_booking_room br ON bc.booking_room_id = br.booking_room_id
                WHERE br.booking_id = :booking_id 
                AND bc.booking_charge_status = 1
            ");
            $pendingChargesQuery->bindParam(':booking_id', $booking_id);
            $pendingChargesQuery->execute();
            $pendingCount = $pendingChargesQuery->fetchColumn();

            // Check if room charges are properly calculated
            $roomValidationQuery = $conn->prepare("
                SELECT COUNT(*) as room_count
                FROM tbl_booking_room br
                WHERE br.booking_id = :booking_id AND br.roomnumber_id IS NOT NULL
            ");
            $roomValidationQuery->bindParam(':booking_id', $booking_id);
            $roomValidationQuery->execute();
            $roomCount = $roomValidationQuery->fetchColumn();

            $result = [
                "success" => true,
                "pending_charges" => $pendingCount,
                "assigned_rooms" => $roomCount,
                "is_complete" => $pendingCount == 0, // Only check for truly pending charges (status = 1)
                "message" => $pendingCount > 0 ? 
                    "There are {$pendingCount} charges with pending status that need to be approved before billing." :
                    ($roomCount > 0 ? "Billing validation complete. All charges are ready for invoice creation." : "Billing validation complete. Note: No rooms assigned yet.")
            ];

            echo json_encode($result);

        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Error validating billing: " . $e->getMessage()
            ]);
        }
    }

    function calculateComprehensiveBilling($json)
    {
        include "connection.php";
        $json = json_decode($json, true);
        $booking_id = $json["booking_id"];
        $discount_id = $json["discount_id"] ?? null;
        $vat_rate = $json["vat_rate"] ?? 0.0;
        $downpayment = $json["downpayment"] ?? 0;

        // If no downpayment provided, get it from the booking
        if ($downpayment == 0) {
            $stmt = $conn->prepare("SELECT booking_downpayment FROM tbl_booking WHERE booking_id = :booking_id");
            $stmt->bindParam(':booking_id', $booking_id);
            $stmt->execute();
            $booking = $stmt->fetch(PDO::FETCH_ASSOC);
            $downpayment = $booking ? ($booking["booking_downpayment"] ?? 0) : 0;
        }

        $result = $this->calculateComprehensiveBillingInternal($conn, $booking_id, $discount_id, $vat_rate, $downpayment);
        echo json_encode($result);
    }

    function createBillingRecord($json)
    {
        include "connection.php";
        $json = json_decode($json, true);
        $booking_id = $json["booking_id"];
        $employee_id = isset($json["employee_id"]) ? intval($json["employee_id"]) : null;
        $payment_method_id = $json["payment_method_id"] ?? 2; // Default to Cash
        $discount_id = $json["discount_id"] ?? null;
        $vat_rate = $json["vat_rate"] ?? 0.12; // Default 12% VAT

        // Safeguards: require valid employee_id and booking_id; validate employee exists and is active
        if (empty($booking_id)) {
            echo json_encode(["success" => false, "message" => "Missing required field: booking_id"]);
            return;
        }
        if (empty($employee_id) || $employee_id <= 0) {
            echo json_encode(["success" => false, "message" => "Missing or invalid employee_id"]);
            return;
        }
        $empStmt = $conn->prepare("SELECT employee_status FROM tbl_employee WHERE employee_id = :employee_id");
        $empStmt->bindParam(':employee_id', $employee_id, PDO::PARAM_INT);
        $empStmt->execute();
        $empRow = $empStmt->fetch(PDO::FETCH_ASSOC);
        if (!$empRow) {
            echo json_encode(["success" => false, "message" => "Employee not found"]);
            return;
        }
        $status = $empRow["employee_status"];
        if ($status === 'Inactive' || $status === 'Disabled' || $status === '0' || $status === 0) {
            echo json_encode(["success" => false, "message" => "Employee is not active"]);
            return;
        }

        try {
            // Check if billing already exists
            $checkStmt = $conn->prepare("SELECT billing_id FROM tbl_billing WHERE booking_id = :booking_id");
            $checkStmt->bindParam(':booking_id', $booking_id);
            $checkStmt->execute();
            
            if ($checkStmt->fetch()) {
                echo json_encode(["success" => true, "message" => "Billing record already exists"]);
                return;
            }

            // 1. Get booking information
            $bookingQuery = $conn->prepare("
                SELECT booking_downpayment, booking_totalAmount, reference_no 
                FROM tbl_booking 
                WHERE booking_id = :booking_id
            ");
            $bookingQuery->bindParam(':booking_id', $booking_id);
            $bookingQuery->execute();
            $booking = $bookingQuery->fetch(PDO::FETCH_ASSOC);
            
            if (!$booking) {
                echo json_encode(["success" => false, "message" => "Booking not found"]);
                return;
            }

            // 2. Calculate room charges
            $roomQuery = $conn->prepare("
                SELECT SUM(rt.roomtype_price * GREATEST(DATEDIFF(b.booking_checkout_dateandtime, b.booking_checkin_dateandtime), 1)) AS room_total
                FROM tbl_booking_room br
                JOIN tbl_booking b ON br.booking_id = b.booking_id
                JOIN tbl_rooms r ON br.roomnumber_id = r.roomnumber_id
                JOIN tbl_roomtype rt ON r.roomtype_id = rt.roomtype_id
                WHERE br.booking_id = :booking_id
            ");
            $roomQuery->bindParam(':booking_id', $booking_id);
            $roomQuery->execute();
            $room_total = $roomQuery->fetchColumn() ?: 0;

            // 3. Calculate additional charges (approved charges only)
            $chargesQuery = $conn->prepare("
                SELECT SUM(bc.booking_charges_price * bc.booking_charges_quantity) AS charge_total
                FROM tbl_booking_charges bc
                JOIN tbl_booking_room br ON bc.booking_room_id = br.booking_room_id
                WHERE br.booking_id = :booking_id
                AND bc.booking_charge_status = 2
            ");
            $chargesQuery->bindParam(':booking_id', $booking_id);
            $chargesQuery->execute();
            $charge_total = $chargesQuery->fetchColumn() ?: 0;

            // 4. Calculate subtotal
            $subtotal = $room_total + $charge_total;

            // 5. Apply discount if provided
            $discount_amount = 0;
            if ($discount_id) {
                $discountQuery = $conn->prepare("
                    SELECT discounts_percentage, discounts_amount 
                    FROM tbl_discounts 
                    WHERE discounts_id = :discount_id
                ");
                $discountQuery->bindParam(':discount_id', $discount_id);
                $discountQuery->execute();
                $discount = $discountQuery->fetch(PDO::FETCH_ASSOC);
                
                if ($discount) {
                    if ($discount['discounts_percentage']) {
                        $discount_amount = $subtotal * ($discount['discounts_percentage'] / 100);
                    } else {
                        $discount_amount = $discount['discounts_amount'];
                    }
                }
            }

            // 6. Calculate amount after discount
            $amount_after_discount = $subtotal - $discount_amount;

            // 7. VAT removed; final total equals amount after discount
            $final_total = $amount_after_discount;
            $vat_amount = 0;

            // 9. Get booking downpayment
            $downpayment = $booking['booking_downpayment'] ?? 0;

            // 10. Calculate balance
            $balance = $final_total - $downpayment;

            // 11. Generate invoice number
            $invoice_number = 'BILL' . date('YmdHis') . rand(100, 999);

            // 12. Create comprehensive billing record
            $stmt = $conn->prepare("
                INSERT INTO tbl_billing (
                    booking_id, employee_id, payment_method_id, discounts_id,
                    billing_dateandtime, billing_invoice_number, billing_downpayment, 
                    billing_vat, billing_total_amount, billing_balance
                ) VALUES (
                    :booking_id, :employee_id, :payment_method_id, :discount_id,
                    NOW(), :invoice_number, :downpayment, 
                    :vat_amount, :total_amount, :balance
                )
            ");
            
            $stmt->bindParam(':booking_id', $booking_id);
            $stmt->bindParam(':employee_id', $employee_id);
            $stmt->bindParam(':payment_method_id', $payment_method_id);
            $stmt->bindParam(':discount_id', $discount_id);
            $stmt->bindParam(':invoice_number', $invoice_number);
            $stmt->bindParam(':downpayment', $downpayment);
            $stmt->bindParam(':vat_amount', $vat_amount);
            $stmt->bindParam(':total_amount', $final_total);
            $stmt->bindParam(':balance', $balance);
            
            if ($stmt->execute()) {
                $billing_id = $conn->lastInsertId();
                
                // Log the billing creation activity
                $activityQuery = $conn->prepare("
                    INSERT INTO tbl_activitylogs (
                        user_type, user_id, user_name, action_type, action_category, 
                        action_description, target_table, target_id, new_values, 
                        status, created_at
                    ) VALUES (
                        'admin', :employee_id, 'System', 'create', 'billing',
                        'Comprehensive billing record created with full calculations',
                        'tbl_billing', :billing_id, :new_values, 'success', NOW()
                    )
                ");
                
                $new_values = json_encode([
                    'billing_id' => $billing_id,
                    'booking_id' => $booking_id,
                    'total_amount' => $final_total,
                    'downpayment' => $downpayment,
                    'balance' => $balance
                ]);
                
                $activityQuery->bindParam(':employee_id', $employee_id);
                $activityQuery->bindParam(':billing_id', $billing_id);
                $activityQuery->bindParam(':new_values', $new_values);
                $activityQuery->execute();
                
                echo json_encode([
                    "success" => true, 
                    "message" => "Comprehensive billing record created successfully",
                    "billing_id" => $billing_id,
                    "invoice_number" => $invoice_number,
                    "total_amount" => $final_total,
                    "downpayment" => $downpayment,
                    "balance" => $balance
                ]);
            } else {
                echo json_encode(["success" => false, "message" => "Failed to create billing record"]);
            }
            
        } catch (Exception $e) {
            echo json_encode(["success" => false, "message" => "Error: " . $e->getMessage()]);
        }
    }

    function getBookingBillingId($json)
    {
        include "connection.php";
        $json = json_decode($json, true);
        $booking_id = $json["booking_id"];

        try {
            $stmt = $conn->prepare("SELECT billing_id FROM tbl_billing WHERE booking_id = :booking_id ORDER BY billing_id DESC LIMIT 1");
            $stmt->bindParam(':booking_id', $booking_id);
            $stmt->execute();
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                echo json_encode([
                    "success" => true,
                    "billing_id" => $result['billing_id']
                ]);
            } else {
                echo json_encode([
                    "success" => false,
                    "message" => "No billing record found for this booking"
                ]);
            }
        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Error: " . $e->getMessage()
            ]);
        }
    }

    function getBookingCharges($json)
    {
        include "connection.php";
        $json = json_decode($json, true);
        $booking_id = $json["booking_id"];

        try {
            // Get room charges
            $roomQuery = $conn->prepare("
                SELECT 
                    'Room Charges' as charge_type,
                    rt.roomtype_name as charge_name,
                    'Room' as category,
                    r.roomnumber_id as room_number,
                    rt.roomtype_name as room_type,
                    rt.roomtype_price as unit_price,
                    GREATEST(DATEDIFF(b.booking_checkout_dateandtime, b.booking_checkin_dateandtime), 1) as quantity,
                    (rt.roomtype_price * GREATEST(DATEDIFF(b.booking_checkout_dateandtime, b.booking_checkin_dateandtime), 1)) as total_amount,
                    rt.roomtype_description as charges_master_description
                FROM tbl_booking_room br
                JOIN tbl_booking b ON br.booking_id = b.booking_id
                JOIN tbl_rooms r ON br.roomnumber_id = r.roomnumber_id
                JOIN tbl_roomtype rt ON r.roomtype_id = rt.roomtype_id
                WHERE br.booking_id = :booking_id
            ");
            $roomQuery->bindParam(':booking_id', $booking_id);
            $roomQuery->execute();
            $roomCharges = $roomQuery->fetchAll(PDO::FETCH_ASSOC);

            // Get additional charges
            $chargesQuery = $conn->prepare("
                SELECT 
                    'Additional Charges' as charge_type,
                    cm.charges_master_name as charge_name,
                    cc.charges_category_name as category,
                    r.roomnumber_id as room_number,
                    rt.roomtype_name as room_type,
                    bc.booking_charges_price as unit_price,
                    bc.booking_charges_quantity as quantity,
                    (bc.booking_charges_price * bc.booking_charges_quantity) as total_amount,
                    cm.charges_master_description as charges_master_description
                FROM tbl_booking_charges bc
                JOIN tbl_booking_room br ON bc.booking_room_id = br.booking_room_id
                JOIN tbl_rooms r ON br.roomnumber_id = r.roomnumber_id
                JOIN tbl_roomtype rt ON r.roomtype_id = rt.roomtype_id
                JOIN tbl_charges_master cm ON bc.charges_master_id = cm.charges_master_id
                JOIN tbl_charges_category cc ON cm.charges_category_id = cc.charges_category_id
                WHERE br.booking_id = :booking_id
                AND bc.booking_charge_status = 2
            ");
            $chargesQuery->bindParam(':booking_id', $booking_id);
            $chargesQuery->execute();
            $additionalCharges = $chargesQuery->fetchAll(PDO::FETCH_ASSOC);

            // Combine all charges
            $allCharges = array_merge($roomCharges, $additionalCharges);

            echo json_encode([
                "success" => true,
                "booking_id" => $booking_id,
                "charges" => $allCharges,
                "room_charges_count" => count($roomCharges),
                "additional_charges_count" => count($additionalCharges),
                "total_charges_count" => count($allCharges)
            ]);

        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Error fetching charges: " . $e->getMessage()
            ]);
        }
    }

    function addBookingCharge($json)
    {
        include "connection.php";
        $json = json_decode($json, true);
        $booking_id = $json["booking_id"];
        $charge_name = $json["charge_name"];
        $charge_price = $json["charge_price"];
        $quantity = $json["quantity"] ?? 1;
        $category_id = $json["category_id"] ?? 4; // Default to "Additional Services"
        $employee_id = isset($json["employee_id"]) ? intval($json["employee_id"]) : null;

        if (empty($employee_id) || $employee_id <= 0) {
            echo json_encode(["success" => false, "message" => "Missing or invalid employee_id"]);
            return;
        }
        $empStmt = $conn->prepare("SELECT employee_status FROM tbl_employee WHERE employee_id = :employee_id");
        $empStmt->bindParam(':employee_id', $employee_id, PDO::PARAM_INT);
        $empStmt->execute();
        $empRow = $empStmt->fetch(PDO::FETCH_ASSOC);
        if (!$empRow || $empRow["employee_status"] == 0 || $empRow["employee_status"] === 'Inactive' || $empRow["employee_status"] === 'Disabled') {
            echo json_encode(["success" => false, "message" => "Employee is not active"]);
            return;
        }

        try {
            // Get the first booking room for this booking
            $roomQuery = $conn->prepare("SELECT booking_room_id FROM tbl_booking_room WHERE booking_id = :booking_id LIMIT 1");
            $roomQuery->bindParam(':booking_room_id', $booking_id);
            $roomQuery->execute();
            $roomData = $roomQuery->fetch(PDO::FETCH_ASSOC);

            if (!$roomData) {
                echo json_encode(["success" => false, "message" => "No room assigned to this booking"]);
                return;
            }

            $booking_room_id = $roomData['booking_room_id'];

            // Check if charge already exists in master table
            $checkCharge = $conn->prepare("SELECT charges_master_id FROM tbl_charges_master WHERE charges_master_name = :charge_name");
            $checkCharge->bindParam(':charge_name', $charge_name);
            $checkCharge->execute();

            $charges_master_id = null;
            if ($checkCharge->rowCount() > 0) {
                $charges_master_id = $checkCharge->fetchColumn();
            } else {
                // Create new charge in master table
                $createCharge = $conn->prepare("
                    INSERT INTO tbl_charges_master (charges_category_id, charges_master_name, charges_master_price, charges_master_description)
                    VALUES (:category_id, :charge_name, :charge_price, 'Added by frontdesk')
                ");
                $createCharge->bindParam(':category_id', $category_id);
                $createCharge->bindParam(':charge_name', $charge_name);
                $createCharge->bindParam(':charge_price', $charge_price);
                $createCharge->execute();
                $charges_master_id = $conn->lastInsertId();
            }

            // Add charge to booking
            $addCharge = $conn->prepare("
                INSERT INTO tbl_booking_charges (booking_room_id, charges_master_id, booking_charges_price, booking_charges_quantity, 
                booking_charge_status)
                VALUES (:booking_room_id, :charges_master_id, :charge_price, :quantity, 2)
            ");
            $addCharge->bindParam(':booking_room_id', $booking_room_id);
            $addCharge->bindParam(':charges_master_id', $charges_master_id);
            $addCharge->bindParam(':charge_price', $charge_price);
            $addCharge->bindParam(':quantity', $quantity);
            $addCharge->execute();

            echo json_encode([
                "success" => true,
                "message" => "Charge added successfully",
                "charge_id" => $conn->lastInsertId()
            ]);

        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Error adding charge: " . $e->getMessage()
            ]);
        }
    }

    function getBookingsWithBillingStatus()
    {
        include "connection.php";

        $query = "
        SELECT DISTINCT
            b.booking_id,
            b.reference_no,
            b.booking_checkin_dateandtime,
            b.booking_checkout_dateandtime,
            CONCAT(c.customers_fname, ' ', c.customers_lname) AS customer_name,
            bi.billing_id,
            i.invoice_id,
            i.invoice_status_id,
            CASE 
                WHEN i.invoice_status_id = 1 THEN 'Checked-Out'
                ELSE COALESCE(bs.booking_status_name, 'Pending')
            END AS booking_status
        FROM tbl_booking b
        LEFT JOIN tbl_customers c ON b.customers_id = c.customers_id
        LEFT JOIN tbl_billing bi ON b.booking_id = bi.booking_id
        LEFT JOIN tbl_invoice i ON bi.billing_id = i.billing_id
        LEFT JOIN (
            SELECT bh1.booking_id, bs.booking_status_name
            FROM tbl_booking_history bh1
            INNER JOIN (
                SELECT booking_id, MAX(booking_history_id) AS latest_history_id
                FROM tbl_booking_history
                GROUP BY booking_id
            ) latest ON latest.booking_id = bh1.booking_id AND latest.latest_history_id = bh1.booking_history_id
            INNER JOIN tbl_booking_status bs ON bh1.status_id = bs.booking_status_id
        ) bs ON bs.booking_id = b.booking_id
        WHERE (
            bs.booking_status_name = 'Checked-In' 
            OR i.invoice_status_id = 1
        )
        ORDER BY b.booking_created_at DESC
    ";

        $stmt = $conn->prepare($query);
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode($results);
    }

    // NEW API: Enhanced booking list with real-time balance calculation for transactions
    function getBookingsWithBillingStatusEnhanced()
    {
        include "connection.php";

        $query = "
        SELECT DISTINCT
            b.booking_id,
            b.reference_no,
            b.booking_checkin_dateandtime,
            b.booking_checkout_dateandtime,
            b.booking_created_at,
            -- Customer info
            CONCAT(c.customers_fname, ' ', c.customers_lname) AS customer_name,
            c.customers_email AS customer_email,
            c.customers_phone AS customer_phone,
            -- Enhanced balance calculation
            CASE 
                WHEN latest_billing.billing_id IS NOT NULL THEN
                    -- Use billing data if available
                    CASE 
                        WHEN latest_invoice.invoice_status_id = 1 THEN 0  -- Invoice complete = fully paid
                        ELSE COALESCE(latest_billing.billing_balance, 0)
                    END
                ELSE 
                    -- No billing record, calculate from booking data
                    COALESCE(b.booking_totalAmount, 0) - COALESCE(b.booking_downpayment, 0)
            END AS balance,
            -- Enhanced amounts
            CASE 
                WHEN latest_billing.billing_id IS NOT NULL THEN
                    COALESCE(latest_billing.billing_total_amount, b.booking_totalAmount)
                ELSE b.booking_totalAmount
            END AS total_amount,
            CASE 
                WHEN latest_billing.billing_id IS NOT NULL THEN
                    COALESCE(latest_billing.billing_downpayment, b.booking_downpayment)
                ELSE b.booking_downpayment
            END AS downpayment,
            
            -- Status and billing info
            CASE 
                WHEN latest_invoice.invoice_status_id = 1 THEN 'Checked-Out'
                ELSE COALESCE(bs.booking_status_name, 'Pending')
            END AS booking_status,
            latest_billing.billing_id,
            latest_invoice.invoice_id,
            latest_invoice.invoice_status_id,
            CASE 
                WHEN latest_invoice.invoice_status_id = 1 THEN 'Complete'
                WHEN latest_invoice.invoice_status_id = 2 THEN 'Incomplete'
                WHEN latest_billing.billing_id IS NOT NULL THEN 'Billed'
                ELSE 'Not Billed'
            END AS billing_status
        FROM tbl_booking b
        LEFT JOIN tbl_customers c ON b.customers_id = c.customers_id
        LEFT JOIN (
            SELECT bh1.booking_id, bs.booking_status_name
            FROM tbl_booking_history bh1
            INNER JOIN (
                SELECT booking_id, MAX(booking_history_id) AS latest_history_id
                FROM tbl_booking_history
                GROUP BY booking_id
            ) latest ON latest.booking_id = bh1.booking_id AND latest.latest_history_id = bh1.booking_history_id
            INNER JOIN tbl_booking_status bs ON bh1.status_id = bs.booking_status_id
        ) bs ON bs.booking_id = b.booking_id
        LEFT JOIN (
            -- Get the latest billing record for each booking
            SELECT 
                bi.booking_id,
                bi.billing_id,
                bi.billing_total_amount,
                bi.billing_downpayment,
                bi.billing_balance,
                bi.billing_vat,
                bi.billing_dateandtime
            FROM tbl_billing bi
            INNER JOIN (
                SELECT booking_id, MAX(billing_id) as max_billing_id
                FROM tbl_billing
                GROUP BY booking_id
            ) latest ON latest.booking_id = bi.booking_id AND latest.max_billing_id = bi.billing_id
        ) latest_billing ON latest_billing.booking_id = b.booking_id
        LEFT JOIN (
            -- Get the latest invoice for each billing record
            SELECT 
                i.billing_id,
                i.invoice_id,
                i.invoice_status_id,
                i.invoice_total_amount,
                i.invoice_date,
                i.invoice_time
            FROM tbl_invoice i
            INNER JOIN (
                SELECT billing_id, MAX(invoice_id) as max_invoice_id
                FROM tbl_invoice
                GROUP BY billing_id
            ) latest_inv ON latest_inv.billing_id = i.billing_id AND latest_inv.max_invoice_id = i.invoice_id
        ) latest_invoice ON latest_invoice.billing_id = latest_billing.billing_id
        WHERE (
            bs.booking_status_name = 'Checked-In' 
            OR latest_invoice.invoice_status_id = 1
        )
        ORDER BY b.booking_created_at DESC
    ";

        $stmt = $conn->prepare($query);
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode($results);
    }

    function getBookingInvoice($json)
    {
        include "connection.php";
        $json = json_decode($json, true);
        $booking_id = $json["booking_id"];

        // Get invoice details
        $query = "
        SELECT 
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
        LIMIT 1
    ";

        $stmt = $conn->prepare($query);
        $stmt->bindParam(":booking_id", $booking_id, PDO::PARAM_INT);
        $stmt->execute();
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($invoice) {
            echo json_encode($invoice);
        } else {
            echo json_encode(["error" => "Invoice not found."]);
        }
    }

    function getDetailedBookingCharges($json)
    {
        include "connection.php";
        $json = json_decode($json, true);
        $booking_id = $json["booking_id"];

        try {
            // Get room charges
            $roomQuery = $conn->prepare("
                SELECT 
                    'Room Charges' as charge_type,
                    rt.roomtype_name as charge_name,
                    'Room' as category,
                    r.roomnumber_id as room_number,
                    rt.roomtype_name as room_type,
                    rt.roomtype_price as unit_price,
                    GREATEST(DATEDIFF(b.booking_checkout_dateandtime, b.booking_checkin_dateandtime), 1) as quantity,
                    (rt.roomtype_price * GREATEST(DATEDIFF(b.booking_checkout_dateandtime, b.booking_checkin_dateandtime), 1)) as total_amount,
                    rt.roomtype_description as charges_master_description
                FROM tbl_booking_room br
                JOIN tbl_booking b ON br.booking_id = b.booking_id
                JOIN tbl_rooms r ON br.roomnumber_id = r.roomnumber_id
                JOIN tbl_roomtype rt ON r.roomtype_id = rt.roomtype_id
                WHERE br.booking_id = :booking_id
            ");
            $roomQuery->bindParam(':booking_id', $booking_id);
            $roomQuery->execute();
            $roomCharges = $roomQuery->fetchAll(PDO::FETCH_ASSOC);

            // Get additional charges
            $chargesQuery = $conn->prepare("
                SELECT 
                    'Additional Charges' as charge_type,
                    cm.charges_master_name as charge_name,
                    cc.charges_category_name as category,
                    r.roomnumber_id as room_number,
                    rt.roomtype_name as room_type,
                    bc.booking_charges_price as unit_price,
                    bc.booking_charges_quantity as quantity,
                    (bc.booking_charges_price * bc.booking_charges_quantity) as total_amount,
                    cm.charges_master_description as charges_master_description
                FROM tbl_booking_charges bc
                JOIN tbl_booking_room br ON bc.booking_room_id = br.booking_room_id
                JOIN tbl_rooms r ON br.roomnumber_id = r.roomnumber_id
                JOIN tbl_roomtype rt ON r.roomtype_id = rt.roomtype_id
                JOIN tbl_charges_master cm ON bc.charges_master_id = cm.charges_master_id
                JOIN tbl_charges_category cc ON cm.charges_category_id = cc.charges_category_id
                WHERE br.booking_id = :booking_id
                AND bc.booking_charge_status = 2
            ");
            $chargesQuery->bindParam(':booking_id', $booking_id);
            $chargesQuery->execute();
            $additionalCharges = $chargesQuery->fetchAll(PDO::FETCH_ASSOC);

            // Calculate totals
            $room_total = array_sum(array_column($roomCharges, 'total_amount'));
            $charges_total = array_sum(array_column($additionalCharges, 'total_amount'));
            $subtotal = $room_total + $charges_total;
            $grand_total = $subtotal;

            // Get booking downpayment
            $bookingQuery = $conn->prepare("SELECT booking_downpayment FROM tbl_booking WHERE booking_id = :booking_id");
            $bookingQuery->bindParam(':booking_id', $booking_id);
            $bookingQuery->execute();
            $booking = $bookingQuery->fetch(PDO::FETCH_ASSOC);
            $downpayment = $booking ? ($booking["booking_downpayment"] ?? 0) : 0;
            $balance = $grand_total - $downpayment;

            $result = [
                "success" => true,
                "room_charges" => $roomCharges,
                "additional_charges" => $additionalCharges,
                "summary" => [
                    "room_total" => $room_total,
                    "charges_total" => $charges_total,
                    "subtotal" => $subtotal,
                    "grand_total" => $grand_total,
                    "downpayment" => $downpayment,
                    "balance" => $balance
                ]
            ];

            echo json_encode($result);

        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Error getting detailed charges: " . $e->getMessage()
            ]);
        }
    }
}

$json = isset($_POST['json']) ? $_POST['json'] : 0;
$operation = isset($_POST['operation']) ? $_POST['operation'] : 0;
$transactions = new Transactions();

switch ($operation) {
    case 'bookingList':
        $transactions->bookingList();
        break;
    case 'finalizeBookingApproval':
        $transactions->finalizeBookingApproval($json);
        break;
    case 'getVacantRoomsByBooking':
        $transactions->getVacantRoomsByBooking($json);
        break;
    case "chargesMasterList":
        $transactions->chargesMasterList();
        break;
    case "bookingChargesList":
        $transactions->bookingChargesList();
        break;
    case "addChargesAmenities":
        $transactions->addChargesAmenities();
        break;
    case "getChargesCategory":
        $transactions->getChargesCategory();
        break;
    case "chargesCategoryList":
        $transactions->getChargesCategory();
        break;
    case "saveAmenitiesCharges":
        $transactions->saveAmenitiesCharges();
        break;
    case "updateAmenityCharges":
        $transactions->updateAmenityCharges();
        break;
    case "createInvoice":
        $transactions->createInvoice($json);
        break;
    case "getBookingsWithBillingStatus":
        $transactions->getBookingsWithBillingStatus();
        break;
    case "getBookingsWithBillingStatusEnhanced":
        $transactions->getBookingsWithBillingStatusEnhanced();
        break;
    case "getBookingInvoice":
        $transactions->getBookingInvoice($json);
        break;
    case "validateBillingCompleteness":
        $transactions->validateBillingCompleteness($json);
        break;
    case "calculateComprehensiveBilling":
        $transactions->calculateComprehensiveBilling($json);
        break;
    case "createBillingRecord":
        $transactions->createBillingRecord($json);
        break;
    case "getBookingCharges":
        $transactions->getBookingCharges($json);
        break;
    case "addBookingCharge":
        $transactions->addBookingCharge($json);
        break;
    case "getDetailedBookingCharges":
        $transactions->getDetailedBookingCharges($json);
        break;
    case "getBookingBillingId":
        $transactions->getBookingBillingId($json);
        break;
    default:
        echo "Invalid Operation";
        break;
}