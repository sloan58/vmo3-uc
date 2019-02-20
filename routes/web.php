<?php

use GuzzleHttp\Client;
use Aws\Polly\PollyClient;
use Illuminate\Http\Request;
use Aws\Exception\AwsException;
use GuzzleHttp\Exception\RequestException;

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

function textToSpeech($message,$callhandler)
{
    $speech = [
        'Text' => $message,
        'OutputFormat' => 'mp3',
        'TextType' => 'text',
        'VoiceId' => 'Emma' 
    ];

    $config = [
        'version' => 'latest',
        'region' => 'us-east-1',
    ];

    try {
        $client = new PollyClient($config);
    } catch(Exception $e) {
        print_r($e); exit;
    }

    $response = $client->synthesizeSpeech($speech);

    file_put_contents("../{$callhandler}.mp3", $response['AudioStream']);
}

function uploadWavFile($callhandler, $guzzle)
{
    $url = "/vmrest/handlers/callhandlers/$callhandler/greetings/Alternate/greetingstreamfiles/1033/audio";

    $path = "../$callhandler.wav";
    $file_path = fopen($path,'rw');

    try {
        $response = $guzzle->put($url, [
            'headers' => [
                'Content-Type' => 'audio/wav'
            ],
            'body' => $file_path
            ]);
    } catch (RequestException $e) {
        return response()->json("Error uploading file", 500);
    }
}

function convertToWav($callhandler)
{
    exec("sox ../{$callhandler}.mp3 -r 8000 -c 1 -b 16 ../{$callhandler}.wav");
}

function cleanUpFiles($callhandler)
{
    exec("rm ../{$callhandler}.mp3 ../{$callhandler}.wav");
}

$router->get('/', function () use ($router) {
    return response()->json([
        "version" => $router->app->version()
    ], 200);
});

$router->post('/ucxn/users/{callhandler}/greeting', function (Request $request, $callhandler) use ($router, $guzzle) {
    
    $action = $request->input('action', FALSE);
    $message = $request->input('message', FALSE);
    
    $body = json_encode([
            "TimeExpires" => "",
            "Enabled" => $action
        ]);

    try {
        $res =  $guzzle->put("/vmrest/handlers/callhandlers/{$callhandler}/greetings/Alternate", [
            "body" => $body
        ]);
    } catch (RequestException $e) {
        if($e->getCode() == "404")
        {
            return response()->json("Greeting not found", 404);
        }
        return response()->json("Could not toggle Unity Connection Greeting", 500);
    }
    
    if($message) {
        textToSpeech($message, $callhandler);
        convertToWav($callhandler);
        uploadWavFile($callhandler, $guzzle);
        cleanupFiles($callhandler);
    }

    return response()->json(
        json_decode($res->getBody()->getContents())
    );
});

$router->get('/ucxn/users/{user}', function ($user) use ($router, $guzzle) {

    try {
        $res =  $guzzle->get("/vmrest/users/$user");
    } catch (RequestException $e) {
        if($e->getCode() == "404")
        {
            return response()->json("User not found", 404);
        }
        return response()->json("Exception: $e->getMessage()", 500);
    }

    return response()->json(
        json_decode($res->getBody()->getContents())
    );
});

$router->get('/ucxn/users', function () use ($router, $guzzle) {
    
    try {
        $res =  $guzzle->get("/vmrest/users");
    } catch (RequestException $e) {
        return response()->json("Exception: $e->getMessage()", 500);
    }

    $users = json_decode($res->getBody()->getContents());

    $total = '@total';

    if(!$users->$total)
    {
        return response()->json("Users not found", 404);
    }

    $outputArray = [];
    foreach($users->User as $key => $user)
    {
        if (preg_match('/^.*@.*$/', $user->Alias)) {
            $outputArray[$key]['ObjectId'] = $user->ObjectId;
            $outputArray[$key]['Alias'] = $user->Alias;
            $outputArray[$key]['Extension'] = $user->DtmfAccessId;
            $outputArray[$key]['CallHandlerObjectId'] = $user->CallHandlerObjectId;
        }
    }

    foreach($outputArray as $key => $output)
    {
        try {
            $res =  $guzzle->get(
                "/vmrest/handlers/callhandlers/{$output['CallHandlerObjectId']}/greetings/Alternate"
            );
        } catch (RequestException $e) {
            dd($e);
        }

        $outputArray[$key]['AlternateGreetingEnabled'] = json_decode($res->getBody()->getContents())->Enabled;
    }

    return response()->json(
        array_values($outputArray)
    );
    
});