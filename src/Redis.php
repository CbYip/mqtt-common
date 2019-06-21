<?php
/**
 * Created by PhpStorm.
 * User: cb
 * Date: 18-9-13
 * Time: 下午4:32
 */

namespace mqtt\src;

class Redis
{
    private static $handler   = null;
    private static $_instance = null;
    private static $options   = [
        'host'       => '',
        'port'       => 6379,
        'password'   => '',
        'select'     => 0,
        'timeout'    => 0,
        'expire'     => 0,
        'persistent' => true,
        'prefix'     => '',
    ];

    /**
     * Redis constructor.
     *
     * @param array $options
     */
    private function __construct($options = [])
    {
        self::$options['host']     = '127.0.0.1';
        self::$options['password'] = 'sb02010513';

        if (!extension_loaded('redis')) {
            throw new \BadFunctionCallException('not support: redis');      //判断是否有扩展
        }
        if (!empty($options)) {
            self::$options = array_merge(self::$options, $options);
        }
        $func          = self::$options['persistent'] ? 'pconnect' : 'connect';     //长链接
        self::$handler = new \Redis;
        self::$handler->$func(self::$options['host'], self::$options['port'], self::$options['timeout']);

        if ('' != self::$options['password']) {
            self::$handler->auth(self::$options['password']);
        }

        if (0 != self::$options['select']) {
            self::$handler->select(self::$options['select']);
        }
    }


