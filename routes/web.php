<?php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

/*
|--------------------------------------------------------------------------
| Application Routes
|--------------------------------------------------------------------------
|
| Here is where you can register all of the routes for an application.
| It is a breeze. Simply tell Lumen the URIs it should respond to
| and give it the Closure to call when that URI is requested.
|
*/

$router->get('/', function () use ($router) {
    return $router->app->version();
});

$router->get('/ucxn/users', function () use ($router) {
    $guzzle = new Client([
        'base_uri' => 'https://***REMOVED***:9110',
        'verify' => false,
        'auth' => [
            '***REMOVED***',
            '***REMOVED***'
        ],
        'headers' => [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json'
        ],
    ]);

    try {
        $res =  $guzzle->get("/vmrest/users");
    } catch (RequestException $e) {
        dd($e);
    }

    $users = json_decode($res->getBody()->getContents());
    $total = '@total';
    if($users->$total)
    {
        $outputArray = [];
        foreach($users->User as $key => $user)
        {
            if (preg_match('/^.*@.*$/', $user->Alias)) {
                $outputArray[$key]['Alias'] = $user->Alias;
                $outputArray[$key]['Extension'] = $user->DtmfAccessId;
                $outputArray[$key]['CallHandlerObjectId'] = $user->CallHandlerObjectId;
            }
        }

        foreach($outputArray as $key => $output)
        {
            try {
                $res =  $guzzle->get("/vmrest/handlers/callhandlers/{$output['CallHandlerObjectId']}/greetings/Alternate");
            } catch (RequestException $e) {
                dd($e);
            }
        
            $outputArray[$key]['AlternateGreetingEnabled'] = json_decode($res->getBody()->getContents())->Enabled;
        }

        return array_values($outputArray);
    }
    
    return false;
    
});