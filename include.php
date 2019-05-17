<?php

# include classes, because that's a thing
use phpcassa\ColumnFamily;
use phpcassa\SuperColumnFamily;
use phpcassa\ColumnSlice;
use phpcassa\Connection\ConnectionPool;

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

function getmicrotime()
{
        list($usec, $sec) = explode(" ",microtime());
        return ((float)$usec + (float)$sec);
}

function blab($message)
{
    global $_DEBUG;
    if ($_DEBUG) echo $message;
    return;
}

function stderr($msg)
{
    fwrite(STDERR, $msg);
}

function initRedis()
{
    global $_REDIS;
    global $_TWEMPROXIES;

    # TODO: this should probably be a bit more complete. timeouts, multiple servers, etc

    if ($_REDIS === false)
    {
        if (is_array($_TWEMPROXIES) && (count($_TWEMPROXIES) > 0))
        {
            $Info = $_TWEMPROXIES[array_rand($_TWEMPROXIES)];
            $IP =  $Info['ip'];
            $Port = $Info['port'];
        }
        else
        {
            $IP = REDISIP;
            $Port = REDISPORT;
        }

        $_REDIS = new Redis();
        $_REDIS->pconnect($IP, $Port);
    }
}



# Thanks!
# http://php.net/manual/en/function.curl-exec.php#80442
# http://php.net/manual/en/function.curl-exec.php#83676
class CurlRequest
{
    private $ch;
    /**
     * Init curl session
     *
     * $params = array('url' => '',
     *                    'host' => '',
     *                   'header' => '',
     *                   'method' => '',
     *                   'referer' => '',
     *                   'cookie' => '',
     *                   'post_fields' => '',
     *                    ['login' => '',]
     *                    ['password' => '',]
     *                   'timeout' => 0
     *                   );
     */
    public function init($params)
    {
        $this->ch = curl_init();
        $user_agent = 'CurlRequest class';

#        $header = array(
#        "Accept: text/xml,application/xml,application/xhtml+xml,text/html;q=0.9,text/plain;q=0.8,image/png,*/*;q=0.5",
#        "Accept-Language: ru-ru,ru;q=0.7,en-us;q=0.5,en;q=0.3",
#        "Accept-Charset: windows-1251,utf-8;q=0.7,*;q=0.7",
#        "Keep-Alive: 300");

        if (isset($params['host']) && $params['host'])      $header[]="Host: " . $params['host'];
        if (isset($params['header']) && $params['header']) $header[]=$params['header'];

        @curl_setopt ( $this -> ch , CURLOPT_ENCODING, "");
        @curl_setopt ( $this -> ch , CURLOPT_RETURNTRANSFER , 1 );
        @curl_setopt ( $this -> ch , CURLOPT_VERBOSE , 0 );
        @curl_setopt ( $this -> ch , CURLOPT_HEADER , 1 );

        if ($params['method'] == "HEAD") @curl_setopt($this -> ch,CURLOPT_NOBODY,1);
        @curl_setopt ( $this -> ch, CURLOPT_FOLLOWLOCATION, 1);
        @curl_setopt ( $this -> ch , CURLOPT_HTTPHEADER, $header );
        if ($params['referer'])    @curl_setopt ($this -> ch , CURLOPT_REFERER, $params['referer'] );
        @curl_setopt ( $this -> ch , CURLOPT_USERAGENT, $user_agent);
        if ($params['cookie'])    @curl_setopt ($this -> ch , CURLOPT_COOKIE, $params['cookie']);

        if ( $params['method'] == "POST" )
        {
            curl_setopt( $this -> ch, CURLOPT_POST, true );
            curl_setopt( $this -> ch, CURLOPT_POSTFIELDS, $params['post_fields'] );
        }
        if ($params['method'] == "DELETE")
        {
            curl_setopt($this -> ch, CURLOPT_CUSTOMREQUEST, "DELETE");
        }
        @curl_setopt( $this -> ch, CURLOPT_URL, $params['url']);
        @curl_setopt ( $this -> ch , CURLOPT_SSL_VERIFYPEER, 0 );
        @curl_setopt ( $this -> ch , CURLOPT_SSL_VERIFYHOST, 0 );
        if (isset($params['login']) & isset($params['password']))
            @curl_setopt($this -> ch , CURLOPT_USERPWD,$params['login'].':'.$params['password']);
        @curl_setopt ( $this -> ch , CURLOPT_TIMEOUT, $params['timeout']);
    }

