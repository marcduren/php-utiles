<?php
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    header('Access-Control-Allow-Origin: ' . $_SERVER['HTTP_ORIGIN']);
    header('Access-Control-Allow-Methods: POST, GET, DELETE, PUT, PATCH, OPTIONS');
    header('Access-Control-Allow-Headers: X-PINGOTHER, Content-Type, Authorization, Content-Length, X-Requested-With');
    header('Access-Control-Max-Age: 1728000');
    header('Content-Length: 0');
    header('Content-Type: text/plain');
    die();
} elseif ($_SERVER['REQUEST_METHOD'] == "GET") {
    header('Access-Control-Allow-Origin: ' . $_SERVER['HTTP_ORIGIN']);
    header('Content-Type: application/json');
    $postData = $_REQUEST;
} elseif ($_SERVER['REQUEST_METHOD'] == "POST") {
    header('Access-Control-Allow-Origin: ' . $_SERVER['HTTP_ORIGIN']);
    header('Content-Type: application/json');
    $postData = json_decode(file_get_contents('php://input'), true);
}
