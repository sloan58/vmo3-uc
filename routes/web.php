<?php

use Log;

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
    
    Log::info("api@/ucxn/users/{$callhandler}/greeting: Hit");

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

    Log::info("api@/ucxn/users/$user: Hit");

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
    
    Log::info("api@/ucxn/users: Hit");

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
    
    Log::info('api@callback: Hit');

    $simpleXml = simplexml_load_string($request->getContent());
    
    Log::info('api@callback: Loaded XML from callback');

    if((string) $simpleXml->attributes()->eventType == "KEEP_ALIVE") {
        Log::info('api@callback: This is a keepalive message', [
            'message' => $simpleXml->attributes()->eventType
        ]);
        return response()->json("", 200);
    }

    $alias = (string) $simpleXml->attributes()->mailboxId;
    $messageId = (string) $simpleXml->messageInfo->attributes()->messageId;
    
    Log::info('api@callback: Extracted alias and messageId', [
        'alias' => $alias,
        'message' => $messageId
    ]);

    Log::info('api@callback: Trying CUPI to get user objectId');
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
    Log::info('api@callback: Received user objectId', [
        'userObjectId' => $userObjectId
    ]);

    $url = "/vmrest/messages/0:$messageId/attachments/0?userobjectid=$userObjectId";
    Log::info('api@callback: Set CUMI url to fetch voicemail message', [
        'url' => $url
    ]);

    $bucket = 'asic-transcribe';
    $keyname = "$messageId.wav";
    $config = [
        'version' => 'latest',
        'region' => 'us-east-1',
    ];
    Log::info('api@callback: Set bucket, keyname and config for S3 access', [
        'bucket' => $bucket,
        'keyname' => $keyname,
        'config' => $config
    ]);

    Log::info('api@callback: Downloading voicemail message from CUMI');
    try {
        $guzzle->get($url, [
            'sink' => "../$keyname"
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

    Log::info('api@callback: Stored voicemail message locally');

    $s3 = new S3Client($config);
    Log::info('api@callback: Created S3 client');

    $uploader = new MultipartUploader($s3, "../$keyname", [
        'bucket' => $bucket,
        'key'    => $keyname
    ]);
    Log::info('api@callback: Created S3 MultipartUploader object');

    Log::info('api@callback: Uploading voicemail message to S3');
    try {
        $uploader->upload();
    } catch (MultipartUploadException $e) {
        Log::error("api@callback: Error fetching wav from CUMI.", [
            'alias' => $alias,
            'userObjectId' => $userObjectId,
            'messageId' => $messageId,
            'message' =>  $e->getMessage(),
            'code' => $e->getCode()
        ]);
        return response()->json("", 200);
    }

    Log::info('api@callback: Uploaded voicemail message to S3');

    $client = new TranscribeClient($config);
    Log::info('api@callback: Created AWS TranscribeClient');

    Log::info('api@callback: Sending transcription job request');
    // TODO: try/catch please?
    $result = $client->startTranscriptionJob([
        'LanguageCode' => 'en-US',
        'Media' => [
            'MediaFileUri' => "s3://{$bucket}/{$keyname}",
        ],
        'MediaFormat' => 'wav',
        'OutputBucketName' => $bucket,
        'TranscriptionJobName' => $keyname,
    ]);

    Log::info('api@callback: Transcription job sent.  Waiting for it to complete');
    while (true) {
        Log::info('api@callback: Fetching job status');
        $result = $client->getTranscriptionJob([
            'TranscriptionJobName' => $keyname,
        ]);
    
        if(in_array($result['TranscriptionJob']['TranscriptionJobStatus'], ['COMPLETED', 'FAILED'])) {
            Log::info("api@callback: Job status is {$result['TranscriptionJob']['TranscriptionJobStatus']}.  Breaking.");
            break;
        }
    
        Log::info('api@callback: Job not ready yet.  Sleeping 5 seconds...');
        sleep(5);
    }

    if($result['TranscriptionJob']['TranscriptionJobStatus'] == "FAILED") {
        Log::error("api@callback: Error transcribing voicemail message in AWS", [
            'alias' => $alias,
            'userObjectId' => $userObjectId,
            'messageId' => $messageId,
        ]);
        return response()->json("", 200);
    }

    Log::info('api@callback: Deleting completed transcription job');
    $client->deleteTranscriptionJob([
        'TranscriptionJobName' => $keyname,
    ]);

    Log::info('api@callback: Deleting voicemail wav file from S3');
    $s3->deleteObject([
        'Bucket' => $bucket,
        'Key'    => $keyname
    ]);

    Log::info('api@callback: Retrieving transcription text from S3');
    try {
        $result = $s3->getObject([
            'Bucket' => $bucket,
            'Key'    => "$keyname.json"
        ]);
    } catch (S3Exception $e) {
        Log::error("api@callback: Error fetching transcription text from S3", [
            'alias' => $alias,
            'userObjectId' => $userObjectId,
            'messageId' => $messageId,
            'message' =>  $e->getMessage(),
            'code' => $e->getCode()
        ]);
    }
    Log::info('api@callback: Got response from S3.  Extracting transcription from json object');

    $transcription = json_decode($result['Body'])->results->transcripts[0]->transcript;
    Log::info('api@callback: Transcription says - ', [
        'transcription' => $transcription
    ]);

    Log::info('api@callback: Deleting transcription text from S3');
    $s3->deleteObject([
        'Bucket' => $bucket,
        'Key'    => "$keyname.json"
    ]);

    Log::info('api@callback: Converting wav file name to something easier on the eyes');
    $newWavName = date('Y-m-d') . '_' . time() . '.wav';
    rename('../' . $keyname, '../' . $newWavName);

    Log::info('api@callback: Completed processing.', [
        'alias' => $alias,
        'messageId' => $messageId,
        'wavFile' => $newWavName
    ]);

    return response()->json("", 200);
});