    /**
     * Make curl request
     *
     * @return array  'header','body','http_code','last_url'
     */
    public function exec()
    {
        $response = curl_exec($this->ch);
        $error = curl_error($this->ch);
        $result = array( 'header' => '',
                         'body' => '',
                         'http_code' => '',
                         'last_url' => '');
        if ( $error != "" )
        {
            $result['curl_error'] = $error;
            return $result;
        }

        $header_size = curl_getinfo($this->ch,CURLINFO_HEADER_SIZE);
        $result['header'] = substr($response, 0, $header_size);
        $result['body'] = substr( $response, $header_size );
        $result['http_code'] = curl_getinfo($this -> ch,CURLINFO_HTTP_CODE);
        $result['last_url'] = curl_getinfo($this -> ch,CURLINFO_EFFECTIVE_URL);
        return $result;
    }
}




function getQueueStats($queue)
{
    global $_RMQSERVER;
    global $_RMQUSER;
    global $_RMQPASS;
    global $_RMQVHOST;

    $url = "http://${_RMQSERVER}:15672/api/queues/${_RMQVHOST}/${queue}";

    $params = array(
        'url' => $url,
        'method' => 'GET',
        'timeout' => 20,
        'login' => $_RMQUSER,
        'password' => $_RMQPASS,
    );

    $request = new CurlRequest;
    $request->init($params);
    $result = $request->exec();

    if (isset($result['curl_error']))
    {
        return false;
    }
    else
    {
        return json_decode($result['body'], true);
    }

}

function ack (&$message)
{
    return $message->delivery_info['channel']->basic_ack($message->delivery_info['delivery_tag']);
}

function nack(&$message)
{
    return $message->delivery_info['channel']->basic_nack($message->delivery_info['delivery_tag']);
}


function enqueueSystemLog(&$channel, $facility = MYID, $level = 'debug', $text = null, $attachments = null)
{
    $payload = array();
    $payload['username'] = SCRIPTNAME;

    # TODO: Put this somewhere more appropriate
    $payload['icon_emoji'] = ':eve_online:';

    if (!is_null($text))
        $payload['text'] = $text;
    if (!is_null($attachments))
        $payload['attachments'] = $attachments;

    $message = new AMQPMessage(json_encode($payload));
    $channel->basic_publish($message, 'systemlog', "${facility}.${level}");
}

function systemLog(&$channel, $level = 'debug', $text = null, $attachments = null)
{
    if (is_null($text) && is_null($attachments))
        return false;

    switch($level)
    {
        case 'emerg':
        {
            $color = '#660000';
            break;
        }
        case 'alert':
        {
            $color = '#aa0000';
            break;
        }
        case 'error':
        {
            $color = '#ff0000';
            break;
        }
        case 'warning':
        {
            $color = '#ffcc5c';
            break;
        }
        case 'notice':
        {
            $color = '#000000';
            break;
        }
        case 'info':
        {
            $color = '#aaaaaa';
            break;
        }
        case 'debug':
        default:
        {
            $color = '#666666';
            break;
        }
    }

    if (is_null($attachments))
    {
        $attachments = array();
    }

    $attachments[0]["color"] = $color;

    if (!is_null($text))
    {
        $attachments[0]["fallback"] = $text;
        $attachments[0]["text"] = $text;
    }

    $attachments[0]["footer"] = MYID;
    $attachments[0]["ts"] = time();

    return enqueueSystemLog($channel, SCRIPTNAME, $level, null, $attachments);
}

