<?php

include 'header.php';

class Admin_Functions
{

    // ------------------------------------------------------- No Table For Admin Yet, needs Changes ------------------------------------------------------- //
    // Login & Signup ???
    function admin_login($data)
    {
        include "connection.php";

        $sql = "";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(":email", $data[""]);
        $stmt->bindParam(":password", $data[""]);
    }

    // Rooms
    function view_rooms()
    {
        include "connection.php";

        $sql = "SELECT 
        b.roomtype_id AS room_type, 
        b.roomtype_name, 
        GROUP_CONCAT(DISTINCT a.imagesroommaster_filename) AS images,
        b.roomtype_price, 
        GROUP_CONCAT(DISTINCT c.roomnumber_id) AS room_ids,
        GROUP_CONCAT(DISTINCT CONCAT('Floor:', c.roomfloor, ' Beds:', c.room_beds)) AS room_details,
        GROUP_CONCAT(DISTINCT e.room_amenities_master_name) AS amenities
        FROM tbl_roomtype b
        LEFT JOIN tbl_imagesroommaster a ON a.roomtype_id = b.roomtype_id
        LEFT JOIN tbl_rooms c ON b.roomtype_id = c.roomtype_id
        LEFT JOIN tbl_amenity_roomtype d ON b.roomtype_id = d.roomtype_id
        LEFT JOIN tbl_room_amenities_master e ON d.room_amenities_master = e.room_amenities_master_id
        GROUP BY b.roomtype_id, b.roomtype_name, b.roomtype_price";

        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $rowCount = $stmt->rowCount();
        unset($stmt, $pdo);

        return $rowCount > 0 ? json_encode($result) : 0;
    }

    // ------------------------------------------------------- Master File Functions ------------------------------------------------------- //
    // ----- Amenity Master ----- //
    function view_Amenities()
    {
        include "connection.php";

        $sql = "SELECT * FROM tbl_room_amenities_master ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $rowCount = $stmt->rowCount();
        unset($stmt, $pdo);

        return $rowCount > 0 ? json_encode($result) : 0;
    }

    function add_NewAmenity($data)
    {
        include "connection.php";

        $sql = "INSERT INTO tbl_room_amenities_master (room_amenities_master_name)
        VALUES (:amenityName)";

        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(":amenityName", $data["amenity_name"]);
        $stmt->execute();

        $rowCount = $stmt->rowCount();
        unset($stmt, $pdo);

        return $rowCount > 0 ? 1 : 0;
    }

    function update_CurrAmenities($data)
    {
        include "connection.php";

        $sql = "UPDATE tbl_room_amenities_master SET room_amenities_master_name=:amenityName 
        WHERE room_amenities_master_id=:amenityID";
        $stmt = $pdo->prepare($sql);

        $stmt->bindParam(":amenityID", $data["amenity_id"]);
        $stmt->bindParam(":amenityName", $data["amenity_name"]);
        $stmt->execute();

        $rowCount = $stmt->rowCount();
        unset($stmt, $pdo);

        return $rowCount > 0 ? 1 : 0;
    }

    function remove_Amenitiy($data)
    {
        include "connection.php";

        $sql = "SELECT * FROM tbl_room_amenities_master ";

        $rowCount = $stmt->rowCount();
        unset($stmt, $pdo);

        return $rowCount > 0 ? 1 : 0;
    }

    // ----- Charges Master ----- //
    function view_AllCharges()
    {
        include "connection.php";

        $sql = "SELECT * FROM tbl_charges_master";

        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $rowCount = $stmt->rowCount();
        unset($stmt, $pdo);

        return $rowCount > 0 ? json_encode($result) : 0;
    }

