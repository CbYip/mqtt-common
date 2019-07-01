<?php
/**
 * Author: Carl Yip
 * Date: 19-6-19
 * Time: 下午2:21
 */

namespace mqtt;

use mqtt\protocol\Mqtt;
use Swoole\Coroutine;

class Client
{
    /**
     * 客户端实例
     *
     * @var
     */
    private $client;

    /**
     * 客户端配置
     *
     * @var
     */
    private $config;

    /**
     * 数据包id数
     *
     * @var
     */
    private $msgId = 0;

    /**
     * 构造函数
     *
     * @param array $config 配置
     * @throws \Exception
     */
    public function __construct($config)
    {
        if (empty($config)) {
            throw new \Exception('参数配置不能为空');
        }

        $this->config = $config;
        $this->client = new \Co\Client(SWOOLE_SOCK_TCP); //协程客户端，长连接
        if (!$this->client->connect($this->config['host'], $this->config['port'], 0.5)) {
            //尝试重连
            $this->reConnect();
        }
    }

    /**
     * 连接broker
     *
     * @param bool  $clean 是否清除会话
     * @param array $will  遗嘱消息
     * @return mixed
     */
    public function connect($clean = true, $will = [])
    {
        $data = [
            'cmd'            => 1,
            'protocol_name'  => 'MQTT',
            'protocol_level' => 4,
            'clean_session'  => $clean ? 0 : 1,
            'client_id'      => $this->config['client_id'],
            'keepalive'      => $config['keepalive'] ?? 0
        ];
        if (isset($this->config['username'])) $data['username'] = $this->config['username'];
        if (isset($this->config['password'])) $data['password'] = $this->config['password'];
        if (!empty($will)) $data['will'] = $will;
        return $this->sendBuffer($data);
    }

    /**
     * 订阅主题
     *
     * @param array $topics 主题列表
     * @return mixed
     */
    public function subscribe($topics)
    {
        $data = [
            'cmd'        => 8,
            'message_id' => $this->getMsgId(),
            'topics'     => $topics
        ];
        return $this->sendBuffer($data);
    }

    /**
     * 取消订阅主题
     *
     * @param array $topics 主题列表
     * @return mixed
     */
    public function unSubscribe($topics)
    {
        $data = [
            'cmd'        => 10,
            'message_id' => $this->getMsgId(),
            'topics'     => $topics
        ];
        return $this->sendBuffer($data);
    }

    /**
     * 客户端发布消息
     *
     * @param string $topic   主题
     * @param string $content 消息内容
     * @param int    $qos     服务质量等级
     * @param int    $dup
     * @param int    $retain  保留标志
     * @return mixed
     */
    public function publish($topic, $content, $qos = 0, $dup = 0, $retain = 0)
    {
        $response = ($qos > 0) ? true : false;
        return $this->sendBuffer([
            'cmd'        => 3,
            'message_id' => $this->getMsgId(),
            'topic'      => $topic,
            'content'    => $content,
            'qos'        => $qos,
            'dup'        => $dup,
            'retain'     => $retain
        ], $response);
    }

    /**
     * 接收订阅的消息
     *
     * @return bool
     * @throws \Exception
     */
    public function recv()
    {
        $response = $this->client->recv();
        if ($response === false) {
            return true;
        } elseif ($response === '') { //已断线，需要进行重连
            $this->reConnect();
            return true;
        }
        $buffer = Mqtt::decode($response);
        return $buffer;
    }

    /**
     * 发送心跳包
     *
     * @return mixed
     */
    public function ping()
    {
        return $this->sendBuffer(['cmd' => 12]);
    }

    /**
     * 断开连接
     *
     * @return string
     */
    public function close()
    {
        return $this->sendBuffer(['cmd' => 14]);
    }

    /**
     * 发送数据信息
     *
     * @param array $data
     * @param bool  $response 需要响应
     * @return mixed
     */
    private function sendBuffer($data, $response = true)
    {
        $buffer = Mqtt::encode($data);
        $this->client->send($buffer);
        if ($response) {
            $response = $this->client->recv();
            return Mqtt::decode($response);
        }
        return true;
    }

    /**
     * 断线重连
     *
     * @throws \Exception
     */
    private function reConnect()
    {
        $reConnectTime = 1;
        $result        = false;
        while (!$result) {
            Coroutine::sleep(3); //协程挂起睡眠3秒
            $this->debug('正在进行第' . $reConnectTime . '次重连');
            $this->client->close();//先释放上一次连接的socket句柄
            $result = $this->client->connect($this->config['host'], $this->config['port'], 0.5);
            $reConnectTime++;
        }
        $this->connect();
    }

    /**
     * 获取当前消息id条数
     *
     * @return int
     */
    public function getMsgId()
    {
        return ++$this->msgId;
    }

    public function debug($str, $title = "Debug")
    {
        echo "-------------------------------\n";
        echo '[' . time() . "] " . $title . ':[' . $str . "]\n";
        echo "-------------------------------\n";
    }
}