    /**
     * 获取redis对象
     *
     * @return Redis|null 对象
     */
    public static function getInstance()
    {
        if (!(self::$_instance instanceof self)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    /**
     * 禁止外部克隆
     */
    public function __clone()
    {
        trigger_error('Clone is not allow!', E_USER_ERROR);
    }

    /**
     * 写入缓存
     *
     * @param string $key    键名
     * @param string $value  键值
     * @param int    $expire 过期时间 0:永不过期
     * @return bool
     */
    public static function set($key, $value, $expire = 0)
    {
        if ($expire == 0) {
            $set = self::$handler->set($key, $value);
        } else {
            $set = self::$handler->setex($key, $expire, $value);
        }
        return $set;
    }

    /**
     * 写入缓存，如果缓存已存在不会替换
     *
     * @param $key
     * @param $value
     * @param $expire
     * @return bool
     */
    public static function setnx($key, $value, $expire = 0)
    {
        $set = self::$handler->setnx($key, $value);
        if ($set && $expire > 0) {
            $set = self::$handler->expire($key, $expire);
        }
        return $set;
    }

    /**
     * 读取缓存
     *
     * @param string $key 键值
     * @return mixed
     */
    public static function get($key)
    {
        $fun = is_array($key) ? 'Mget' : 'get';
        return self::$handler->{$fun}($key);
    }

    /**
     * 删除缓存
     *
     * @param $key
     * @return int
     */
    public static function del($key)
    {
        return self::$handler->del($key);
    }

    /**
     * 清空缓存
     *
     * @return bool
     */
    public static function flushAll()
    {
        return self::$handler->flushAll();
    }

    /**
     * 获取key组
     *
     * @param $key
     * @return array
     */
    public static function keys($key)
    {
        return self::$handler->keys($key);
    }

    /**
     * 获取值长度
     *
     * @param string $key
     * @return int
     */
    public static function lLen($key)
    {
        return self::$handler->lLen($key);
    }

    /**
     * 将一个或多个值插入到列表头部
     *
     * @param $key
     * @param $value
     * @return int
     */
    public static function LPush($key, $value)
    {
        return self::$handler->lPush($key, $value);
    }

    /**
     * 向队列尾部插入数据
     *
     * @param $key
     * @param $fields
     * @return string
     */
    public static function rPush($key, $fields)
    {
        return self::reflectMethod($key, $fields, 'rPush');
    }

    /**
     * 移出并获取列表的第一个元素
     *
     * @param string $key
     * @return string
     */
    public static function lPop($key)
    {
        return self::$handler->lPop($key);
    }

    /**
     * 获取列表的第一个元素，如果没有元素则阻塞一分钟
     *
     * @param mixed $keys    键值组
     * @param int   $timeout 超时时间
     * @return array
     */
    public static function blPop($keys, $timeout = 30)
    {
        return self::$handler->blPop($keys, $timeout);
    }

    /**
     * 查询是否是集合里的成员
     *
     * @param $key
     * @param $value
     * @return bool
     */
    public static function sIsMember($key, $value)
    {
        return self::$handler->sIsMember($key, $value);
    }

    /**
     * 列出集合的所有成员
     *
     * @param $key
     * @return array
     */
    public static function sMembers($key)
    {
        return self::$handler->sMembers($key);
    }

    /**
     * 同sIsMember
     *
     * @param $key
     * @param $value
     */
    public static function sContains($key, $value)
    {
        return self::$handler->sContains($key, $value);
    }

    /**
     * 两个集合间的成员移动
     *
     * @param $srcKey
     * @param $dstKey
     * @param $value
     * @return bool
     */
    public static function sMove($srcKey, $dstKey, $value)
    {
        return self::$handler->sMove($srcKey, $dstKey, $value);
    }

    /**
     * 批量添加集合成员
     *
     * @param $key
     * @param $fields
     * @param $timeout
     * @return int
     */
    public static function sAdd($key, $fields, $timeout = 0)
    {
        $response = self::reflectMethod($key, $fields, 'sAdd');
        if ($response && $timeout > 0) {
            self::$handler->expire($key, $timeout);
        }
        return $response;
    }

    /**
     * 移除集合中一个或多个成员
     *
     * @param $key
     * @param $fields
     * @return mixed
     */
    public static function sRem($key, $fields)
    {
        return self::reflectMethod($key, $fields, 'sRem');
    }

    /**
     * 开启redis事务
     *
     * @return \Redis
     */
    public static function multi()
    {
        return self::$handler->multi();
    }

    /**
     * 执行事务里的所有命令
     *
     * @return array
     */
    public static function exec()
    {
        return self::$handler->exec();
    }

    /**
     * 获取key数据并销毁key
     *
     * @param $key
     * @return mixed
     */
    public static function getOnce($key)
    {
        $get = self::get($key);
        if ($get) {
            self::del($key);
        }
        return $get;
    }

    /**
     * 设置哈希Key
     *
     * @param     $key
     * @param     $hashKey
     * @param     $value
     * @param int $timeout
     * @return bool|int
     */
    public static function hSet($key, $hashKey, $value, $timeout = 0)
    {
        $response = self::$handler->hSet($key, $hashKey, $value);
        if ($timeout > 0) {
            self::$handler->expire($key, $timeout);
        }
        return $response;
    }

    /**
     * 设置哈希key[多个字段]
     *
     * @param       $key
     * @param array $array
     * @param int   $timeout
     * @return bool
     */
    public static function hMset($key, $array = [], $timeout = 0)
    {
        $response = self::$handler->hMset($key, $array);
        if ($timeout > 0) {
            self::$handler->expire($key, $timeout);
        }
        return $response;
    }

    /**
     * 获取哈希表中所有值
     *
     * @param $key
     * @return array
     */
    public static function hVals($key)
    {
        return self::$handler->hVals($key);
    }

    /**
     * 获取哈希表中所有字段和值
     *
     * @param $key
     * @return array
     */
    public static function hGetAll($key)
    {
        return self::$handler->hGetAll($key);
    }

    /**
     * 获取存储在哈希表中指定的字段
     *
     * @param $key
     * @param $field
     * @return string
     */
    public static function hGet($key, $field)
    {
        return self::$handler->hGet($key, $field);
    }

    /**
     * 删除哈希表中指定的字段
     *
     * @param string $key
     * @param array  $fields
     * @return bool|int
     */
    public static function hDel($key, $fields)
    {
        return self::reflectMethod($key, $fields, 'hDel');
    }

    /**
     * 调用批量添加
     *
     * @param string       $key    键名
     * @param string|array $fields 数据内容
     * @param string       $method 方法名
     * @return mixed
     */
    public static function reflectMethod($key, $fields, $method)
    {
        if (!is_array($fields))
            $fields = [$fields];
        array_unshift($fields, $key);
        return call_user_func_array(array(self::$handler, $method), $fields);
    }

    /**
     * 给对应的Key自增1
     *
     * @param $key
     * @return int
     */
    public static function incr($key)
    {
        return self::$handler->incr($key);
    }

    /**
     * 返回对应Key的剩余过期时间
     *
     * @param $key
     * @return int
     */
    public static function ttl($key)
    {
        return self::$handler->ttl($key);
    }

    /**
     * 批量添加有序集合
     *
     * @param       $key
     * @param array $scoreMember 分数成员集合
     * @return mixed
     */
    public static function zAdd($key, $scoreMember)
    {
        return self::reflectMethod($key, $scoreMember, 'zAdd');
    }

    /**
     * 移除一个或多个成员
     *
     * @param string       $key   键名
     * @param array|string $value 成员
     * @return mixed
     */
    public static function zRem($key, $value)
    {
        return self::reflectMethod($key, $value, 'zRem');
    }

    /**
     * 查询有序集合中指定成员的索引
     *
     * @param       $key
     * @param mixed $value 值
     * @return int
     */
    public static function zRank($key, $value)
    {
        return self::$handler->zRank($key, $value);
    }

    /**
     * 返回有序集合中指定成员的分数
     *
     * @param string $key    键名
     * @param string $member 成员
     * @return float
     */
    public static function zScore($key, $member)
    {
        return self::$handler->zScore($key, $member);
    }

    /**
     * 通过索引区间返回有序集合指定区间的成员
     *
     * @param mixed $key        键值
     * @param int   $start      开始分数
     * @param int   $stop       结束分数
     * @param bool  $withScores 是否包含分数
     * @return array
     */
    public static function zRange($key, $start, $stop, $withScores = false)
    {
        return self::$handler->zRange($key, $start, $stop, $withScores);
    }

    /**
     * 获取有序集合中给定的分数区间内的所有成员
     *
     * @param string $key     键名
     * @param int    $start   起始分数
     * @param int    $stop    结束分数
     * @param array  $options 配置参数
     * @return array
     */
    public static function zRangeByScore($key, $start, $stop, $options = array())
    {
        return self::$handler->zRangeByScore($key, $start, $stop, $options);
    }

    /**
     * 移除有序集合中给定的排名区间的所有成员
     *
     * @param mixed $key       键名
     * @param int   $start     起始分数
     * @param int   $stop      终止分数
     * @param bool  $withScore 是否返回分数
     * @return int
     */
    public static function zRemRange($key, $start, $stop, $withScore = false)
    {
        return ($withScore === false) ? self::$handler->zRemRangeByRank($key, $start, $stop) :
            self::$handler->zRemRangeByScore($key, $start, $stop);
    }

    /**
     * 查询Key是否存在
     *
     * @param $key
     * @return int
     */
    public static function exists($key)
    {
        return self::$handler->exists($key);
    }

    /**
     * 查询hash表中，对应的字段是否存在
     *
     * @param $key
     * @param $field
     * @return bool
     */
    public static function hExists($key, $field)
    {
        return self::$handler->hExists($key, $field);
    }
}