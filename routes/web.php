<?php

use BotMan\BotMan\BotMan;
use BotMan\BotMan\BotManFactory;
use BotMan\BotMan\Drivers\DriverManager;
use BotMan\Drivers\CiscoSpark\CiscoSparkDriver;

$router->get('/', function () use ($router) {
    \Log::info('api@/: Ping!');
    return response()->json([
        "version" => $router->app->version()
    ], 200);
});

$router->get('queue', function() {
    dispatch(new \App\Jobs\ExampleJob());
    return response()->json([], 200);
});

$router->post('/callback', 'Vmo3Controller@ucxnCuniCallback');
$router->post('/ucxn/users/{callhandler}/greeting', 'Vmo3Controller@updateCallHandlerGreeting');

$router->get('/ucxn/users/{user}', 'Vmo3Controller@getOneUcxnUser');
$router->get('/ucxn/users', 'Vmo3Controller@getAllUcxnUsers');