function createInfluxDB($db)
{
    global $_INFLUXURL;

    # I hate grafana so much for making me define all of these
    $RPs = array(
        '1s' => '7200s',
        '5s' => '36000s',
        '2s' => '14400s',
        '10s' => '72000s',
        '20s' => '144000s',
        '30s' => '216000s',
        '1m' => '7200m',
        '2m' => '14000m',
        '10m' => '72000m',
        '20m' => '144000m',
        '30m' => '216000m',
        '1h' => '7200h',
        '2h' => '14400h',
        '3h' => '21600h',
        '6h' => '43200h',
        '12h' => '86400h',
    );

    $queries = array();
    $queries[] = "q=CREATE DATABASE $db WITH DURATION 1h REPLICATION 1 SHARD DURATION 10m NAME \"fullres\"";
    #$queries[] = "q=CREATE RETENTION POLICY \"fullres\" ON \"$db\" DURATION 1h REPLICATION 1 DEFAULT";

    #foreach ($RPs as $RP => $duration)
    #{
    #    $queries[] = "q=CREATE RETENTION POLICY \"${RP}\" ON \"$db\" DURATION ${duration} REPLICATION 1";
    #    $queries[] = "q=CREATE CONTINUOUS QUERY apicalls_${RP}  ON esg BEGIN SELECT mean(*) INTO esg.\"${RP}\".:MEASUREMENT  FROM esg.fullres./.*/ GROUP BY time(${RP}), * END";
    #}



    $url = "${_INFLUXURL}/query";

    foreach ($queries as $id => $query)
    {
        $params = array(
            'url' => $url,
            'method' => 'POST',
            'timeout' => 20,
            'post_fields' => $query,
        );

        blab("$url   $query\n");
        $request = new CurlRequest;
        $request->init($params);
        $result = $request->exec();

        if (($result['http_code'] == '204') || ($result['http_code'] == '200') )
        {
            blab("InfluxDB post success!\n");
        }
        else
        {
            blab("InfluxDB post return code ${result['http_code']}\n");
            return false;
        }
    }
}

function postMetricsToInfluxDB($input)
{
    global $_INFLUXURL;

    $db = INFLUXDATABASE;

    if (is_array($input))
    {
        $query = "";
        foreach ($input as $id => $line)
        {
            $query .= trim($line) . "\n";
        }
    }
    else
    {
        $query = $input;
    }

    $url = "${_INFLUXURL}/write?db=${db}";
    $params = array(
        'url' => $url,
        'method' => 'POST',
        'timeout' => 20,
        'post_fields' => $query,
    );

    $request = new CurlRequest;
    $request->init($params);
    $result = $request->exec();

    if ($result['http_code'] == '204')
    {
        blab("InfluxDB post success!\n");
        return true;
    }
    else {
        blab("InfluxDB post return code ${result['http_code']}\n");
        print_r($result);
        return false;
    }
}


function sendHeartbeat(&$channel)
{
    $message = new AMQPMessage(MYID);
    $channel->basic_publish($message, 'aliveness', SCRIPTNAME);
}



function setCache($key, $value, $TTL = null)
{
    if (CACHETOMEMCACHE)
    {
        global $_MEMCACHE;

        for ($x = 0; $x < MEMCACHERETRIES; $x++)
        {
            try
            {
                $memcachereturn = $_MEMCACHE->set($key, $value, false, $TTL);
                break;
            }
            catch (Exception $e)
            {
                $memcachereturn = false;
            }
        }
    }

    if (CACHETOREDIS)
    {
        global $_REDIS;

        if (is_null($TTL))
        {
            for ($x = 0; $x < REDISRETRIES; $x++)
            {
                try
                {
                    $redisreturn = $_REDIS->set($key, json_encode($value));
                    break;
                }
                catch (Exception $e)
                {
                    $redisreturn = false;
                }
            }
        }
        else
        {
            for ($x = 0; $x < REDISRETRIES; $x++)
            {
                try
                {
                    $redisreturn = $_REDIS->setEx($key, $TTL, json_encode($value));
                    break;
                }
                catch (Exception $e)
                {
                    $redisreturn = false;
                }
            }
        }
    }

    switch (CACHEAUTHORITY)
    {
        case 'memcache':
        {
            return $memcachereturn;
            break;
        }
        case 'redis':
        {
            return $redisreturn;
            break;
        }
        default:
        {
            #wut?
            return false;
        }
    }
}

