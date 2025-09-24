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
        $billing_ids = $json["billing_ids"];
        $employee_id = $json["employee_id"];
        $payment_method_id = $json["payment_method_id"];
        $invoice_status_id = $json["invoice_status_id"] ?? 1;
        $discount_id = $json["discount_id"] ?? null;
        $vat_rate = $json["vat_rate"] ?? 0.12; // Default 12% VAT
        $downpayment = $json["downpayment"] ?? 0;

        $invoice_date = date("Y-m-d");
        $invoice_time = date("H:i:s");
        $results = [];

        try {
            $conn->beginTransaction();

        foreach ($billing_ids as $billing_id) {
            // 1. Get the booking_id linked to this billing_id
            $stmt = $conn->prepare("SELECT booking_id FROM tbl_billing WHERE billing_id = :billing_id");
            $stmt->bindParam(':billing_id', $billing_id);
            $stmt->execute();
            $billingRow = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$billingRow) {
                    $results[] = ["billing_id" => $billing_id, "status" => "error", "message" => "Billing not found"];
                    continue;
                }

            $booking_id = $billingRow["booking_id"];

                // 2. Calculate comprehensive billing breakdown
                $billingBreakdown = $this->calculateComprehensiveBillingInternal($conn, $booking_id, $discount_id, $vat_rate, $downpayment);

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
                $updateBilling->bindParam(':downpayment', $downpayment);
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

    function calculateComprehensiveBillingInternal($conn, $booking_id, $discount_id = null, $vat_rate = 0.12, $downpayment = 0)
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

            // 6. Calculate VAT
            $vat_amount = $amount_after_discount * $vat_rate;

            // 7. Calculate final total
            $final_total = $amount_after_discount + $vat_amount;

            // 8. Calculate balance after downpayment
            $balance = $final_total - $downpayment;

            return [
                "success" => true,
                "room_total" => $room_total,
                "charge_total" => $charge_total,
                "subtotal" => $subtotal,
                "discount_amount" => $discount_amount,
                "amount_after_discount" => $amount_after_discount,
                "vat_amount" => $vat_amount,
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
            // Check if there are any pending charges not yet billed
            // Only consider charges as "pending" if there's no billing record at all
            $pendingChargesQuery = $conn->prepare("
                SELECT COUNT(*) as pending_count
                FROM tbl_booking_charges bc
                JOIN tbl_booking_room br ON bc.booking_room_id = br.booking_room_id
                LEFT JOIN tbl_billing b ON br.booking_id = b.booking_id
                WHERE br.booking_id = :booking_id 
                AND b.billing_id IS NULL
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
                "is_complete" => $pendingCount == 0, // Only check for pending charges, not room assignments
                "message" => $pendingCount > 0 ? 
                    "There are {$pendingCount} pending charges that need to be included in billing." :
                    ($roomCount > 0 ? "Billing validation complete." : "Billing validation complete. Note: No rooms assigned yet.")
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
        $vat_rate = $json["vat_rate"] ?? 0.12;
        $downpayment = $json["downpayment"] ?? 0;

        $result = $this->calculateComprehensiveBillingInternal($conn, $booking_id, $discount_id, $vat_rate, $downpayment);
        echo json_encode($result);
    }

    function createBillingRecord($json)
    {
        include "connection.php";
        $json = json_decode($json, true);
        $booking_id = $json["booking_id"];
        $employee_id = $json["employee_id"] ?? 1;

        try {
            // Check if billing already exists
            $checkStmt = $conn->prepare("SELECT billing_id FROM tbl_billing WHERE booking_id = :booking_id");
            $checkStmt->bindParam(':booking_id', $booking_id);
            $checkStmt->execute();
            
            if ($checkStmt->fetch()) {
                echo json_encode(["success" => true, "message" => "Billing record already exists"]);
                return;
            }

            // Create billing record
            $stmt = $conn->prepare("
                INSERT INTO tbl_billing (
                    booking_id, employee_id, billing_dateandtime, 
                    billing_invoice_number, billing_total_amount, billing_balance
                ) VALUES (
                    :booking_id, :employee_id, NOW(), 
                    :invoice_number, 0, 0
                )
            ");
            
            $invoice_number = 'REF' . date('YmdHis') . rand(100, 999);
            
            $stmt->bindParam(':booking_id', $booking_id);
            $stmt->bindParam(':employee_id', $employee_id);
            $stmt->bindParam(':invoice_number', $invoice_number);
            
            if ($stmt->execute()) {
                echo json_encode(["success" => true, "message" => "Billing record created successfully"]);
            } else {
                echo json_encode(["success" => false, "message" => "Failed to create billing record"]);
            }
            
        } catch (Exception $e) {
            echo json_encode(["success" => false, "message" => "Error: " . $e->getMessage()]);
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
                    'Room Charges' AS charge_type,
                    rt.roomtype_name AS charge_name,
                    rt.roomtype_price AS unit_price,
                    1 AS quantity,
                    rt.roomtype_price AS total_amount,
                    br.booking_room_id,
                    'Room' AS category
                FROM tbl_booking_room br
                JOIN tbl_roomtype rt ON br.roomtype_id = rt.roomtype_id
                WHERE br.booking_id = :booking_id
            ");
            $roomQuery->bindParam(':booking_id', $booking_id);
            $roomQuery->execute();
            $roomCharges = $roomQuery->fetchAll(PDO::FETCH_ASSOC);

            // Get additional charges (amenities, services, damages)
            $chargesQuery = $conn->prepare("
                SELECT 
                    'Additional Charges' AS charge_type,
                    cm.charges_master_name AS charge_name,
                    bc.booking_charges_price AS unit_price,
                    bc.booking_charges_quantity AS quantity,
                    (bc.booking_charges_price * bc.booking_charges_quantity) AS total_amount,
                    bc.booking_room_id,
                    cc.charges_category_name AS category,
                    bc.booking_charges_id
                FROM tbl_booking_charges bc
                JOIN tbl_charges_master cm ON bc.charges_master_id = cm.charges_master_id
                JOIN tbl_charges_category cc ON cm.charges_category_id = cc.charges_category_id
                JOIN tbl_booking_room br ON bc.booking_room_id = br.booking_room_id
                WHERE br.booking_id = :booking_id
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
        $employee_id = $json["employee_id"] ?? 1;

        try {
            // Get the first booking room for this booking
            $roomQuery = $conn->prepare("SELECT booking_room_id FROM tbl_booking_room WHERE booking_id = :booking_id LIMIT 1");
            $roomQuery->bindParam(':booking_id', $booking_id);
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
                INSERT INTO tbl_booking_charges (booking_room_id, charges_master_id, booking_charges_price, booking_charges_quantity)
                VALUES (:booking_room_id, :charges_master_id, :charge_price, :quantity)
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

    function getDetailedBookingCharges($json)
    {
        include "connection.php";
        $json = json_decode($json, true);
        $booking_id = $json["booking_id"];

        try {
            // Get detailed room charges with room information
            $roomQuery = $conn->prepare("
                SELECT 
                    'Room Charges' AS charge_type,
                    br.booking_room_id,
                    rt.roomtype_name AS charge_name,
                    rt.roomtype_price AS unit_price,
                    1 AS quantity,
                    rt.roomtype_price AS total_amount,
                    rt.roomtype_description,
                    rt.max_capacity,
                    rt.roomtype_beds,
                    rt.roomtype_sizes,
                    br.bookingRoom_adult,
                    br.bookingRoom_children,
                    'Room' AS category,
                    r.roomnumber_id,
                    r.roomnumber_name
                FROM tbl_booking_room br
                JOIN tbl_roomtype rt ON br.roomtype_id = rt.roomtype_id
                LEFT JOIN tbl_rooms r ON br.roomnumber_id = r.roomnumber_id
                WHERE br.booking_id = :booking_id
            ");
            $roomQuery->bindParam(':booking_id', $booking_id);
            $roomQuery->execute();
            $roomCharges = $roomQuery->fetchAll(PDO::FETCH_ASSOC);

            // Get detailed additional charges
            $chargesQuery = $conn->prepare("
                SELECT 
                    'Additional Charges' AS charge_type,
                    bc.booking_charges_id,
                    bc.booking_room_id,
                    cm.charges_master_name AS charge_name,
                    bc.booking_charges_price AS unit_price,
                    bc.booking_charges_quantity AS quantity,
                    (bc.booking_charges_price * bc.booking_charges_quantity) AS total_amount,
                    cc.charges_category_name AS category,
                    cm.charges_master_description,
                    rt.roomtype_name AS room_type,
                    r.roomnumber_name AS room_number
                FROM tbl_booking_charges bc
                JOIN tbl_charges_master cm ON bc.charges_master_id = cm.charges_master_id
                JOIN tbl_charges_category cc ON cm.charges_category_id = cc.charges_category_id
                JOIN tbl_booking_room br ON bc.booking_room_id = br.booking_room_id
                JOIN tbl_roomtype rt ON br.roomtype_id = rt.roomtype_id
                LEFT JOIN tbl_rooms r ON br.roomnumber_id = r.roomnumber_id
                WHERE br.booking_id = :booking_id
                ORDER BY bc.booking_charges_id
            ");
            $chargesQuery->bindParam(':booking_id', $booking_id);
            $chargesQuery->execute();
            $additionalCharges = $chargesQuery->fetchAll(PDO::FETCH_ASSOC);

            // Calculate totals
            $room_total = array_sum(array_column($roomCharges, 'total_amount'));
            $charges_total = array_sum(array_column($additionalCharges, 'total_amount'));
            $grand_total = $room_total + $charges_total;

            echo json_encode([
                "success" => true,
                "booking_id" => $booking_id,
                "room_charges" => $roomCharges,
                "additional_charges" => $additionalCharges,
                "summary" => [
                    "room_total" => $room_total,
                    "charges_total" => $charges_total,
                    "grand_total" => $grand_total,
                    "room_count" => count($roomCharges),
                    "charges_count" => count($additionalCharges)
                ]
            ]);

        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "message" => "Error fetching detailed charges: " . $e->getMessage()
            ]);
        }
    }

    function getBookingsWithBillingStatus()
    {
        include "connection.php";

        $query = "
        SELECT 
            b.booking_id,
            b.reference_no,
            b.booking_checkin_dateandtime,
            b.booking_checkout_dateandtime,
            CASE 
                WHEN c.customers_id IS NOT NULL THEN CONCAT(c.customers_fname, ' ', c.customers_lname)
                WHEN w.customers_walk_in_id IS NOT NULL THEN CONCAT(w.customers_walk_in_fname, ' ', w.customers_walk_in_lname)
                ELSE 'Unknown Customer'
            END AS customer_name,
            CASE 
                WHEN c.customers_id IS NOT NULL THEN 'Online'
                WHEN w.customers_walk_in_id IS NOT NULL THEN 'Walk-in'
                ELSE 'Unknown'
            END AS customer_type,
            bi.billing_id,
            i.invoice_id,
            i.invoice_status_id
        FROM tbl_booking b
        LEFT JOIN tbl_customers c ON b.customers_id = c.customers_id
        LEFT JOIN tbl_customers_walk_in w ON b.customers_walk_in_id = w.customers_walk_in_id
        LEFT JOIN tbl_billing bi ON b.booking_id = bi.booking_id
        LEFT JOIN tbl_invoice i ON bi.billing_id = i.billing_id
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
    default:
        echo "Invalid Operation";
        break;
}
//pustahanay pata ma kaon ra nimo imo gisulti AAAHHAAHHAH