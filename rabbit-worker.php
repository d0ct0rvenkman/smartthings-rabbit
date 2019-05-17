#!/usr/bin/php -q
<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/globals.php';
require_once __DIR__ . '/include.php';

if (isset($_ENV['REFRESHINTERVAL']))
    define('REFRESHINTERVAL', $_ENV['REFRESHINTERVAL']);
else
    define('REFRESHINTERVAL', 15);


if (isset($_ENV['INFLUXURL'])) {
    define('INFLUXURL', $_ENV['INFLUXURL']);
    $_INFLUXURL = INFLUXURL;
} else {
    echo "InfluxDB URL not present in environment. Exiting.\n";
    exit(1);
}

if (isset($_ENV['INFLUXDATABASE'])) {
    define('INFLUXDATABASE', $_ENV['INFLUXDATABASE']);
} else {
    echo "InfluxDB Database name not present in environment. Exiting.\n";
    exit(1);
}

if (getenv('SLACK_WEBHOOK_URL')) {
    define('SLACK_WEBHOOK_URL', getenv('SLACK_WEBHOOK_URL'));
    echo "Using Slack Webhook URL: " . SLACK_WEBHOOK_URL . "\n";
} else {
    echo "Slack Webhook URL not present in environment. Exiting.\n";
    exit(1);
}

if (getenv('SLACK_CHANNEL')) {
    define('SLACK_CHANNEL', getenv('SLACK_CHANNEL'));
    echo "Using Slack Channel: " . SLACK_CHANNEL . "\n";
} else {
    echo "Slack Webhook Channel not present in environment. Exiting.\n";
    exit(1);
}


define('REFRESHWORKERS', 1);
define('STORAGEWORKERS', 2);
define('SLACKWORKERS', 2);
define('HUEWORKERS', 1);


define('SCRIPTNAME', 'logger-influxdb');

echo "SmartThings InfluDB logger worker starting...\n";
echo "Logging events to Influx Database '" . INFLUXDATABASE . "'.\n";
echo "Refreshing every " . REFRESHINTERVAL . " minutes.\n";


use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;


proc_nice(5);
$_DEBUG = false;
$startuptime = time();

echo date("[Y/m/d H:i:s]") . '  Waiting for log events. To exit press CTRL+C', "\n";


$processSmartThingsEventForInflux = function($msg)
{
    global $_REDIS;

    $channel = $msg->delivery_info['channel'];

    $event = json_decode($msg->body, true);
    $text = $event['value'];
    $deviceID = $event['deviceId'];
    $eventTime = $event['unixTimeMs'] / 1000;
    $timeDiff = round((getmicrotime() - $eventTime), 4);

    list(,$deviceName,$eventType) = explode('.', trim($msg->delivery_info['routing_key']), 3);

    stderr(date("[Y/m/d H:i:s]") . str_pad(getmypid(), 7, " ", STR_PAD_LEFT) . "] Processing '$eventType' message for '$deviceName' (${timeDiff} seconds latency)\n");

    #print_r($event);

    if ($event['value'] == '')
    {
        stderr(date("[Y/m/d H:i:s]") . str_pad(getmypid(), 7, " ", STR_PAD_LEFT) . "]   Skipping message with empty value\n");
        #print_r($event);
        ack($msg);
        return false;
    }

    $_REDIS->hset("ST-devices", $deviceID, ($event['unixTimeMs'] / 1000));
    $_REDIS->hset("ST-device-${deviceID}", $eventType, $msg->body);


    if (setCache("ST-${deviceID}-${eventType}", $msg->body, 300))
    {
        stderr(date("[Y/m/d H:i:s]") . str_pad(getmypid(), 7, " ", STR_PAD_LEFT) . "]   Event successfully cached\n");
        ack($msg);
    }
    else
    {
        stderr(date("[Y/m/d H:i:s]") . str_pad(getmypid(), 7, " ", STR_PAD_LEFT) . "]   Event NOT successfully cached\n");
        nack($msg);
    }

    if (storeSmartThingsEventInInfluxDB($event))
    {
        stderr(date("[Y/m/d H:i:s]") . str_pad(getmypid(), 7, " ", STR_PAD_LEFT) . "]   Event successfully stored to InfluxDB\n");
    }
    else
    {
        stderr(date("[Y/m/d H:i:s]") . str_pad(getmypid(), 7, " ", STR_PAD_LEFT) . "]   Event NOT successfully stored to InfluxDB\n");
    }
};