function getCache($key)
{
    switch (CACHEAUTHORITY)
    {
        case 'memcache':
        {
            global $_MEMCACHE;

            for ($x = 0; $x < MEMCACHERETRIES; $x++)
            {
                try
                {
                    $ret = $_MEMCACHE->get($key);
                    break;
                }
                catch (Exception $e)
                {
                    $ret = null;
                }
            }
            break;
        }
        case 'redis':
        {
            global $_REDIS;

            for ($x = 0; $x < REDISRETRIES; $x++)
            {
                try
                {
                    $ret = json_decode($_REDIS->get($key), true);
                    break;
                }
                catch (Exception $e)
                {
                    $ret = null;
                }
            }

            break;
        }
        default:
        {
            #wut?
            $ret = null;
            break;
        }
    }

    if (is_null($ret))
        return false;
    else
        return $ret;
}

function escapeStringForInfluxDB($str)
{
    if (!is_null($str)) {
        $str = str_replace(" ", "\\ ", $str); // Escape spaces.
        $str = str_replace(",", "\\,", $str); // Escape commas.
        $str = str_replace("=", "\\=", $str); // Escape equal signs.
        $str = str_replace("\"", "\\\"", $str); // Escape double quotes.
        //$str = str_replace("'", "_", $str);  // Replace apostrophes with underscores.
    }
    else {
        $str = 'null';
    }
    return $str;
}

