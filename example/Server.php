<?php
/**
 * Author: Carl Yip
 * Date: 19-6-13
 * Time: 上午9:53
 */

namespace mqtt\example;
require_once __DIR__ . "/../src/Redis.php";
require_once __DIR__ . "/../src/Server.php";

use mqtt\src\Redis;
use swoole_server;
use mqtt\src\Server as BaseServer;

class Server extends BaseServer
{
    public function __construct()
    {
        $server = new swoole_server("127.0.0.1", 9502);
        $server->set(array(
            'worker_num'               => 2,
            'daemonize'                => false,
            'max_request'              => 10000,
            'open_mqtt_protocol'       => true,
            'heartbeat_idle_time'      => 30,
            'heartbeat_check_interval' => 3,
            'dispatch_mode'            => 2,
            'debug_mode'               => 2,
            'log_level'                => SWOOLE_LOG_ERROR
        ));
        $server->on('Start', array($this, 'onStart'));
        $server->on('Connect', array($this, 'onConnect'));
        $server->on('Receive', array($this, 'onReceive'));
        $server->on('Close', array($this, 'onClose'));
        $server->on('WorkerStart', array($this, 'onWorkerStart'));
        $server->start();
    }

    /**
     * @param swoole_server $server
     */
    public function onStart($server)
    {
        $this->debug("Swoole Server Start");
    }

    public function onWorkerStart($serv, $worker_id)
    {
    }

    public function onConnect($serv, $fd, $from_id)
    {
        echo '连接mqtt服务器成功' . $fd . PHP_EOL;
    }