$processSmartThingsEventForSlack = function($msg)
{
    $channel = $msg->delivery_info['channel'];

    $event = json_decode($msg->body, true);
    $text = $event['value'];


    list(,$deviceName,$eventType) = explode('.', trim($msg->delivery_info['routing_key']), 3);

    echo date("[Y/m/d H:i:s]") . str_pad(getmypid(), 7, " ", STR_PAD_LEFT) . "] Processing '$eventType' message for '$deviceName'\n";

    if ($event['source'] != 'DEVICE')
    {
        echo date("[Y/m/d H:i:s]") . str_pad(getmypid(), 7, " ", STR_PAD_LEFT) . "] Skipping soft-poll event\n";
        ack($msg);
        return;
    }

    switch ($eventType)
    {
        case 'acceleration':
        {
            if ($text == 'active')
            {
                $slackbody = "Sensor *$deviceName* is experiencing acceleration";
            }
            else
            {
                $slackbody = "Sensor *$deviceName* has stopped experiencing acceleration";
            }
            break;
        }
        case 'button':
        {
            $slackbody = "Button *$deviceName* *$text*";
            break;
        }
        case 'contact':
        {
            $slackbody = "Contact sensor *$deviceName* is *$text*";
            break;
        }
        case 'motion':
        {
            $slackbody = "Motion sensor *$deviceName* is *$text*";
            break;
        }

        case 'presence':
        {
            $slackbody = "Presence sensor *$deviceName* is *$text*";
            break;
        }
        case 'switch':
        {
            $slackbody = "Switch *$deviceName* is *$text*";
            break;
        }
        case 'temperature':
        {
            $slackbody = "Temperature at *$deviceName* is *${text}F*";
            break;
        }
        default:
        {
            $slackbody = "Default case: *$deviceName* $text";
            break;
        }
    }

    if (is_null($attachments))
    {
        $attachments = array();
    }

    $attachments[0]["color"] = '#666666';

    if (!is_null($text))
    {
        $attachments[0]["fallback"] = $slackbody;
        $attachments[0]["text"] = $slackbody;
    }

    $attachments[0]["footer"] = MYID;
    $attachments[0]["ts"] = time();

    $payload = array();
    $payload['username'] = 'SmartThings Bridge';
    $payload['icon_url'] = 'http://app.whizscreen.com/app/help/newimg/smartthings.png';
    $payload['text'] = $slackbody;
    $payload['channel'] = SLACK_CHANNEL;
    #$payload['attachments'] = $attachments;

    $message = array('payload' => json_encode($payload));

    $url = SLACK_WEBHOOK_URL;
    $params = array(
        'url' => $url,
        'method' => 'POST',
        'timeout' => 20,
        'post_fields' => $message,
    );

    $request = new CurlRequest;
    $request->init($params);
    $result = $request->exec();

    if ($result['http_code'] == '200')
    {
        echo date("[Y/m/d H:i:s]") . str_pad(getmypid(), 7, " ", STR_PAD_LEFT) . "]   Message successfully delivered to slack\n";
        ack($msg);
    }
    else
    {
        echo date("[Y/m/d H:i:s]") . str_pad(getmypid(), 7, " ", STR_PAD_LEFT) . "]   Message not delivered to slack\n";
        nack($msg);
    }
};


