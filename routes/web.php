<?php

use Illuminate\Http\Request;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

use Aws\Polly\PollyClient;
use Aws\Polly\Exception\PollyException;

use Aws\S3\S3Client;
use Aws\S3\MultipartUploader;
use Aws\Common\Exception\MultipartUploadException;

use Aws\TranscribeService\TranscribeServiceClient as TranscribeClient;

$guzzle = new Client([
    'base_uri' => "https://" . env('UCXN_SERVER') . ":9110",
    'verify' => false,
    'auth' => [
        env('UCXN_USER'),
        env('UCXN_PASSWORD')
    ],
    'headers' => [
        'Accept' => 'application/json',
        'Content-Type' => 'application/json'
    ],
]);


$router->get('/', function () use ($router) {
    Log::info('api@/: Ping!');
    return response()->json([
        "version" => $router->app->version()
    ], 200);
});

$router->post('/ucxn/users/{callhandler}/greeting', 'Vmo3Controller@updateCallHandlerGreeting');
$router->get('/ucxn/users/{user}', 'Vmo3Controller@getOneUcxnUser');
$router->get('/ucxn/users', 'Vmo3Controller@getAllUcxnUsers');

$router->post('/callback', function(Request $request) use ($router, $guzzle) {
    
    
});