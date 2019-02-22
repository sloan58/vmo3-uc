<?php

use Log;
use GuzzleHttp\Client;
use Aws\Polly\PollyClient;
use Illuminate\Http\Request;
use Aws\Exception\AwsException;
use Aws\Polly\Exception\PollyException;
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
    Log::info('@textToSpeech: Starting Process', [
        'message' => $message,
        'callHander' => $callhandler
    ]);

    $speech = [
        'Text' => $message,
        'OutputFormat' => 'mp3',
        'TextType' => 'text',
        'VoiceId' => 'Emma' 
    ];
    Log::info('@textToSpeech: Generated Speech Params', $speech);

    $config = [
        'version' => 'latest',
        'region' => 'us-east-1',
    ];
    Log::info('@textToSpeech: Generated AWS Client Config', $config);

    Log::info('@textToSpeech: Creating AWS Polly Client');
    $client = new PollyClient($config);

    Log::info('@textToSpeech: Trying AWS Polly API');
    try {
        $response = $client->synthesizeSpeech($speech);
    } catch(PollyException $e) {
        Log::error('@textToSpeech: Error calling AWS Polly API', [
            'message' =>  $e->getAwsErrorMessage(),
            'code' => $e->getStatusCode()
        ]);
        return response()->json("Error calling AWS Polly API", 500);
    }

    Log::info('@textToSpeech: Received response from Polly.  Storing file locally.', [
        'fileLocation' => "../{$callhandler}.mp3"
    ]);
    file_put_contents("../{$callhandler}.mp3", $response['AudioStream']);
}

function uploadWavFile($callhandler, $guzzle)
{
    Log::info('@uploadWavFile: Starting Process', [
        'callHander' => $callhandler
    ]);
    
    $url = "/vmrest/handlers/callhandlers/$callhandler/greetings/Alternate/greetingstreamfiles/1033/audio";
    Log::info('@uploadWavFile: Set URL', [
        'url' => $url
    ]);

    $path = "../$callhandler.wav";
    $file_path = fopen($path,'rw');
    Log::info('@uploadWavFile: Set path and file_path', [
        'path' => $path,
        'file_path' => $file_path
    ]);

    Log::info('@uploadWavFile: Trying to upload wav file to UCXN.');
    try {
        $response = $guzzle->put($url, [
            'headers' => [
                'Content-Type' => 'audio/wav'
            ],
            'body' => $file_path
            ]);
    } catch (RequestException $e) {
        Log::error('@uploadWavFile: Error uploading wav file to UCXN', [
            'message' =>  $e->getMessage(),
            'code' => $e->getCode()
        ]);
        return response()->json("Error uploading file to UCXN", 500);
    }
}

function convertToWav($callhandler)
{
    Log::info('@convertToWav: Converting mp3 to wav.', [
        'mp3' => "../{$callhandler}.mp3",
        'wav' => "../{$callhandler}.wav"
    ]);
    exec("sox ../{$callhandler}.mp3 -r 8000 -c 1 -b 16 ../{$callhandler}.wav");
}

function cleanUpFiles($callhandler)
{
    Log::info('@cleanUpFiles: Removing mp3 and wav files.', [
        'mp3' => "../{$callhandler}.mp3",
        'wav' => "../{$callhandler}.wav"
    ]);
    exec("rm ../{$callhandler}.mp3 ../{$callhandler}.wav");
}

$router->get('/', function () use ($router) {
    Log::info('api@/: Ping!');
    return response()->json([
        "version" => $router->app->version()
    ], 200);
});

$router->post('/ucxn/users/{callhandler}/greeting', function (Request $request, $callhandler) use ($router, $guzzle) {
    
    Log::info("api@/ucxn/users/{$callhandler}/greeting: Hit.");

    $action = $request->input('action', FALSE);
    $message = $request->input('message', FALSE);

    Log::info("api@/ucxn/users/{$callhandler}/greeting: Received input", [
        'action' => $action,
        'message' => $message
    ]);
    
    $body = json_encode([
            "TimeExpires" => "",
            "Enabled" => $action
        ]);

    Log::info("api@/ucxn/users/{$callhandler}/greeting: Setting UCXN greeting update body", [
        "TimeExpires" => "",
        "Enabled" => $action
    ]);

    Log::info("api@/ucxn/users/{$callhandler}/greeting: Trying UNXN API to toggle greeting.");
    try {
        $res =  $guzzle->put("/vmrest/handlers/callhandlers/{$callhandler}/greetings/Alternate", [
            "body" => $body
        ]);
    } catch (RequestException $e) {
        Log::error('api@/ucxn/users/{$callhandler}/greeting: Error updating UCXN greeting', [
            'message' =>  $e->getMessage(),
            'code' => $e->getCode()
        ]);
        return response()->json("Could not toggle Unity Connection Greeting", 500);
    }
    Log::info("api@/ucxn/users/{$callhandler}/greeting: Toggled greeting successfully.");

    if($message) {
        Log::info("api@/ucxn/users/{$callhandler}/greeting: 'message' is set so we will hit the AWS Polly API.");
        textToSpeech($message, $callhandler);
        convertToWav($callhandler);
        uploadWavFile($callhandler, $guzzle);
        cleanupFiles($callhandler);
    }

    Log::info("api@/ucxn/users/{$callhandler}/greeting: OOO synthesis completed.  Returning 200 OK");
    return response()->json(
        json_decode($res->getBody()->getContents()), 200
    );
});

