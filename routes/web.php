<?php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\RequestOptions;
use Illuminate\Http\Request;

$router->get('/', function () use ($router) {
    \Log::info('api@/: Ping!');
    return response()->json([
        "version" => $router->app->version()
    ], 200);
});

$router->post('/curri', 'Vmo3Controller@inboundCallAttempt');

$router->get('/curri', function(Request $request) {
    \Log::info('Received Cisco UCM CURRI Keepalive', [
        'request' => $request->all()
    ]);
    return response()->json('You hit CURRI!', 200);
});
$router->post('/callback', 'Vmo3Controller@ucxnCuniCallback');
$router->post('/ucxn/users/{callhandler}/greeting', 'Vmo3Controller@updateCallHandlerGreeting');

$router->get('/ucxn/users/{user}', 'Vmo3Controller@getOneUcxnUser');
$router->get('/ucxn/users', 'Vmo3Controller@getAllUcxnUsers');
