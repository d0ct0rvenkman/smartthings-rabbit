#!/usr/bin/php -q
<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../globals.php';
require_once __DIR__ . '/../include.php';

define('SCRIPTNAME', 'logger-slack');

echo "Slack logger worker starting...\n";

if (getenv('SLACK_WEBHOOK_URL'))
{
    define('SLACK_WEBHOOK_URL', getenv('SLACK_WEBHOOK_URL'));
    echo "Using Slack Webhook URL: " . SLACK_WEBHOOK_URL . "\n";
}
else
{
    echo "Slack Webhook URL not present in environment. Exiting.\n";
    exit(1);
}

if (getenv('SLACK_CHANNEL'))
{
    define('SLACK_CHANNEL', getenv('SLACK_CHANNEL'));
    echo "Using Slack Channel: " . SLACK_CHANNEL . "\n";
}
else
{
    echo "Slack Webhook Channel not present in environment. Exiting.\n";
    exit(1);
}

define('WORKERS', 2);


use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;


proc_nice(5);
$_DEBUG = false;
$startuptime = time();

echo date("[Y/m/d H:i:s]") . str_pad(getmypid(), 7, " ", STR_PAD_LEFT) . '] Waiting for log events. To exit press CTRL+C', "\n";


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
        echo date("[Y/m/d H:i:s]") . str_pad(getmypid(), 7, " ", STR_PAD_LEFT) . "] Message successfully delivered\n";
        ack($msg);
    }
    else
    {
        echo date("[Y/m/d H:i:s]") . str_pad(getmypid(), 7, " ", STR_PAD_LEFT) . "] Message not delivered\n";
        nack($msg);
    }
};


$PIDs = array();
$forked = 0;
while (true)
{
    if (count($PIDs) < WORKERS)
    {
        $RefreshWorkerCount = 0;
        foreach ($PIDs as $PID => $ChildInfo)
        {
            if ($ChildInfo['type'] == 'refresh')
                $RefreshWorkerCount++;
        }

        if ($RefreshWorkerCount == 0)
        {
            $WorkerType = 'refresh';
        }
        else {
            $WorkerType = 'storage';
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
            $PIDs[$pid]['birthtime'] = $WorkerType;
            $PIDs[$pid]['type'] = $WorkerType;
            continue;
        }
        else
        {
            proc_nice(19);

            # do child things
            define("WORKERID", $forked);

            $shutdowntimer = rand(1800,3600);
            stderr(date("[Y/m/d H:i:s]") . str_pad(getmypid(), 7, " ", STR_PAD_LEFT) . "] I will shut myself down in $shutdowntimer seconds\n");



            $_AMQPCONNECTION = new AMQPStreamConnection($_RMQSERVER, $_RMQPORT, $_RMQUSER, $_RMQPASS, $_RMQVHOST);
            $datachannel = $_AMQPCONNECTION->channel();

            $datachannel->basic_qos(null, 50, null);
            $datachannel->basic_consume('events.slack', MYID, false, false, false, false, $processSmartThingsEventForSlack);

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

        }
    }
    else
    {
        $childpid = pcntl_wait($status); //Protect against Zombie children
        stderr(date("[Y/m/d H:i:s]") . " parent] RIP worker ${childpid}\n");
        unset($PIDs[$childpid]);
        usleep(500000);
    }
}