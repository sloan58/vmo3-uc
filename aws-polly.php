<?php

require 'vendor/autoload.php';

use Aws\Polly\PollyClient;
use Aws\Exception\AwsException;

$speech = [
  
  // Change this to whatever text you want to convert to audio
  'Text' => 'Hi! My name is Emma. Welcome to the Amazon Polly demo.',
  'OutputFormat' => 'mp3',
  'TextType' => 'text',
  'VoiceId' => 'Emma'

];

$config = [
  'version' => 'latest',
  'region' => 'us-east-1', // Change this to your respective AWS region
];


// get service handle
try {
    $client = new PollyClient($config);
} catch(Exception $e) {
    print_r($e); exit;
}

// get speech
$response = $client->synthesizeSpeech($speech);

// save response file
file_put_contents('text.mp3', $response['AudioStream']);

// DEBUG
print_r($response); exit;
