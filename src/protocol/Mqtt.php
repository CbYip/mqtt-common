<?php
/**
 * Author: Carl Yip
 * Date: 19-6-19
 * Time: 下午2:17
 */

namespace mqtt\protocol;

class Mqtt
{
    const CONNECT     = 1;
    const CONNACK     = 2;
    const PUBLISH     = 3;
    const PUBACK      = 4;
    const PUBREC      = 5;
    const PUBREL      = 6;
    const PUBCOMP     = 7;
    const SUBSCRIBE   = 8;
    const SUBACK      = 9;
    const UNSUBSCRIBE = 10;
    const UNSUBACK    = 11;
    const PINGREQ     = 12;
    const PINGRESP    = 13;
    const DISCONNECT  = 14;

    /**
     * 检查包长
     *
     * @param string $buffer
     * @return int
     */
    public static function input($buffer)
    {
        $length      = strlen($buffer);
        $bodyLength  = static::getBodyLength($buffer, $headBytes);
        $totalLength = $bodyLength + $headBytes;
        if ($length < $totalLength) {
            return 0;
        }

        return $totalLength;
    }

    /**
     * 打包Mqtt数据包
     *
     * @param array $data
     * @return string
     */
    public static function encode($data)
    {
        $cmd = $data['cmd'];
        switch ($data['cmd']) {
            // ['cmd'=>1, 'clean_session'=>x, 'will'=>['qos'=>x, 'retain'=>x, 'topic'=>x, 'content'=>x],'username'=>x, 'password'=>x, 'keepalive'=>x, 'protocol_name'=>x, 'protocol_level'=>x, 'client_id' => x]
            case static::CONNECT;
                $body          = self::packString($data['protocol_name']) . chr($data['protocol_level']);
                $connect_flags = 0;
                if (!empty($data['clean_session'])) {
                    $connect_flags |= 1 << 1;
                }
                if (!empty($data['will'])) {
                    $connect_flags |= 1 << 2;
                    $connect_flags |= $data['will']['qos'] << 3;
                    if ($data['will']['retain']) {
                        $connect_flags |= 1 << 5;
                    }
                }
                if (!empty($data['password'])) {
                    $connect_flags |= 1 << 6;
                }
                if (!empty($data['username'])) {
                    $connect_flags |= 1 << 7;
                }
                $body .= chr($connect_flags);

                $keepalive = !empty($data['keepalive']) && (int)$data['keepalive'] >= 0 ? (int)$data['keepalive'] : 0;
                $body      .= pack('n', $keepalive);

                $body .= static::packString($data['client_id']);
                if (!empty($data['will'])) {
                    $body .= static::packString($data['will']['topic']);
                    $body .= static::packString($data['will']['content']);
                }
                if (!empty($data['username']) || $data['username'] === '0') {
                    $body .= static::packString($data['username']);
                }
                if (!empty($data['password']) || $data['password'] === '0') {
                    $body .= static::packString($data['password']);
                }
                $head = self::packHead($cmd, strlen($body));
                return $head . $body;
            //['cmd'=>2, 'session_present'=>0/1, 'code'=>x]
            case static::CONNACK:
                $body = !empty($data['session_present']) ? chr(1) : chr(0);
                $code = !empty($data['code']) ? $data['code'] : 0;
                $body .= chr($code);
                $head = static::packHead($cmd, strlen($body));
                return $head . $body;
            // ['cmd'=>3, 'message_id'=>x, 'topic'=>x, 'content'=>x, 'qos'=>0/1/2, 'dup'=>0/1, 'retain'=>0/1]
            case static::PUBLISH:
                $body = static::packString($data['topic']);
                $qos  = isset($data['qos']) ? $data['qos'] : 0;
                if ($qos) {
                    $body .= pack('n', $data['message_id']);
                }
                $body   .= $data['content'];
                $dup    = isset($data['dup']) ? $data['dup'] : 0;
                $retain = isset($data['retain']) ? $data['retain'] : 0;
                $head   = static::packHead($cmd, strlen($body), $dup, $qos, $retain);
                return $head . $body;
            // ['cmd'=>x, 'message_id'=>x]
            case static::PUBACK:
            case static::PUBREC:
            case static::PUBREL:
            case static::PUBCOMP:
                $body = pack('n', $data['message_id']);
                if ($cmd === static::PUBREL) {
                    $head = static::packHead($cmd, strlen($body), 0, 1);
                } else {
                    $head = static::packHead($cmd, strlen($body));
                }
                return $head . $body;

            // ['cmd'=>8, 'message_id'=>x, 'topics'=>[topic=>qos, ..]]]
            case static::SUBSCRIBE:
                $id   = $data['message_id'];
                $body = pack('n', $id);
                foreach ($data['topics'] as $topic => $qos) {
                    $body .= self::packString($topic);
                    $body .= chr($qos);
                }
                $head = static::packHead($cmd, strlen($body), 0, 1);
                return $head . $body;
            // ['cmd'=>9, 'message_id'=>x, 'codes'=>[x,x,..]]
            case static::SUBACK:
                $payload = $data['payload'];
                $body    = pack('n', $data['message_id']) . call_user_func_array('pack', array_merge(array('C*'), $payload));
                $head    = static::packHead($cmd, strlen($body));
                return $head . $body;

            // ['cmd' => 10, 'message_id' => $message_id, 'topics' => $topics];
            case static::UNSUBSCRIBE:
                $body = pack('n', $data['message_id']);
                foreach ($data['topics'] as $topic) {
                    $body .= static::packString($topic);
                }
                $head = static::packHead($cmd, strlen($body), 0, 1);
                return $head . $body;
            // ['cmd'=>11, 'message_id'=>x]
            case static::UNSUBACK:
                $body = pack('n', $data['message_id']);
                $head = static::packHead($cmd, strlen($body));
                return $head . $body;

            // ['cmd'=>x]
            case static::PINGREQ;
            case static::PINGRESP:
            case static::DISCONNECT:
                return static::packHead($cmd, 0);
            default:
                return '';
        }
    }

