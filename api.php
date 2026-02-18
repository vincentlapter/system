<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
// Get the HTTP method
$method = $_SERVER['REQUEST_METHOD'];

if ($method == 'GET') {
    $name = 'hello beautifull';
        $json_data = json_encode($name);
        echo $json_data;
} elseif ($method == 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $name = $data['name'];
    $data = 'hello ' . $name;
    $json_data = json_encode($data);
    echo $json_data;
    # code...
}
?>