$processSmartThingsEventForHue = function($msg)
{
    $channel = $msg->delivery_info['channel'];

    $event = json_decode($msg->body, true);
    $text = $event['value'];
    $eventTime = $event['unixTimeMs'] / 1000;
    $timeDiff = round((getmicrotime() - $eventTime), 4);



    list(,$deviceName,$eventType) = explode('.', trim($msg->delivery_info['routing_key']), 3);

    stderr(date("[Y/m/d H:i:s]") . str_pad(getmypid(), 7, " ", STR_PAD_LEFT) . "] Processing '$eventType' message for '$deviceName' (${timeDiff} seconds latency)\n");

    if ($event['source'] != 'DEVICE')
    {
        echo date("[Y/m/d H:i:s]") . str_pad(getmypid(), 7, " ", STR_PAD_LEFT) . "] Skipping soft-poll event\n";
        ack($msg);
        return;
    }

    if ($timeDiff > 180) {
        stderr(date("[Y/m/d H:i:s]") . str_pad(getmypid(), 7, " ", STR_PAD_LEFT) . "] Discarding message because of high latency.\n");
        ack($msg);
        return;
    }


    switch ("${eventType}-${text}")
    {
        case 'contact-open':
        {
            $color = "pink";
            break;
        }
        case 'motion-active':
        {
            $color = "blue";
            break;
        }
        default:
        {
            stderr(date("[Y/m/d H:i:s]") . str_pad(getmypid(), 7, " ", STR_PAD_LEFT) . "] This isn't a message I care about.\n");
            ack($msg);
            return;
            break;
        }
    }



    $hueclient = new \Phue\Client(HUE_HOST, HUE_USER);

    $bridge = new \Phue\Command\GetLights();
    $lights = $bridge->send($hueclient);

    $groups = $hueclient->getGroups();
    $AlertGroup = "Alert Group";

    foreach ($groups as $group) {
        $groupName = $group->getName();
        if ( $groupName == $AlertGroup) {
            $AlertGroupID = $group->getId();
            $lightArray = $group->getLightIds();
            break;
        }
    }


    if (!is_array($lightArray)) {
        die("lights isn't an array!\n");
    }

    $colorparams = getColorParameters($color);

    ack($msg);

    $x = new \Phue\Command\SetGroupState($AlertGroupID);
    $y = $x->on(true);
    $hueclient->sendCommand($y);
    #usleep(10000);

    $y = $x->brightness(254);
    $hueclient->sendCommand($y);
    #usleep(10000);

    $y = $x->hue($colorparams['hue']);
    $hueclient->sendCommand($y);
    #usleep(10000);

    $y = $x->saturation($colorparams['sat']);
    $hueclient->sendCommand($y);
    #usleep(10000);

    $y = $x->alert(\Phue\Command\SetLightState::ALERT_LONG_SELECT);
    $hueclient->sendCommand($y);
    sleep(10);
    $y = $x->alert(\Phue\Command\SetLightState::ALERT_NONE);
    $hueclient->sendCommand($y);
    #usleep(10000);

    foreach ($lights as $lightID => $lightObj) {
        if (in_array($lightID, $lightArray)) {
            $brightness = $lightObj->getBrightness();
            $lightObj->setBrightness($brightness);
            #usleep(10000);


            $colorMode = $lightObj->getColorMode();
            #echo "colorMode: $colorMode\n";
            switch ($colorMode) {
                case 'ct': {
                    $colorTemp = $lightObj->getColorTemp();
                    $lightObj->setColorTemp($colorTemp);
                    #usleep(10000);
                    break;
                }
                case 'hs':
                {
                    $hue = $lightObj->getHue();
                    $sat = $lightObj->getSaturation();
                    $lightObj->setHue($hue);
                    #usleep(10000);
                    $lightObj->setSaturation($sat);
                    #usleep(10000);
                    break;
                }
                case 'xy':
                {
                    $xy = $lightObj->getXY();
                    #print_r($xy);
                    $lightObj->setXY($xy['x'], $xy['y']);
                    #usleep(10000);
                    break;
                }
                default: {
                    # this is a legitimate case for the white-only bulbs. they don't have a color mode.
                    break;
                }
            }

            $state = $lightObj->isOn();
            $lightObj->setOn($state);
            #usleep(10000);
        }
    }
};


cli_set_process_title("SmartThings Event Worker [parent]");

$PREFETCHCOUNT = 2;