    function add_NewCharges($data)
    {
        include "connection.php";

        $sql = "INSERT INTO tbl_charges_master (charges_category_id, charges_master_name, charges_master_price)
        VALUES (:categoryID, :chargeName, :chargePrice)";

        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(":categoryID", $data["charge_category"]);
        $stmt->bindParam(":chargeName", $data["charge_name"]);
        $stmt->bindParam(":chargePrice", $data["charge_price"]);
        $stmt->execute();

        $rowCount = $stmt->rowCount();
        unset($stmt, $pdo);

        return $rowCount > 0 ? 1 : 0;
    }

    function update_CurrCharges($data)
    {
        include "connection.php";

        $sql = "UPDATE tbl_charges_master 
        SET 'charges_category_id' = :categoryID, 'charges_master_name' = :chargeName, 'charges_master_price' = :chargePrice
        WHERE room_amenities_master_id = :amenityID";

        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(":categoryID", $data["charge_category"]);
        $stmt->bindParam(":chargeName", $data["charge_name"]);
        $stmt->bindParam(":chargePrice", $data["charge_price"]);
        $stmt->execute();

        $rowCount = $stmt->rowCount();
        unset($stmt, $pdo);

        return $rowCount > 0 ? 1 : 0;
    }

    function remove_Charges($data)
    {
        include "connection.php";

        $sql = "SELECT * FROM tbl_room_amenities_master ";

        $rowCount = $stmt->rowCount();
        unset($stmt, $pdo);

        return $rowCount > 0 ? 1 : 0;
    }

    // ----- Charges Category Master ----- //
    function view_AllChargeCategory()
    {
        include "connection.php";

        $sql = "SELECT * FROM tbl_charges_category";

        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $rowCount = $stmt->rowCount();
        unset($stmt, $pdo);

        return $rowCount > 0 ? json_encode($result) : 0;
    }

    function add_NewChargeCategory($data)
    {
        include "connection.php";

        $sql = "INSERT INTO tbl_charges_category (charges_category_name)
        VALUES (:chargeCategoryName)";

        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(":chargeCategoryName", $data["charge_category_name"]);
        $stmt->execute();

        $rowCount = $stmt->rowCount();
        unset($stmt, $pdo);

        return $rowCount > 0 ? 1 : 0;
    }

    function update_CurrChargeCategory($data)
    {
        include "connection.php";

        $sql = "UPDATE tbl_charges_category 
        SET 'charges_category_name' = :chargeCategoryName
        WHERE charges_category_id = :chargeCategoryID";

        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(":chargeCategoryName", $data["charge_category_name"]);
        $stmt->execute();

        $rowCount = $stmt->rowCount();
        unset($stmt, $pdo);

        return $rowCount > 0 ? 1 : 0;
    }

    function remove_ChargeCategory($data)
    {
        include "connection.php";

        $sql = "SELECT * FROM tbl_room_amenities_master ";

        $rowCount = $stmt->rowCount();
        unset($stmt, $pdo);

        return $rowCount > 0 ? 1 : 0;
    }

    // ----- Discount Master ----- //
    function view_AllDiscounts()
    {
        include "connection.php";

        $sql = "SELECT * FROM tbl_discounts";

        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $rowCount = $stmt->rowCount();
        unset($stmt, $pdo);

        return $rowCount > 0 ? json_encode($result) : 0;
    }

    function add_NewDiscounts($data)
    {
        include "connection.php";

        $sql = "INSERT INTO tbl_discounts (discounts_type, discounts_datestart, discounts_dateend, discounts_percent)
        VALUES (:discountType, :discountDateStart, :discountDateEnd, :discountPercent)";

        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(":discountType", $data["discount_type"]);
        $stmt->bindParam(":discountDateStart", $data["discount_date_start"]);
        $stmt->bindParam(":discountDateEnd", $data["discount_date_end"]);
        $stmt->bindParam(":discountPercent", $data["discount_percent"]);
        $stmt->execute();

        $rowCount = $stmt->rowCount();
        unset($stmt, $pdo);

        return $rowCount > 0 ? 1 : 0;
    }

