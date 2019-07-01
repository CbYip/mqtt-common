<?php
/**
 * Author: Carl Yip
 * Date: 19-6-21
 * Time: 上午10:31
 */

require_once '../vendor/autoload.php';
use mqtt\Client;

$config = [
    'host'      => '127.0.0.1',
    'port'      => 9502,
    'username'  => 'device2',
    'password'  => 'E1xCXM118sHxdWwm',
    'client_id' => 'device01'
];

go(function () use ($config) {
    $client = new Client($config);
    while (!$client->connect()) {
        \Swoole\Coroutine::sleep(3);
        $client->connect();
    }
    $response = $client->publish('vzk6p63muX9B/+/get', '123');
//    if ($response) {
//        $client->close();
//    }
});