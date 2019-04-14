<?php
# make getmicrotime() sensitive again
#ini_set ( 'precision' , '20' );
date_default_timezone_set('UTC');

define('MYID', str_replace('.spacecricket.net', '', gethostname()) . "-" . getmypid());


if (isset($_ENV['REDISIP']))
    define('REDISIP', $_ENV['REDISIP']);
else
    define('REDISIP', '127.0.0.1');

if (isset($_ENV['REDISPORT']))
    define('REDISPORT', $_ENV['REDISPORT']);
else
    define('REDISPORT', '6379');

define('REDISRETRIES', 3);
define('CACHEAUTHORITY', 'redis');
define('CACHETOMEMCACHE', false);
define('CACHETOREDIS', true);

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

if (file_exists('/etc/esg/datasources.php'))
    require_once '/etc/esg/datasources.php';
else
    require_once __DIR__ . '/datasources.php';

