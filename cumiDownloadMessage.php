<?php

require "vendor/autoload.php";

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

$guzzle = new Client([
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

$url = "https://unityconnection.karmatek.io:9110/vmrest/messages/0:38bcbc8e-b255-4126-aaa2-7d47f914ec8b/attachments/0?userobjectid=3b82980a-f9ba-4492-ad09-b4d202c1789c";

try {
    $response = $guzzle->get($url, [
        'sink' => "./myVoicemail.wav"
    ]);
} catch (RequestException $e) {
    dd($e);
}

// dd($response);