    function update_CurrDiscounts($data)
    {
        include "connection.php";

        $sql = "UPDATE tbl_discounts 
        SET 'discounts_type' = :discountType, 'discounts_datestart' = :discountDateStart, 
            'discounts_dateend' = :discountDateEnd, 'discounts_percent' = :discountPercent
        WHERE discounts_id = :discountID";

        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(":discountID", $data["discount_id"]);
        $stmt->bindParam(":discountType", $data["discount_type"]);
        $stmt->bindParam(":discountDateStart", $data["discount_date_start"]);
        $stmt->bindParam(":discountDateEnd", $data["discount_date_end"]);
        $stmt->bindParam(":discountPercent", $data["discount_percent"]);
        $stmt->execute();

        $rowCount = $stmt->rowCount();
        unset($stmt, $pdo);

        return $rowCount > 0 ? 1 : 0;
    }

    function remove_Discounts($data)
    {
        include "connection.php";

        $sql = "SELECT * FROM tbl_room_amenities_master ";

        $rowCount = $stmt->rowCount();
        unset($stmt, $pdo);

        return $rowCount > 0 ? 1 : 0;
    }

    // ----- Room Type Master ----- //
    function view_AllRoomTypes()
    {
        include "connection.php";

        $sql = "SELECT * FROM tbl_roomtype";

        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $rowCount = $stmt->rowCount();
        unset($stmt, $pdo);

        return $rowCount > 0 ? json_encode($result) : 0;
    }

    function add_NewRoomTypes($data)
    {
        include "connection.php";

        $sql = "INSERT INTO tbl_roomtype (roomtype_name, roomtype_description, roomtype_price)
        VALUES (:roomTypeName, :discountDateStart, :discountDateEnd, :discountPercent)";

        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(":discountType", $data["discount_type"]);
        $stmt->bindParam(":discountDateStart", $data["discount_date_start"]);
        $stmt->bindParam(":discountDateEnd", $data["discount_date_end"]);
        $stmt->bindParam(":discountPercent", $data["discount_percent"]);
        $stmt->execute();

        $rowCount = $stmt->rowCount();
        unset($stmt, $pdo);

        return $rowCount > 0 ? 1 : 0;
    }

    function update_CurrRoomTypes($data)
    {
        include "connection.php";

        $sql = "UPDATE  tbl_roomtype 
        SET 'discounts_type' = :discountType, 'discounts_datestart' = :discountDateStart, 
            'discounts_dateend' = :discountDateEnd, 'discounts_percent' = :discountPercent
        WHERE discounts_id = :discountID";

        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(":discountID", $data["discount_id"]);
        $stmt->bindParam(":discountType", $data["discount_type"]);
        $stmt->bindParam(":discountDateStart", $data["discount_date_start"]);
        $stmt->bindParam(":discountDateEnd", $data["discount_date_end"]);
        $stmt->bindParam(":discountPercent", $data["discount_percent"]);
        $stmt->execute();

        $rowCount = $stmt->rowCount();
        unset($stmt, $pdo);

        return $rowCount > 0 ? 1 : 0;
    }

    function remove_RoomTypes($data)
    {
        include "connection.php";

        $sql = "SELECT * FROM  tbl_roomtype";

        $rowCount = $stmt->rowCount();
        unset($stmt, $pdo);

        return $rowCount > 0 ? 1 : 0;
    }
}


$AdminClass = new Admin_Functions();

$methodType = isset($_POST["method"]) ? $_POST["method"] : 0;
$jsonData = isset($_POST["json"]) ? json_decode($_POST["json"], true) : 0;


switch ($methodType) {
    // --------------------------------- For Viewing Data or Login --------------------------------- //
    case "login-data":
        echo $AdminClass->admin_login($jsonData);
        break;

    case "view-customers":
        echo json_encode(["message" => "Successfully Retrieved Data"]);
        break;


    // Room Management or Something?
    case "view_rooms":
        echo $AdminClass->view_rooms();
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