function storeSmartThingsEventInInfluxDB($event, $eventType = null)
{
    /*
    // Event Format (probably)
    {
      "id": "6e9df588-4793-11e9-8b55-0de72b467c6d",
      "data": "{\"microDeviceTile\":{\"type\":\"value\",\"label\":\"60\u00b0\",\"backgroundColor\":\"#8ad09e\"}}",
      "description": null,
      "displayName": "Living Room North Window",
      "deviceId": "33a9aab0-98c3-4a56-b771-35247818943e",
      "hubId": "31988017-e101-411d-8b0b-e585876f220d",
      "isStateChange": true,
      "locationId": "53fa74f8-75c6-4534-a994-580c8c1c565b",
      "name": "temperature",
      "source": "DEVICE",
      "unit": "F",
      "unixTimeMs": 1552703415312,
      "value": "60",
      "groupId": "8e8b7d07-48cd-4df1-8ef9-d20b10f81fe4",
      "groupName": "Living Room",
      "hubName": "Home Hub",
      "locationName": "Home"
    }
    */

    if (is_null($eventType))
    {
        $eventType = escapeStringForInfluxDB($event['source']);
    }
    else
    {
        $eventType = escapeStringForInfluxDB($eventType);
    }


    $measurement = $event['name'];
    // tags:
    $deviceId = escapeStringForInfluxDB($event['deviceId']);
    $deviceName = escapeStringForInfluxDB($event['displayName']);
    $groupId = escapeStringForInfluxDB($event['.device.device.groupId']);
    $groupName = escapeStringForInfluxDB($event['groupName']);
    $hubId = escapeStringForInfluxDB($event['hubId']);
    $hubName = escapeStringForInfluxDB($event['hubName']);
    $locationId = escapeStringForInfluxDB($event['locationId']);
    $locationName = escapeStringForInfluxDB($event['locationName']);

    $unit = escapeStringForInfluxDB($event['unit']);
    $value = escapeStringForInfluxDB($event['value']);
    $valueBinary = '';
    $timestamp = (int)(escapeStringForInfluxDB($event['unixTimeMs']) * 1000000); // apparently influx timestamps are very small

    if (($value == '') or ($value == 'null'))
    {
        echo "   *** Bad value: '${value}' ***   ";
        #print_r($event);
        return false;
    }

    $eventTime = $event['unixTimeMs'] / 1000;
    $latency = round((getmicrotime() - $eventTime), 4);

    $data = "latency,eventType=${eventType},deviceId=${deviceId},deviceName=${deviceName},groupId=${groupId},groupName=${groupName},hubId=${hubId},hubName=${hubName},locationId=${locationId},locationName=${locationName} value=${latency} ${timestamp}";
    postMetricsToInfluxDB($data);

    $data = "${measurement},eventType=${eventType},deviceId=${deviceId},deviceName=${deviceName},groupId=${groupId},groupName=${groupName},hubId=${hubId},hubName=${hubName},locationId=${locationId},locationName=${locationName}";

    // Unit tag and fields depend on the event type:
    //  Most string-valued attributes can be translated to a binary value too.
    if ('acceleration' == $event['name']) { // acceleration: Calculate a binary value (active = 1, inactive = 0)
        $unit = 'acceleration';
        $value = '"' . $value . '"';
        $valueBinary = ('active' == $event['value'] ? '1i' : '0i');
        $data .= ",unit=${unit} value=${value},valueBinary=${valueBinary}";
    }
    else if ('alarm' == $event['name']) { // alarm: Calculate a binary value (strobe/siren/both = 1, off = 0)
        $unit = 'alarm';
        $value = '"' . $value . '"';
        $valueBinary = ('off' == $event['value'] ? '0i' : '1i');
        $data .= ",unit=${unit} value=${value},valueBinary=${valueBinary}";
    }
    else if ('button' == $event['name']) { // button: Calculate a binary value (held = 1, pushed = 0)
        $unit = 'button';
        $value = '"' . $value . '"';
        $valueBinary = ('pushed' == $event['value'] ? '0i' : '1i');
        $data .= ",unit=${unit} value=${value},valueBinary=${valueBinary}";
    }
    else if ('carbonMonoxide' == $event['name']) { // carbonMonoxide: Calculate a binary value (detected = 1, clear/tested = 0)
        $unit = 'carbonMonoxide';
        $value = '"' . $value . '"';
        $valueBinary = ('detected' == $event['value'] ? '1i' : '0i');
        $data .= ",unit=${unit} value=${value},valueBinary=${valueBinary}";
    }
    else if ('consumableStatus' == $event['name']) { // consumableStatus: Calculate a binary value ("good" = 1, "missing"/"replace"/"maintenance_required"/"order" = 0)
        $unit = 'consumableStatus';
        $value = '"' . $value . '"';
        $valueBinary = ('good' == $event['value'] ? '1i' : '0i');
        $data .= ",unit=${unit} value=${value},valueBinary=${valueBinary}";
    }
    else if ('contact' == $event['name']) { // contact: Calculate a binary value (closed = 1, open = 0)
        $unit = 'contact';
        $value = '"' . $value . '"';
        $valueBinary = ('closed' == $event['value'] ? '1i' : '0i');
        $data .= ",unit=${unit} value=${value},valueBinary=${valueBinary}";
    }
    else if ('door' == $event['name']) { // door: Calculate a binary value (closed = 1, open/opening/closing/unknown = 0)
        $unit = 'door';
        $value = '"' . $value . '"';
        $valueBinary = ('closed' == $event['value'] ? '1i' : '0i');
        $data .= ",unit=${unit} value=${value},valueBinary=${valueBinary}";
    }
    else if ('lock' == $event['name']) { // door: Calculate a binary value (locked = 1, unlocked = 0)
        $unit = 'lock';
        $value = '"' . $value . '"';
        $valueBinary = ('locked' == $event['value'] ? '1i' : '0i');
        $data .= ",unit=${unit} value=${value},valueBinary=${valueBinary}";
    }
    else if ('motion' == $event['name']) { // Motion: Calculate a binary value (active = 1, inactive = 0)
        $unit = 'motion';
        $value = '"' . $value . '"';
        $valueBinary = ('active' == $event['value'] ? '1i' : '0i');
        $data .= ",unit=${unit} value=${value},valueBinary=${valueBinary}";
    }
    else if ('mute' == $event['name']) { // mute: Calculate a binary value (muted = 1, unmuted = 0)
        $unit = 'mute';
        $value = '"' . $value . '"';
        $valueBinary = ('muted' == $event['value'] ? '1i' : '0i');
        $data .= ",unit=${unit} value=${value},valueBinary=${valueBinary}";
    }
    else if ('presence' == $event['name']) { // presence: Calculate a binary value (present = 1, not present = 0)
        $unit = 'presence';
        $value = '"' . $value . '"';
        $valueBinary = ('present' == $event['value'] ? '1i' : '0i');
        $data .= ",unit=${unit} value=${value},valueBinary=${valueBinary}";
    }
    else if ('shock' == $event['name']) { // shock: Calculate a binary value (detected = 1, clear = 0)
        $unit = 'shock';
        $value = '"' . $value . '"';
        $valueBinary = ('detected' == $event['value'] ? '1i' : '0i');
        $data .= ",unit=${unit} value=${value},valueBinary=${valueBinary}";
    }
    else if ('sleeping' == $event['name']) { // sleeping: Calculate a binary value (sleeping = 1, not sleeping = 0)
        $unit = 'sleeping';
        $value = '"' . $value . '"';
        $valueBinary = ('sleeping' == $event['value'] ? '1i' : '0i');
        $data .= ",unit=${unit} value=${value},valueBinary=${valueBinary}";
    }
    else if ('smoke' == $event['name']) { // smoke: Calculate a binary value (detected = 1, clear/tested = 0)
        $unit = 'smoke';
        $value = '"' . $value . '"';
        $valueBinary = ('detected' == $event['value'] ? '1i' : '0i');
        $data .= ",unit=${unit} value=${value},valueBinary=${valueBinary}";
    }
    else if ('sound' == $event['name']) { // sound: Calculate a binary value (detected = 1, not detected = 0)
        $unit = 'sound';
        $value = '"' . $value . '"';
        $valueBinary = ('detected' == $event['value'] ? '1i' : '0i');
        $data .= ",unit=${unit} value=${value},valueBinary=${valueBinary}";
    }
    else if ('switch' == $event['name']) { // switch: Calculate a binary value (on = 1, off = 0)
        $unit = 'switch';
        $value = '"' . $value . '"';
        $valueBinary = ('on' == $event['value'] ? '1i' : '0i');
        $data .= ",unit=${unit} value=${value},valueBinary=${valueBinary}";
    }
    else if ('tamper' == $event['name']) { // tamper: Calculate a binary value (detected = 1, clear = 0)
        $unit = 'tamper';
        $value = '"' . $value . '"';
        $valueBinary = ('detected' == $event['value'] ? '1i' : '0i');
        $data .= ",unit=${unit} value=${value},valueBinary=${valueBinary}";
    }
    else if ('thermostatMode' == $event['name']) { // thermostatMode: Calculate a binary value (<any other value> = 1, off = 0)
        $unit = 'thermostatMode';
        $value = '"' . $value . '"';
        $valueBinary = ('off' == $event['value'] ? '0i' : '1i');
        $data .= ",unit=${unit} value=${value},valueBinary=${valueBinary}";
    }
    else if ('thermostatFanMode' == $event['name']) { // thermostatFanMode: Calculate a binary value (<any other value> = 1, off = 0)
        $unit = 'thermostatFanMode';
        $value = '"' . $value . '"';
        $valueBinary = ('off' == $event['value'] ? '0i' : '1i');
        $data .= ",unit=${unit} value=${value},valueBinary=${valueBinary}";
    }
    else if ('thermostatOperatingState' == $event['name']) { // thermostatOperatingState: Calculate a binary value (heating = 1, <any other value> = 0)
        $unit = 'thermostatOperatingState';
        $value = '"' . $value . '"';
        $valueBinary = ('heating' == $event['value'] ? '1i' : '0i');
        $data .= ",unit=${unit} value=${value},valueBinary=${valueBinary}";
    }
    else if ('thermostatSetpointMode' == $event['name']) { // thermostatSetpointMode: Calculate a binary value (followSchedule = 0, <any other value> = 1)
        $unit = 'thermostatSetpointMode';
        $value = '"' . $value . '"';
        $valueBinary = ('followSchedule' == $event['value'] ? '0i' : '1i');
        $data .= ",unit=${unit} value=${value},valueBinary=${valueBinary}";
    }
    else if ('threeAxis' == $event['name']) { // threeAxis: Format to x,y,z values.
        $unit = 'threeAxis';
        $valueXYZ = explode(",", $event['value']);
        $valueX = $valueXYZ[0];
        $valueY = $valueXYZ[1];
        $valueZ = $valueXYZ[2];
        $data .= ",unit=${unit} valueX=${valueX}i,valueY=${valueY}i,valueZ=${valueZ}i"; // values are integers.;
    }
    else if ('touch' == $event['name']) { // touch: Calculate a binary value (touched = 1, "" = 0)
        $unit = 'touch';
        $value = '"' . $value . '"';
        $valueBinary = ('touched' == $event['value'] ? '1i' : '0i');
        $data .= ",unit=${unit} value=${value},valueBinary=${valueBinary}";
    }
    else if ('optimisation' == $event['name']) { // optimisation: Calculate a binary value (active = 1, inactive = 0)
        $unit = 'optimisation';
        $value = '"' . $value . '"';
        $valueBinary = ('active' == $event['value'] ? '1i' : '0i');
        $data .= ",unit=${unit} value=${value},valueBinary=${valueBinary}";
    }
    else if ('windowFunction' == $event['name']) { // windowFunction: Calculate a binary value (active = 1, inactive = 0)
        $unit = 'windowFunction';
        $value = '"' . $value . '"';
        $valueBinary = ('active' == $event['value'] ? '1i' : '0i');
        $data .= ",unit=${unit} value=${value},valueBinary=${valueBinary}";
    }
    else if ('touch' == $event['name']) { // touch: Calculate a binary value (touched = 1, <any other value> = 0)
        $unit = 'touch';
        $value = '"' . $value . '"';
        $valueBinary = ('touched' == $event['value'] ? '1i' : '0i');
        $data .= ",unit=${unit} value=${value},valueBinary=${valueBinary}";
    }
    else if ('water' == $event['name']) { // water: Calculate a binary value (wet = 1, dry = 0)
        $unit = 'water';
        $value = '"' . $value . '"';
        $valueBinary = ('wet' == $event['value'] ? '1i' : '0i');
        $data .= ",unit=${unit} value=${value},valueBinary=${valueBinary}";
    }
    else if ('windowShade' == $event['name']) { // windowShade: Calculate a binary value (closed = 1, <any other value> = 0)
        $unit = 'windowShade';
        $value = '"' . $value . '"';
        $valueBinary = ('closed' == $event['value'] ? '1i' : '0i');
        $data .= ",unit=${unit} value=${value},valueBinary=${valueBinary}";
    }
    // Catch any other event with a string value that hasn't been handled:
    else if (preg_match("/.*[^0-9\.,-].*/", $event['value'])) { // match if any characters are not digits, period, comma, or hyphen.
        $value = '"' . $value . '"';
        $data .= ",unit=${unit} value=${value}";
    }
    // Catch any other general numerical event (carbonDioxide, power, energy, humidity, level, temperature, ultravioletIndex, voltage, etc).
    else {
        $data .= ",unit=${unit} value=${value}";
    }

    $data .= " ${timestamp}";

    // Post data to InfluxDB:
    return postMetricsToInfluxDB($data);

}


function getColorParameters($color = 'white')
{
    $retval = array();
    switch ($color) {
        case 'pink': {
            $retval['hue'] = 54394;
            $retval['sat'] = 254;
            break;
        }
        case 'green': {
            $retval['hue'] = 41372;
            $retval['sat'] = 78;
            break;
        }
        case 'blue': {
            $retval['hue'] = 46014;
            $retval['sat'] = 254;
            break;
        }
        case 'white':
        default: {
            $retval['hue'] = 13582;
            $retval['sat'] = 48;
            break;
        }
    }

    return $retval;
}