    public function onReceive(swoole_server $server, $fd, $fromId, $buffer)
    {
        go(function () use ($server, $fd, $buffer) {
            try {
                $redis = Redis::getInstance();
                //初始化数据体
                $this->initialize($buffer);
                if ($this->error) {
                    $server->close($fd);
                }
                $ipList = swoole_get_local_ip();
                $ip     = $ipList['eth0'] ?? '127.0.0.1';
                switch ($this->buffer['cmd']) {
                    case 1: //连接
                        //判断客户端是否已经连接，如果是需要断开旧的连接
                        $oldFd = $redis->hGet('mqtt:client', $this->buffer['client_id']);
                        if ($oldFd && $oldFd != $fd) {
                            $server->close($oldFd);
                        }
                        $redis->hSet('mqtt:client', $this->buffer['client_id'], $fd);
                        $redis->hSet('mqtt:ip:' . $ip, $fd, $this->buffer['client_id']);
                        //判断是否有遗嘱信息
                        if (!empty($this->buffer['will'])) {
                            $redis->hMSet('mqtt:will:' . $this->buffer['client_id'], $this->buffer['will']);
                        }
                        $server->send($fd, $this->getAck([
                            'cmd'             => 2,
                            'code'            => 0,
                            'session_present' => 0
                        ]));
                        break;
                    case 3:
                        if (strpos($this->buffer['topic'], '+')) {
                            $topic     = explode('/', $this->buffer['topic']);
                            $topicList = $redis->keys('mqtt:topic:' . $topic[0] . '/*/' . $topic[2] . '*');
                            foreach ($topicList as $v) {
                                $v       = substr($v, strpos($v, 'topic:') + 6);
                                $subList = $redis->hGetAll('mqtt:topic:' . $v);
                                foreach ($subList as $key => $v1) {
                                    //获取客户端fd
                                    $clientFd = $redis->hGet('mqtt:client', $key);
                                    if ($clientFd) {
                                        $server->send($clientFd, $this->getAck([
                                            'cmd'        => $this->buffer['cmd'],
                                            'topic'      => $v,
                                            'content'    => $this->buffer['content'],
                                            'dup'        => $this->buffer['dup'],
                                            'qos'        => $this->buffer['qos'],
                                            'retain'     => $this->buffer['retain'],
                                            'message_id' => $this->buffer['message_id']
                                        ]));
                                    }
                                }
                            }
                        } else {
                            $subList = $redis->hGetAll('mqtt:topic:' . $this->buffer['topic']);
                            foreach ($subList as $key => $v) {
                                //获取客户端fd
                                $clientFd = $redis->hGet('mqtt:client', $key);
                                $server->send($clientFd, $this->getAck([
                                    'cmd'        => $this->buffer['cmd'],
                                    'topic'      => $v,
                                    'content'    => $this->buffer['content'],
                                    'dup'        => $this->buffer['dup'],
                                    'qos'        => $this->buffer['qos'],
                                    'retain'     => $this->buffer['retain'],
                                    'message_id' => $this->buffer['message_id']
                                ]));
                            }
                        }
                        break;
                    case 6:
                        break;
                    case 8: //订阅
                        $clientId = $redis->hGet('mqtt:ip:' . $ip, $fd);
                        $payload  = [];
                        foreach ($this->buffer['topics'] as $k => $v) {
                            if (is_numeric($v) && $v < 3) {
                                $redis->hSet('mqtt:topic:' . $k, $clientId, $v);
                                $payload[] = chr($v);
                            } else {
                                $payload[] = chr(0x80);
                            }
                        }
                        $server->send($fd, $this->getAck([
                            'cmd'        => 9,
                            'message_id' => $this->buffer['message_id'],
                            'payload'    => $payload
                        ]));
                        break;
                    case 10: //取消订阅
                        $clientId = $redis->hGet('mqtt:ip:' . $ip, $fd);
                        foreach ($this->buffer['topics'] as $v) {
                            if ($redis->hExists('mqtt:topic:' . $v, $clientId)) {
                                $redis->hDel('mqtt:topic:' . $v, [$clientId]);
                            }
                        }
                        $server->send($fd, $this->getAck([
                            'cmd'        => 11,
                            'message_id' => $this->buffer['message_id']
                        ]));
                        break;
                    case 12:
                        $server->send($fd, $this->getAck([
                            'cmd' => 13
                        ]));
                        break;
                    case 14:
                        $clientId = $redis->hGet('mqtt:ip:' . $ip, $fd);
                        $redis->hDel('mqtt:client', [$clientId]);
                        $redis->del('mqtt:will:' . $clientId); //移除遗嘱消息
                        $server->send($fd, $this->getAck(['cmd' => 14]));
                        $server->close($fd);
                        break;
                }
            } catch (\Exception $e) {
                $server->close($fd);
            }
        });
    }

    public function onClose(swoole_server $server, $fd, $from_id)
    {
        $this->debug("Client {$fd} close connection");
        $redis    = Redis::getInstance();
        $ipList   = swoole_get_local_ip();
        $ip       = $ipList['eth0'] ?? '127.0.0.1';
        $clientId = $redis->hGet('mqtt:ip:' . $ip, $fd);
        $redis->hDel('mqtt:client', [$clientId]);
//        //查询是否需要颁布遗嘱消息
//        if ($redis->exists('mqtt:will:' . $clientId)) {
//            $will = $redis->hGetAll('mqtt:will:' . $clientId);
//            //获取订阅的客户端
//            $subList = $redis->hGetAll('mqtt:topic:' . $will['topic']);
//            if ($subList) {
//                foreach ($subList as $v) {
//                    $server->send($fd, $this->getAck([
//                        'cmd'        => 3,
//                        'topic'      => $will['topic'],
//                        'content'    => $will['content'],
//                        'dup'        => 0,
//                        'qos'        => $will['qos'],
//                        'retain'     => $will['retain'],
//                        'message_id' => $this->getMsgId()
//                    ]));
//                }
//            }
//        }
        $this->debug("delete client redis data" . $fd . '-' . $clientId);
    }

    public function debug($str, $title = "Debug")
    {
        echo "-------------------------------\n";
        echo '[' . time() . "] " . $title . ':[' . $str . "]\n";
        echo "-------------------------------\n";
    }
}

new Server();