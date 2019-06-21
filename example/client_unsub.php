<?php
/**
 * Author: Carl Yip
 * Date: 19-6-20
 * Time: 上午11:48
 */

require_once __DIR__ . '/../src/protocol/Mqtt.php';
require_once __DIR__ . '/../src/Client.php';

$config = [
    'host'      => '127.0.0.1',
    'port'      => 9502,
    'username'  => 'device2',
    'password'  => 'E1xCXM118sHxdWwm',
    'client_id' => 'device02'
];

go(function () use ($config) {
    $client = new \mqtt\src\Client($config);
    while (!$client->connect()) {
        \Swoole\Coroutine::sleep(3);
        $client->connect();
    }
    $topics = ['vzk6p63muX9B/device2/get'];
    $client->unSubscribe($topics);
    $buffer = $client->recv();
    var_dump($buffer);
    $client->close();
});

