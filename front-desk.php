<?php

include 'header.php';

// Goals for Admin Functions 
// Importance (From Top to Bottom)
// 1. Can book a customer into a room
// 2. Can cancel a booking
// 3. Can update a booking
// 4. Can view all booking

class Booking_Functions
{
    // Views the Rooms that are currently Booked and it's details
    function view_booking()
    {
        include 'connection.php';

        $sql = "SELECT a.reservation_status_id, CONCAT(c.guests_user_fname, ' ', c.guests_user_lname) AS guest_name, b.reservation_online_num_of_guest, 
                b.reservation_online_adult, b.reservation_online_children, b.reservation_online_roomtype_id FROM tbl_reservation_status a 
                INNER JOIN tbl_reservation_online b ON a.reservation_online_id = b.reservation_online_id
                INNER JOIN tbl_guests c ON b.reservation_online_guest_id = c.guests_id";

        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        unset($pdo, $stmt);

        if ($results) {
            return json_encode(["response" => true, "data" => $results, "message" => "Fetched All Available Bookings"]);
        } else {
            return json_encode(["response" => false, "message" => "Could not find anything..."]);
        }
    }

    // Customer Gets Room from Front Desk
    function add_booking($data)
    {
        include 'connection.php';
        $decodeData = json_decode($data, true);

        $sql = "INSERT INTO timeanddate( guest_name, time_arrival, date_arrival, num_of_guest, 
                adult, children, roomtype_id, created_at, updated_at) 
                VALUES (:name, :time_arrival, :date_arrival, :num_of_guest, :adult, :children, :roomtype_id, 
                :created_at, :updated_at)";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(":name", $decodeData["name"]);
        $stmt->bindParam(":time_arrival", $decodeData["time_arrival"]);
        $stmt->bindParam(":date_arrival", $decodeData["date_arrival"]);
        $stmt->bindParam(":num_of_guest", $decodeData["num_of_guest"]);
        $stmt->bindParam(":adult", $decodeData["adult"]);
        $stmt->bindParam(":children", $decodeData["children"]);
        $stmt->bindParam(":roomtype_id", $decodeData["roomtype_id"]);

        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($results) {
            json_encode(["response" => true, "message" => "Successfully Added New Schedule"]);
        }
    }

    // Add Guests
    function newGuests($data)
    {
        include 'connection.php';
        $decodeData = json_decode($data, true);

        $sql = "INSERT INTO tbl_guests(guests_user_fname, guests_user_lname, guests_user_country, guests_user_email,
                guests_user_phone, guests_user_age)
                VALUES(:fname, :lname, :country, :email, :phone, :age)";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(":fname", $decodeData[""]);
        $stmt->bindParam(":lname", $decodeData[""]);
        $stmt->bindParam(":country", $decodeData[""]);
        $stmt->bindParam(":email", $decodeData[""]);
        $stmt->bindParam(":phone", $decodeData[""]);
        $stmt->bindParam(":age", $decodeData[""]);
        $stmt->execute();

        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        unset($stmt, $pdo);
        if ($result) {
            json_encode(["response" => true, "message" => "Successfully Added Guest"]);
        }
    }

    // Front Desk Checks In and Checks Out
    function visitor_logs($data)
    {
        include 'connection.php';
        $decodeData = json_decode($data, true);

        $sql = "INSERT INTO tbl_guests(visitorlogs_guest_id, visitorlogs_visitorname , visitorlogs_purpose , visitorlogs_checkin_time,
                visitorlogs_checkout_time)
                VALUES(:guest, :visitor_name, :purpose, :check_in, :check_out)";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(":guest", $decodeData[""]);
        $stmt->bindParam(":visitor_name", $decodeData[""]);
        $stmt->bindParam(":purpose", $decodeData[""]);
        $stmt->bindParam(":check_in", $decodeData[""]);
        $stmt->bindParam(":check_out", $decodeData[""]);
        $stmt->execute();

        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        unset($stmt, $pdo);
        if ($result) {
            json_encode(["response" => true, "message" => "Successfully Added New Log"]);
        }
    }

    // Can change Check In Check Out
    function change_visitor_logs($data)
    {
        include 'connection.php';
        $decodeData = json_decode($data, true);

        $sql = "UPDATE tbl_guests 
                SET visitorlogs_guest_id = :guest, visitorlogs_visitorname = :visitor_name, visitorlogs_purpose = :purpose, 
                visitorlogs_checkin_time = :check_in, visitorlogs_checkout_time = :check_out)";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(":guest", $decodeData[""]);
        $stmt->bindParam(":visitor_name", $decodeData[""]);
        $stmt->bindParam(":purpose", $decodeData[""]);
        $stmt->bindParam(":check_in", $decodeData[""]);
        $stmt->bindParam(":check_out", $decodeData[""]);
        $stmt->execute();

        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        unset($stmt, $pdo);
        if ($result) {
            json_encode(["response" => true, "message" => "Successfully Updated Log"]);
        }
    }
}


$booking = new Booking_Functions();
if ($_SERVER["REQUEST_METHOD"] == "GET") {
    
    $svr_Request = $_GET["methodType"];
    switch ($svr_Request) {
        case "view-booking":
            echo $booking->view_booking();
            break;

        default:
            echo json_encode(["response" => false, "message" => "Not Available"]);
    }
    
} else if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $svr_Request = $_POST["methodType"];
    switch ($svr_Request) {
        case "add-booking":
            $book_data = $_POST[""];
            echo $booking->add_booking($book_data);
            break;

        case "addGuest":
            $data = $_POST["guest_data"];
            echo $website->newGuests($data);
            break;

        default:
            echo json_encode(["response" => false, "message" => "Not Available"]);
    }

} else if ($_SERVER["REQUEST_METHOD"] == "PUT") {

    $svr_Request = $_PUT["methodType"];
    switch ($svr_Request) {
        case "upd_Log":
            $book_data = $_PUT["log_data"];
            echo $booking->change_visitor_logs($book_data);
            break;

        default:
    }
} else {
    echo json_encode(["response" => false, "message" => "Request Method not Available..."]);
}
