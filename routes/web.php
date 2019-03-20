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

$router->post('/chatter', function() {
    $config = [
        'cisco-spark' => [
            'token' => 'NGIyZmFjYTAtZTU5Yi00YjYwLTgzNjUtYjQ0NWU3ZTJmMzBhNWY2YzY3MjEtYzNj_PF84_1eb65fdf-9643-417f-9974-ad72cae0e10f',
        ]
    ];
    
    // Load the driver(s) you want to use
    DriverManager::loadDriver(CiscoSparkDriver::class);
    
    // Create an instance
    $botman = BotManFactory::create($config);
    
    // Create an instance
    $botman = BotManFactory::create($config);

    // Give the bot something to listen for.
    // $botman->hears('hello', function (BotMan $bot) {
    //     $bot->reply('Hello yourself.');
    // });
    
    $res = $botman->say('Message', 'Y2lzY29zcGFyazovL3VzL1BFT1BMRS9hZmZlMWFiYi0xNzFmLTRjODEtOWYxMC0zNTBlNmM0ODYzNTY', CiscoSparkDriver::class);

    \Log::info('res', [$res]);

    // Start listening
    $botman->listen();
});

$router->post('/callback', 'Vmo3Controller@ucxnCuniCallback');
$router->post('/ucxn/users/{callhandler}/greeting', 'Vmo3Controller@updateCallHandlerGreeting');

$router->get('/ucxn/users/{user}', 'Vmo3Controller@getOneUcxnUser');
$router->get('/ucxn/users', 'Vmo3Controller@getAllUcxnUsers');