$router->get('/ucxn/users/{user}', function ($user) use ($router, $guzzle) {

    Log::info("api@/ucxn/users/$user: Hit.");

    Log::info("api@/ucxn/users/$user: Trying UCXN to gather User data.");
    try {
        $res =  $guzzle->get("/vmrest/users/$user");
    } catch (RequestException $e) {
        Log::error("api@/ucxn/users/$user: Error fetching UCXN User data.", [
            'message' =>  $e->getMessage(),
            'code' => $e->getCode()
        ]);
        return response()->json("Error fetching UCXN User data.", 500);
    }

    Log::info("api@/ucxn/users/$user: Got User data.  Returning 200 OK.");
    return response()->json(
        json_decode($res->getBody()->getContents())
    );
});

$router->get('/ucxn/users', function () use ($router, $guzzle) {
    
    Log::info("api@/ucxn/users: Hit.");

    Log::info("api@/ucxn/users: Trying UCXN to gather all User data.");
    try {
        $res =  $guzzle->get("/vmrest/users");
    } catch (RequestException $e) {
        Log::error("api@/ucxn/users: Error fetching UCXN User(s) data.", [
            'message' =>  $e->getMessage(),
            'code' => $e->getCode()
        ]);
        return response()->json("Error fetching UCXN User(s) data.", 500);
    }

    Log::info("api@/ucxn/users: Got UCXN User(s) data.");
    $users = json_decode($res->getBody()->getContents());

    $outputArray = [];
    Log::info("api@/ucxn/users: Iterating Users and extracting data.");
    foreach($users->User as $key => $user)
    {
        if (preg_match('/^.*@.*$/', $user->Alias)) {
            $outputArray[$key]['ObjectId'] = $user->ObjectId;
            $outputArray[$key]['Alias'] = $user->Alias;
            $outputArray[$key]['Extension'] = $user->DtmfAccessId;
            $outputArray[$key]['CallHandlerObjectId'] = $user->CallHandlerObjectId;
        }
    }

    Log::info("api@/ucxn/users: Iterating Users and fetching Alternate Greeting state.");
    foreach($outputArray as $key => $output)
    {
        try {
            $res =  $guzzle->get(
                "/vmrest/handlers/callhandlers/{$output['CallHandlerObjectId']}/greetings/Alternate"
            );
        } catch (RequestException $e) {
            Log::error("api@/ucxn/users: Error fetching User Alternate Greeting state.", [
                'callHandler' => $output['CallHandlerObjectId'],
                'message' =>  $e->getMessage(),
                'code' => $e->getCode()
            ]);
            return response()->json("Error fetching User Alternate Greeting state.", 500);
        }

        $outputArray[$key]['AlternateGreetingEnabled'] = json_decode($res->getBody()->getContents())->Enabled;
    }

    Log::info("api@/ucxn/users: Built response array with User data.  Returning 200 OK.");
    return response()->json(
        array_values($outputArray)
    );
    
});

$router->post('/callback', function(Request $request) use ($router, $guzzle) {

    $simpleXml = simplexml_load_string($request->getContent());

    if((string) $simpleXml->attributes()->eventType == "KEEP_ALIVE") {
        return response()->json("", 200);
    }

    $alias = (string) $simpleXml->attributes()->mailboxId;
    $messageId = (string) $simpleXml->messageInfo->attributes()->messageId;

    try {
        $res =  $guzzle->get(
            "/vmrest/users?query=(alias is $alias)"
        );
    } catch (RequestException $e) {
        Log::error("api@callback: Error fetching User objectId.", [
            'alias' => $alias,
            'messageId' => $messageId,
            'message' =>  $e->getMessage(),
            'code' => $e->getCode()
        ]);
        return response()->json("", 200);
    }

    $userObjectId = json_decode($res->getBody()->getContents())->User->ObjectId;

    $url = "/vmrest/messages/0:$messageId/attachments/0?userobjectid=$userObjectId";

    try {
        $response = $guzzle->get($url, [
            'sink' => "../$messageId.wav"
        ]);
    } catch (RequestException $e) {
        Log::error("api@callback: Error fetching wav from CUMI.", [
            'alias' => $alias,
            'userObjectId' => $userObjectId,
            'messageId' => $messageId,
            'message' =>  $e->getMessage(),
            'code' => $e->getCode()
        ]);
        return response()->json("", 200);
    }
    
    Log::info('Callback request:', [
        'alias' => $alias,
        'messageId' => $messageId
    ]);
});