$PIDs = array();
$forked = 0;
while (true)
{
    # Make sure we can't forkbomb
    usleep(500000);

    if (count($PIDs) < (REFRESHWORKERS + STORAGEWORKERS + SLACKWORKERS + HUEWORKERS))
    {

        $shutdowntimer = rand(1800,3600);
        $RefreshWorkerCount = $StorageWorkerCount = $SlackWorkerCount = 0;
        foreach ($PIDs as $PID => $ChildInfo)
        {
            if ($ChildInfo['type'] == 'refresh')
                $RefreshWorkerCount++;
            if ($ChildInfo['type'] == 'storage')
                $StorageWorkerCount++;
            if ($ChildInfo['type'] == 'slack')
                $SlackWorkerCount++;
            if ($ChildInfo['type'] == 'hue')
                $HueWorkerCount++;
        }

        if ($RefreshWorkerCount < REFRESHWORKERS)
        {
            $WorkerType = 'refresh';
        }
        else {
            if ($StorageWorkerCount < STORAGEWORKERS) {
                $WorkerType = 'storage';
            } else
            if ($SlackWorkerCount < SLACKWORKERS) {
                $WorkerType = 'slack';
            } else
            if ($HueWorkerCount < HUEWORKERS) {
                $WorkerType = 'hue';
            }
        }

        $forked++;
        $pid = pcntl_fork();
        if ($pid == -1)
        {
             die('could not fork');
        }
        else if ($pid)
        {
            // we are the parent
            $PIDs[$pid]['birthtime'] = time();
            $PIDs[$pid]['deathtime'] = time() + $shutdowntimer;
            $PIDs[$pid]['type'] = $WorkerType;
            continue;
        }
        else
        {
            proc_nice(19);
            cli_set_process_title("SmartThings Event Worker [${forked} - ${WorkerType}]");
            define("WORKERID", $forked);

            # do child things
            stderr(date("[Y/m/d H:i:s]") . str_pad(getmypid(), 7, " ", STR_PAD_LEFT) . "] worker ${forked} starting - I will do ${WorkerType} things.\n");
            switch ($WorkerType)
            {
                case 'refresh':
                {
                    initRedis();
                    sleep(60);
                    while (true)
                    {
                        echo date("[Y/m/d H:i:s]") . str_pad(getmypid(), 7, " ", STR_PAD_LEFT) . "]  Doing refresh\n";

                        foreach ($_REDIS->hgetall("ST-devices") as $deviceID => $lastUpdate)
                        {
                            #echo "$deviceID: $lastUpdate\n";
                            if (($lastUpdate + (2 * 28 * 86400)) > time())
                            {
                                foreach ($_REDIS->hgetall("ST-device-${deviceID}") as $key => $value)
                                {
                                    #echo "$key: $value\n";
                                    $event = json_decode($value, true);
                                    echo "  ${event['displayName']}: ${event['name']}  ";
                                    $event['unixTimeMs'] = time() . "000";
                                    if (storeSmartThingsEventInInfluxDB($event, 'refresh'))
                                    {
                                        echo "Event successfully refreshed in InfluxDB\n";
                                    }
                                    else
                                    {
                                        echo "Event not successfully refreshed in InfluxDB\n";
                                    }
                                }
                            }
                        }
                        echo date("[Y/m/d H:i:s]") . str_pad(getmypid(), 7, " ", STR_PAD_LEFT) . "]  Finished Refreshing\n";
                        sleep((REFRESHINTERVAL * 60));

                    }
                    exit;
                    break;
                }
                case 'storage':
                {
                    stderr(date("[Y/m/d H:i:s]") . str_pad(getmypid(), 7, " ", STR_PAD_LEFT) . "] I will shut myself down in $shutdowntimer seconds\n");

                    $timeouts = array();
                    $timeouts['keepalive'] = time() + 59;
                    $timeouts['shutdown'] = time() + $shutdowntimer;

                    initRedis();
                    $_AMQPCONNECTION = new AMQPStreamConnection($_RMQSERVER, $_RMQPORT, $_RMQUSER, $_RMQPASS, $_RMQVHOST);
                    $datachannel = $_AMQPCONNECTION->channel();

                    $datachannel->basic_qos(null, $PREFETCHCOUNT, null);
                    $datachannel->basic_consume('events.influxdb', MYID . '-' . WORKERID, false, false, false, false, $processSmartThingsEventForInflux);

                    while(count($datachannel->callbacks))
                    {
                        if (time() > $timeouts['shutdown'] )
                        {
                            stderr(date("[Y/m/d H:i:s]") . str_pad(getmypid(), 7, " ", STR_PAD_LEFT) . "] Shutdown timer has elapsed. Exiting.\n");
                            exit;
                        }

                        if (time() > $timeouts['keepalive'] )
                        {
                            #echo date("[Y/m/d H:i:s]") . "  Sending keepalive\n";
                            $keepalivemsg = new AMQPMessage(time());
                            $datachannel->basic_publish($keepalivemsg, 'amq.direct', "keepalive");

                            $timeouts['keepalive'] = time() + 59;
                        }

                        $idle = false;
                        $start = getmicrotime();

                        do
                        {
                            try
                            {
                                $datachannel->wait(null, true, 1.0);
                            }
                            catch (Exception $e)
                            {
                                $idle = true;
                            }
                        } while (!$idle);

                    }

                    $datachannel->close();
                    $_AMQPCONNECTION->close();
                    exit;
                }
                case 'slack':
                {
                    stderr(date("[Y/m/d H:i:s]") . str_pad(getmypid(), 7, " ", STR_PAD_LEFT) . "] I will shut myself down in $shutdowntimer seconds\n");

                    $_AMQPCONNECTION = new AMQPStreamConnection($_RMQSERVER, $_RMQPORT, $_RMQUSER, $_RMQPASS, $_RMQVHOST);
                    $datachannel = $_AMQPCONNECTION->channel();

                    $datachannel->basic_qos(null, $PREFETCHCOUNT, null);
                    $datachannel->basic_consume('events.slack', MYID . '-' . WORKERID, false, false, false, false, $processSmartThingsEventForSlack);

                    $timeouts = array();
                    $timeouts['keepalive'] = time() + 59;
                    $timeouts['shutdown'] = time() + $shutdowntimer;

                    while(count($datachannel->callbacks)) {
                        if (time() > $timeouts['shutdown'] )
                        {
                            stderr(date("[Y/m/d H:i:s]") . str_pad(getmypid(), 7, " ", STR_PAD_LEFT) . "] Shutdown timer has elapsed. Exiting.\n");
                            exit;
                        }

                        if (time() > $timeouts['keepalive'] )
                        {
                            #echo date("[Y/m/d H:i:s]") . "  Sending keepalive\n";
                            $keepalivemsg = new AMQPMessage(time());
                            $datachannel->basic_publish($keepalivemsg, 'amq.direct', "keepalive");

                            $timeouts['keepalive'] = time() + 59;
                        }

                        $idle = false;
                        $start = getmicrotime();

                        do
                        {
                            try
                            {
                                $datachannel->wait(null, true, 1.0);
                            }
                            catch (Exception $e)
                            {
                                $idle = true;
                            }
                        } while (!$idle);

                    }

                    $datachannel->close();
                    $_AMQPCONNECTION->close();

                    exit;
                    break;
                }
                case 'hue': {
                    stderr(date("[Y/m/d H:i:s]") . str_pad(getmypid(), 7, " ", STR_PAD_LEFT) . "] I will shut myself down in $shutdowntimer seconds\n");

                    $_AMQPCONNECTION = new AMQPStreamConnection($_RMQSERVER, $_RMQPORT, $_RMQUSER, $_RMQPASS, $_RMQVHOST);
                    $datachannel = $_AMQPCONNECTION->channel();

                    $datachannel->basic_qos(null, $PREFETCHCOUNT, null);
                    $datachannel->basic_consume('events.hue', MYID . '-' . WORKERID, false, false, false, false, $processSmartThingsEventForHue);

                    $timeouts = array();
                    $timeouts['keepalive'] = time() + 59;
                    $timeouts['shutdown'] = time() + $shutdowntimer;

                    while(count($datachannel->callbacks)) {
                        if (time() > $timeouts['shutdown'] )
                        {
                            stderr(date("[Y/m/d H:i:s]") . str_pad(getmypid(), 7, " ", STR_PAD_LEFT) . "] Shutdown timer has elapsed. Exiting.\n");
                            exit;
                        }

                        if (time() > $timeouts['keepalive'] )
                        {
                            #echo date("[Y/m/d H:i:s]") . "  Sending keepalive\n";
                            $keepalivemsg = new AMQPMessage(time());
                            $datachannel->basic_publish($keepalivemsg, 'amq.direct', "keepalive");

                            $timeouts['keepalive'] = time() + 59;
                        }

                        $idle = false;
                        $start = getmicrotime();

                        do
                        {
                            try
                            {
                                $datachannel->wait(null, true, 1.0);
                            }
                            catch (Exception $e)
                            {
                                $idle = true;
                            }
                        } while (!$idle);

                    }

                    $datachannel->close();
                    $_AMQPCONNECTION->close();

                    exit;
                    break;
                }
                default:
                {
                    stderr(date("[Y/m/d H:i:s]") . str_pad(getmypid(), 7, " ", STR_PAD_LEFT) . "] I forked but I don't know what kind of a worker I am. Exiting.\n");
                    sleep(1);
                    exit;
                }
            }
        }
    }
    else
    {
        $childpid = pcntl_wait($status, WNOHANG); //Protect against Zombie children
        if ($childpid > 0)
        {
            stderr(date("[Y/m/d H:i:s]") . " parent] RIP worker ${childpid}\n");
            unset($PIDs[$childpid]);
        } else {
            # check for children overstaying their welcome
            foreach ($PIDs as $PID => $PIDInfo)
            {
                if ($PIDInfo['type'] == 'refresh')
                    continue;

                if (time() > ($PIDInfo['deathtime'] + 30))
                {
                    stderr(date("[Y/m/d H:i:s]") . " parent] Killing ${PID} for overstaying its welcome\n");
                    posix_kill ($PID, SIGKILL);
                }
            }
        }
    }

}
