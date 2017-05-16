<?php

namespace App\MyClass;

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;


class MyRabbitmq
{
    public $connection;
    public $channel;
    public $ex;

    public $queue;//队列名
    public $exchange;//交换机名
    public $messsage;//消息

    public function __construct($queue,$exchange,$message)
    {
        $this->queue = $queue;
        $this->exchange = $exchange;
        $this->messsage = $message;
    }
    //创建链接
    public function setConnect()
    {
        $conn_args = array(
            'host' => env('RABBITMQ_HOST','127.0.0.1'),
            'port' => env('RABBITMQ_PORT',5672),
            'login' => env('RABBITMQ_LOGIN','admin'),
            'password' => env('RABBITMQ_PASSWORD','admin'),
            'vhost'=>'/'
        );

        $this->connection = new \AMQPConnection($conn_args);
        return $this;
    }

    //创建channel
    public function setChannel()
    {
        $this->channel = new \AMQPChannel($this->connection);
        return $this;
    }

    //创建交换机 队列
    public function setExchange()
    {
        $ex = new \AMQPExchange($this->channel);
        $ex->setName($this->exchange);
        $ex->setType(AMQP_EX_TYPE_DIRECT); //direct类型
        $ex->setFlags(AMQP_DURABLE); //持久化
        $ex->declareExchange();
        $this->ex = $ex;

        return $this;
    }

    //创建队列
    public function setQueue()
    {
        $q = new \AMQPQueue($this->channel);
        $q->setName($this->queue);
        $q->setFlags(AMQP_DURABLE); //持久化
        $q->declareQueue();

        $q->bind($this->exchange,$this->queue);

        return $this;
    }

    public function publish()
    {
        $this->ex->publish($this->messsage, $this->queue);
        return $this;
    }

}