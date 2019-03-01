<?php

$router->get('/', function () use ($router) {
    \Log::info('api@/: Ping!');
    return response()->json([
        "version" => $router->app->version()
    ], 200);
});

$router->post('/callback', 'Vmo3Controller@ucxnCuniCallback');
$router->post('/ucxn/users/{callhandler}/greeting', 'Vmo3Controller@updateCallHandlerGreeting');

$router->get('/ucxn/users/{user}', 'Vmo3Controller@getOneUcxnUser');
$router->get('/ucxn/users', 'Vmo3Controller@getAllUcxnUsers');