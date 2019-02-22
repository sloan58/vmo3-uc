<?php

require "vendor/autoload.php";

$api = "https://servername/messageeventservice/services/MessageEventService";

$cuni = new SoapClient("$api?wsdl",
[
    'trace' => true,
    'location' => $api,
    'exceptions' => true,
    'login' => '',
    'password' => '',
    'stream_context' => stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'ciphers' => 'SHA1'
            ]
        ]),
]);

$expires = date(DATE_ATOM, mktime(0, 0, 0, 3, 1, 2019));

try {
    $res = $cuni->subscribe([
        'resourceIdList' => [
            'string' => 'marty@karmatek.io'
        ],
        'eventTypeList' => [
            'string' => 'NEW_MESSAGE'
        ],
        'callbackServiceInfo' => [
            'callbackServiceUrl' => 'http://callbackurl/callback',
            'hostname' => 'hostname',
            'password' => '',
            'protocol' => '',
            'sslCertificates' => '',
            'username' => ''

        ],
        "expiration" => $expires
    ]);
} catch (\SoapFault $e) {
    var_dump($e->getCode(), $cuni->__getLastRequest(), $cuni->__getLastResponse());
    exit;
}
var_dump($res, $cuni->__getLastRequest(), $cuni->__getLastResponse());