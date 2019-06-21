<?php
/**
 * Author: Carl Yip
 * Date: 19-6-19
 * Time: 下午2:21
 */

namespace mqtt\src;
require_once 'protocol/Mqtt.php';

use mqtt\src\protocol\Mqtt;

class Server
{
    /**
     * 数据包
     *
     * @var
     */
    private $buffer;

    /**
     * 连接返回code码
     *
     * @var
     */
    protected $code = 0;

    /**
     * 当前会话
     *
     * @var
     */
    protected $sessionPresent = 0;

    /**
     * 错误标识
     *
     * @var int
     */
    protected $error;

    /**
     * 消息包数
     *
     * @var int
     */
    private $msgId = 0;

    /**
     * 初始化解析数据包
     *
     * @param $buffer
     */
    public function initialize($buffer)
    {
        Mqtt::printStr($buffer);
        //检查包长
        if (!Mqtt::input($buffer)) {
            $this->error = '数据包长度不一致';
        }
        $this->buffer = Mqtt::decode($buffer);
    }

    /**
     * 获取响应数据包
     *
     * @param array $attribute 属性
     * @return string
     */
    public function getAck($attribute)
    {
        return Mqtt::encode($attribute);
    }

    /**
     * 获取消息包数
     * @return int
     */
    public function getMsgId()
    {
        return ++$this->msgId;
    }

    /**
     * 获取私有变量
     *
     * @param string $name
     * @return mixed
     */
    public function __get($name)
    {
        return $this->$name;
    }
}