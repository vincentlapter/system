<?php
// Prevent multiple loading
if (defined('CONFIG_LOADED')) {
    return;
}

define('CONFIG_LOADED', true);

$host = "localhost";
$user = "schs_admin";
$pass = "vincent2002";
$dbname = "schs_schooldb";

$conn = new mysqli($host, $user, $pass, $dbname);

if ($conn->connect_error) {
    die("connection failed:" . $conn->connect_error);
}

// SchoolPay API Configuration
define('SCHOOLPAY_BASE_URL', 'https://schoolpay.co.ug/paymentapi/AndroidRS/');
define('SCHOOLPAY_SCHOOL_CODE', '19457');
define('SCHOOLPAY_PASSWORD', 'Ts@vxe000045ik');

// Set timezone
date_default_timezone_set('Africa/Kampala');
?>