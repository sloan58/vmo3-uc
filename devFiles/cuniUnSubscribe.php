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

$id = "b604a877-4962-480a-bf26-f263322c6092";
try {
    $res = $cuni->unsubscribe([
        'subscriptionId' => $id
    ]);
} catch (\SoapFault $e) {
    var_dump($e->getCode(), $cuni->__getLastRequest(), $cuni->__getLastResponse());
    exit;
}
var_dump($res, $cuni->__getLastRequest(), $cuni->__getLastResponse());