    /**
     * 解析MQTT数据包
     *
     * @param string $buffer
     * @return string|array
     */
    public static function decode($buffer)
    {
        $cmd  = static::getCmd($buffer);//获取消息类型
        $body = static::getBody($buffer);//获取消息体
        switch ($cmd) {
            case static::CONNECT:
                $protocolName  = static::readString($body);
                $protocolLevel = ord($body[0]);
                $cleanSession  = ord($body[1]) >> 1 & 0x1;
                $willFlag      = ord($body[1]) >> 2 & 0x1;
                $willQos       = ord($body[1]) >> 3 & 0x3;
                $willRetain    = ord($body[1]) >> 5 & 0x1;
                $passwordFlag  = ord($body[1]) >> 6 & 0x1;
                $usernameFlag  = ord($body[1]) >> 7 & 0x1;
                $body          = substr($body, 2);
                $tmp           = unpack('n', $body);
                $keepalive     = $tmp[1];
                $body          = substr($body, 2);
                $clientId      = static::readString($body);
                if ($willFlag) {
                    $willTopic   = static::readString($body);
                    $willContent = static::readString($body);
                }
                $username = $password = '';
                if ($usernameFlag) {
                    $username = static::readString($body);
                }
                if ($passwordFlag) {
                    $password = static::readString($body);
                }
                // ['cmd'=>1, 'clean_session'=>x, 'will'=>['qos'=>x, 'retain'=>x, 'topic'=>x, 'content'=>x],'username'=>x, 'password'=>x, 'keepalive'=>x, 'protocol_name'=>x, 'protocol_level'=>x, 'client_id' => x]
                $package = array(
                    'cmd'            => $cmd,
                    'protocol_name'  => $protocolName,
                    'protocol_level' => $protocolLevel,
                    'clean_session'  => $cleanSession,
                    'will'           => array(),
                    'username'       => $username,
                    'password'       => $password,
                    'keepalive'      => $keepalive,
                    'client_id'      => $clientId,
                );
                if ($willFlag) {
                    $package['will'] = array(
                        'qos'     => $willQos,
                        'retain'  => $willRetain,
                        'topic'   => $willTopic,
                        'content' => $willContent
                    );
                } else {
                    unset($package['will']);
                }
                return $package;
            case static::CONNACK:
                $sessionPresent = ord($body[0]) & 0x01;
                $code           = ord($body[1]);
                return array('cmd' => $cmd, 'session_present' => $sessionPresent, 'code' => $code);
            case static::PUBLISH:
                $dup    = ord($buffer[0]) >> 3 & 0x1;
                $qos    = ord($buffer[0]) >> 1 & 0x3;
                $retain = ord($buffer[0]) & 0x1;
                $topic  = static::readString($body);
                if ($qos) {
                    $messageId = static::readShortInt($body);
                }
                $package = array('cmd' => $cmd, 'topic' => $topic, 'content' => $body, 'dup' => $dup, 'qos' => $qos, 'retain' => $retain);
                if ($qos) {
                    $package['message_id'] = $messageId;
                }
                return $package;
            case static::PUBACK:
            case static::PUBREC:
            case static::PUBREL:
            case static::PUBCOMP:
                $messageId = static::readShortInt($body);
                return array('cmd' => $cmd, 'message_id' => $messageId);
            case static::SUBSCRIBE:
                $messageId = static::readShortInt($body);
                $topics     = array();
                while ($body) {
                    $topic          = static::readString($body);
                    $qos            = ord($body[0]);
                    $topics[$topic] = $qos;
                    $body           = substr($body, 1);
                }
                return array('cmd' => $cmd, 'message_id' => $messageId, 'topics' => $topics);
            case static::SUBACK:
                $messageId = static::readShortInt($body);
                $tmp        = unpack('C*', $body);
                $codes      = array_values($tmp);
                return array('cmd' => $cmd, 'message_id' => $messageId, 'codes' => $codes);
            case static::UNSUBSCRIBE:
                $messageId = static::readShortInt($body);
                $topics     = array();
                while ($body) {
                    $topic    = static::readString($body);
                    $topics[] = $topic;
                }
                return array('cmd' => $cmd, 'message_id' => $messageId, 'topics' => $topics);
            case static::UNSUBACK:
                $messageId = static::readShortInt($body);
                return array('cmd' => $cmd, 'message_id' => $messageId);
            case static::PINGREQ:
            case static::PINGRESP:
            case static::DISCONNECT:
                return array('cmd' => $cmd);
        }
        return $buffer;
    }


