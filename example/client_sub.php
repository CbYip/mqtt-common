<?php
/**
 * Author: Carl Yip
 * Date: 19-6-20
 * Time: 上午11:48
 */

require_once '../vendor/autoload.php';
use mqtt\Client;

$config = [
    'host'      => '127.0.0.1',
    'port'      => 9502,
    'username'  => 'device2',
    'password'  => 'E1xCXM118sHxdWwm',
    'client_id' => 'device02',
    'keepalive' => 10,
];

go(function () use ($config) {
    $client = new Client($config);
    $will = [
        'topic'   => 'vzk6p63muX9B/device2/update',
        'qos'     => 1,
        'retain'  => 0,
        'content' => 123
    ];
    while (!$client->connect(true, $will)) {
        \Swoole\Coroutine::sleep(3);
        $client->connect(true, $will);
    }
    $topics['vzk6p63muX9B/device2/get'] = 1;
    $topics['vzk6p63muX9B/device2/update'] = 1;
    $timeSincePing = time();
    $client->subscribe($topics);
    while (true) {
        $buffer = $client->recv();
        if ($buffer && $buffer !== true) {
            var_dump($buffer);
            $timeSincePing = time();
        }
        if (isset($config['keepalive']) && $timeSincePing < (time() - $config['keepalive'])) {
            $buffer = $client->ping();
            if ($buffer) {
                echo '发送心跳包成功' . PHP_EOL;
                $timeSincePing = time();
            } else {
                $client->close();
                break;
            }
        }
    }
});

