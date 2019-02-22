<?php

require "vendor/autoload.php";

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

$guzzle = new Client([
    // 'debug' => true,
    'base_uri' => "https://unityconnection.karmatek.io:9110",
    'verify' => false,
    'auth' => [
        "Administrator",
        "A\$h8urn!"
    ],
    'headers' => [
        'Accept' => 'application/json',
        'Content-Type' => 'application/json'
    ],
]);

$alias = 'marty@karmatek.io';

try {
    $res =  $guzzle->get("/vmrest/users?query=(alias is $alias)");
} catch (RequestException $e) {
    dd($e);
}

$objectId = json_decode($res->getBody()->getContents())->User->ObjectId;
dd($objectId);