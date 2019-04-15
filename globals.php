<?php
# make getmicrotime() sensitive again
#ini_set ( 'precision' , '20' );
date_default_timezone_set('UTC');

define('MYID', str_replace('.spacecricket.net', '', gethostname()) . "-" . getmypid());

global $_AMQPCONNECTION;
global $_RMQ;
global $_RMQSERVER;
global $_RMQPORT;
global $_RMQUSER;
global $_RMQPASS;
global $_RMQVHOST;
global $_INFLUXURL;
global $_REDIS;
global $_DEBUG;

$_AMQPCONNECTION = null;

$_DEBUG = false;
$_REDIS = false;

if (isset($_ENV['REDISIP'])) {
    define('REDISIP', $_ENV['REDISIP']);
} else {
    echo "Redis IP is not present in environment. Exiting.\n";
    exit(1);
}

if (isset($_ENV['REDISPORT'])) {
    define('REDISPORT', $_ENV['REDISPORT']);
} else {
    define('REDISPORT', '6379');
}

if (isset($_ENV['RMQSERVER'])) {
    define('RMQSERVER', $_ENV['RMQSERVER']);
} else {
    echo "RabbitMQ server IP is not present in environment. Exiting.\n";
    exit(1);
}

if (isset($_ENV['RMQPORT'])) {
    define('RMQPORT', $_ENV['RMQPORT']);
} else {
    define('RMQPORT', '5672');
}

if (isset($_ENV['RMQUSER'])) {
    define('RMQUSER', $_ENV['RMQUSER']);
} else {
    echo "RabbitMQ user is not present in environment. Exiting.\n";
    exit(1);
}

if (isset($_ENV['RMQPASS'])) {
    define('RMQPASS', $_ENV['RMQPASS']);
} else {
    echo "RabbitMQ password is not present in environment. Exiting.\n";
    exit(1);
}

if (isset($_ENV['RMQVHOST'])) {
    define('RMQVHOST', $_ENV['RMQVHOST']);
}
else {
    echo "RabbitMQ vhost is not present in environment. Exiting.\n";
    exit(1);
}

$_RMQSERVER = RMQSERVER;
$_RMQPORT = RMQPORT;
$_RMQUSER = RMQUSER;
$_RMQPASS = RMQPASS;
$_RMQVHOST = RMQVHOST;


define('REDISRETRIES', 3);
define('CACHEAUTHORITY', 'redis');
define('CACHETOMEMCACHE', false);
define('CACHETOREDIS', true);