    /**
     * Pack string.
     *
     * @param $str
     * @return string
     */
    public static function packString($str)
    {
        $len = strlen($str);
        return pack('n', $len) . $str;
    }

    /**
     * 写入包长
     *
     * @param int $length 长度
     * @return string
     */
    protected static function writeBodyLength($length)
    {
        $string = "";
        do {
            $digit  = $length % 128;
            $length = $length >> 7;
            // if there are more digits to encode, set the top bit of this digit
            if ($length > 0)
                $digit = ($digit | 0x80);
            $string .= chr($digit);
        } while ($length > 0);
        return $string;
    }

    /**
     * 获取消息类型.
     *
     * @param $buffer
     * @return int
     */
    public static function getCmd($buffer)
    {
        return ord($buffer[0]) >> 4;
    }

    /**
     * 获取消息体长度
     *
     * @param string $buffer    消息内容
     * @param int    $headBytes 固定头部长度
     * @return int
     */
    public static function getBodyLength($buffer, &$headBytes)
    {
        $headBytes = $multiplier = 1;
        $value     = 0;
        do {
            if (!isset($buffer[$headBytes])) { //如果剩余长度不存在，则返回消息体为空
                $headBytes = 0;
                return 0;
            }
            $digit      = ord($buffer[$headBytes]); //消息体长度
            $value      += ($digit & 127) * $multiplier;
            $multiplier *= 128;
            $headBytes++;
        } while (($digit & 128) != 0);
        return $value;
    }

    /**
     * 获取消息内容.
     *
     * @param string $buffer
     * @return string
     */
    public static function getBody($buffer)
    {
        $bodyLength = static::getBodyLength($buffer, $headBytes);
        $buffer     = substr($buffer, $headBytes, $bodyLength);
        return $buffer;
    }

    /**
     * 从消息体中解析数据
     *
     * @param $buffer
     * @return string
     */
    public static function readString(&$buffer)
    {
        $tmp    = unpack('n', $buffer);
        $length = $tmp[1];
        if ($length + 2 > strlen($buffer)) {
            echo "buffer:" . bin2hex($buffer) . " lenth:$length not enough for unpackString\n";
        }

        $string = substr($buffer, 2, $length);
        $buffer = substr($buffer, $length + 2);
        return $string;
    }

    /**
     * 读取无符号短整型数据
     *
     * @param $buffer
     * @return mixed
     */
    public static function readShortInt(&$buffer)
    {
        $tmp    = unpack('n', $buffer);
        $buffer = substr($buffer, 2);
        return $tmp[1];
    }

    /**
     * 打包固定头信息
     *
     * @param int $cmd        消息类型
     * @param int $bodyLength 包体长度
     * @param int $dup        重发标志位
     * @param int $qos        服务质量等级
     * @param int $retain     保留标志
     * @return string
     */
    public static function packHead($cmd, $bodyLength, $dup = 0, $qos = 0, $retain = 0)
    {
        $cmd = $cmd << 4;
        if ($dup) {
            $cmd |= 1 << 3;
        }
        if ($qos) {
            $cmd |= $qos << 1;
        }
        if ($retain) {
            $cmd |= 1;
        }
        return chr($cmd) . static::writeBodyLength($bodyLength);
    }

    /**
     * 打印数据内容
     *
     * @param string $string
     */
    public static function printStr($string)
    {
        $strLen = strlen($string);
        for ($j = 0; $j < $strLen; $j++) {
            $num = ord($string{$j});
            if ($num > 31)
                $chr = $string{$j};
            else
                $chr = " ";
            printf("%4d: %08b : 0x%02x : %d : %s \n", $j, $num, $num, $num, $chr);
        }
    }
}