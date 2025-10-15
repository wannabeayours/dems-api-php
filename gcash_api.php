<?php
require_once('vendor/autoload.php');
include "headers.php";

$totalAmount = isset($_POST['totalAmount']) ? $_POST['totalAmount'] : 0;
$amount = (int) round(floatval($totalAmount) * 100);
$name = isset($_POST['name']) ? $_POST['name'] : 'John Doe';
$email = isset($_POST['email']) ? $_POST['email'] : 'john@example.com';
$phone = isset($_POST['phone']) ? $_POST['phone'] : '09123456789';
$client = new \GuzzleHttp\Client();

try {
  $response = $client->request('POST', 'https://api.paymongo.com/v1/checkout_sessions', [
    'json' => [
      'data' => [
        'attributes' => [
          'cancel_url' => 'http://localhost:3000/customer/roomsearch',
          'success_url' => 'http://localhost:3000/payment-success',
          'billing' => [
            'address' => [
              'line1' => 'Manila',
              'city' => 'Manila',
              'country' => 'PH',
            ],
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
          ],
          'description' => 'Hotel Booking GCash Payment',
          'line_items' => [
            [
              'amount' => $amount,
              'currency' => 'PHP',
              'name' => 'Hotel Booking Downpayment',
              'quantity' => 1,
            ],
          ],
          'payment_method_types' => ['gcash'],
          'send_email_receipt' => false,
          'show_description' => true,
          'show_line_items' => true,
        ],
      ],
    ],
    'headers' => [
      'Content-Type' => 'application/json',
      'accept' => 'application/json',
      'authorization' => 'Basic c2tfdGVzdF9hVGF5eEU3VUM2SmFTSFN4cnRHOUpYM286', // sandbox key
    ],
  ]);

  $result = json_decode($response->getBody(), true);
  $checkout_url = $result['data']['attributes']['checkout_url'] ?? null;

  echo json_encode(['checkout_url' => $checkout_url]);
} catch (Exception $e) {
  echo json_encode(['error' => $e->getMessage()]);
}
