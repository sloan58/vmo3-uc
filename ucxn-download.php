<?php

require 'vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

$url = "https://unityconnection.karmatek.io:9110/vmrest/handlers/callhandlers/444f6ea3-955d-48f3-bee3-27a4dd706b5c/greetings/Alternate/greetingstreamfiles/1033/audio";

$path = __DIR__. '/testfile.wav';
$file_path = fopen($path,'w');

$client = new \GuzzleHttp\Client([
    'debug' => true,
    'verify' => false,
    'auth' => [
        'Administrator',
        'A$h8urn!'
    ],
    'headers' => [
        'Accept' => 'application/json',
        'Content-Type' => 'application/json'
    ],
]);

$response = $client->get($url,['sink' => $path]);

var_dump($response->getStatusCode());