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

    function getBookingsWithBillingStatus()
    {
        include "connection.php";

        $query = "
        SELECT 
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
    default:
        echo "Invalid Operation";
        break;
}