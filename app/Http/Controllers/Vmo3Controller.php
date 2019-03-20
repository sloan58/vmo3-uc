<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use GuzzleHttp\Exception\RequestException;

use Aws\Polly\PollyClient;
use Aws\Polly\Exception\PollyException;

use Aws\S3\S3Client;
use Aws\S3\MultipartUploader;
use Aws\Common\Exception\MultipartUploadException;

use Aws\TranscribeService\TranscribeServiceClient as TranscribeClient;

class Vmo3Controller extends Controller
{
    /**
     * AWS S3 Bucket
    **/
    private $bucket;

    /**
     * The Guzzle HTTP Client
    **/
    private $guzzle;

    /**
     * AWS Config
    **/
    private $awsConfig;

    /**
     * AWS S3 Client
    **/
    private $s3;

    /**
     * AWS Transcribe Client
    **/
    private $transcribe;

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->bucket = 'asic-transcribe';

        $this->guzzle = new Client([
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

        $this->awsConfig = [
            'version' => 'latest',
            'region' => 'us-east-1',
        ];

        $this->s3 = new S3Client($this->awsConfig);
        $this->transcribe = new TranscribeClient($this->awsConfig);
    }

    /**
     * Get details for one user from UCXN
     */
    public function getOneUcxnUser(Request $request)
    {
        \Log::info("Vmo3Controller@getOneUcxnUser: Hit");

        $user = $request->input('user');
        \Log::info("Vmo3Controller@getOneUcxnUser: Trying UCXN to gather User data.");
        try {
            $res =  $this->guzzle->get("/vmrest/users/$user");
        } catch (RequestException $e) {
            \Log::error("Vmo3Controller@getOneUcxnUser: Error fetching UCXN User data.", [
                'message' =>  $e->getMessage(),
                'code' => $e->getCode()
            ]);
            return response()->json("Error fetching UCXN User data.", 500);
        }

        \Log::info("Vmo3Controller@getOneUcxnUser: Got User data.  Returning 200 OK.");
        return response()->json(
            json_decode($res->getBody()->getContents())
        );
    }

    /**
     * Get details for all users from UCXN
     */
    public function getAllUcxnUsers()
    {
        \Log::info("Vmo3Controller@getAllUcxnUsers: Hit");

        \Log::info("Vmo3Controller@getAllUcxnUsers: Trying UCXN to gather all User data.");
        try {
            $res =  $this->guzzle->get("/vmrest/users");
        } catch (RequestException $e) {
            \Log::error("Vmo3Controller@getAllUcxnUsers: Error fetching UCXN User(s) data.", [
                'message' =>  $e->getMessage(),
                'code' => $e->getCode()
            ]);
            return response()->json("Error fetching UCXN User(s) data.", 500);
        }

        \Log::info("Vmo3Controller@getAllUcxnUsers: Got UCXN User(s) data.");
        $users = json_decode($res->getBody()->getContents());

        $outputArray = [];
        \Log::info("Vmo3Controller@getAllUcxnUsers: Iterating Users and extracting data.");
        foreach($users->User as $key => $user)
        {
            if (preg_match('/^.*@.*$/', $user->Alias)) {
                $outputArray[$key]['ObjectId'] = $user->ObjectId;
                $outputArray[$key]['Alias'] = $user->Alias;
                $outputArray[$key]['Extension'] = $user->DtmfAccessId;
                $outputArray[$key]['CallHandlerObjectId'] = $user->CallHandlerObjectId;
            }
        }

        \Log::info("Vmo3Controller@getAllUcxnUsers: Iterating Users and fetching Alternate Greeting state.");
        foreach($outputArray as $key => $output)
        {
            try {
                $res =  $this->guzzle->get(
                    "/vmrest/handlers/callhandlers/{$output['CallHandlerObjectId']}/greetings/Alternate"
                );
            } catch (RequestException $e) {
                \Log::error("Vmo3Controller@getAllUcxnUsers: Error fetching User Alternate Greeting state.", [
                    'callHandler' => $output['CallHandlerObjectId'],
                    'message' =>  $e->getMessage(),
                    'code' => $e->getCode()
                ]);
                return response()->json("Error fetching User Alternate Greeting state.", 500);
            }

            $outputArray[$key]['AlternateGreetingEnabled'] = json_decode($res->getBody()->getContents())->Enabled;
        }

        \Log::info("Vmo3Controller@getAllUcxnUsers: Built response array with User data.  Returning 200 OK.");
        return response()->json(
            array_values($outputArray)
        );
    }

    /**
     * Update the UCXN Alternate Greeting
     */
    public function updateCallHandlerGreeting(Request $request, $callhandler)
    {
        \Log::info("Vmo3Controller@updateCallHandlerGreeting: Hit");
        
        $action = $request->input('action', FALSE);
        $message = $request->input('message', FALSE);

        \Log::info("Vmo3Controller@updateCallHandlerGreeting: Received input", [
            'action' => $action,
            'message' => $message
        ]);
        
        $body = json_encode([
                "TimeExpires" => "",
                "Enabled" => $action
            ]);

        \Log::info("Vmo3Controller@updateCallHandlerGreeting: Setting UCXN greeting update body", [
            "TimeExpires" => "",
            "Enabled" => $action
        ]);

        \Log::info("Vmo3Controller@updateCallHandlerGreeting: Trying UNXN API to toggle greeting.");
        try {
            $res =  $this->guzzle->put("/vmrest/handlers/callhandlers/{$callhandler}/greetings/Alternate", [
                "body" => $body
            ]);
            \Log::info("Vmo3Controller@updateCallHandlerGreeting: Toggled greeting successfully.");
        } catch (RequestException $e) {
            \Log::error('Vmo3Controller@updateCallHandlerGreeting: Error updating UCXN greeting', [
                'message' =>  $e->getMessage(),
                'code' => $e->getCode()
            ]);
            return response()->json("Could not toggle Unity Connection Greeting", 500);
        }

        if($message) {
            \Log::info("Vmo3Controller@updateCallHandlerGreeting: 'message' is set so we will hit the AWS Polly API.");
            $this->textToSpeech($message, $callhandler);
            $this->convertToWav($callhandler);
            $this->uploadWavFile($callhandler);
            $this->cleanupFiles($callhandler);
        }

        \Log::info("Vmo3Controller@updateCallHandlerGreeting: OOO synthesis completed.  Returning 200 OK");
        return response()->json(
            json_decode($res->getBody()->getContents()), 200
        );
    }

    /**
     * Callback endpoint for UCXN webhook notification
     */
    public function ucxnCuniCallback(Request $request)
    {
        \Log::info('Vmo3Controller@ucxnCuniCallback: Hit');

        $simpleXml = simplexml_load_string($request->getContent());
        
        \Log::info('Vmo3Controller@ucxnCuniCallback: Loaded XML from callback');

        if((string) $simpleXml->attributes()->eventType == "KEEP_ALIVE") {
            \Log::info('Vmo3Controller@ucxnCuniCallback: This is a keepalive message', [
                'message' => $simpleXml->attributes()->eventType
            ]);
            return response()->json("", 200);
        }

        $alias = (string) $simpleXml->attributes()->mailboxId;
        $displayName = (string) $simpleXml->attributes()->displayName;
        $messageId = (string) $simpleXml->messageInfo->attributes()->messageId;
        $callerAni = (string) $simpleXml->messageInfo->attributes()->callerAni;
        
        \Log::info('Vmo3Controller@ucxnCuniCallback: Extracted message metadata', [
            'alias' => $alias,
            'displayName' => $displayName,
            'message' => $messageId,
            'callerAni' => $callerAni
        ]);

        $userObjectId = $this->getUserObjectId($alias);
        \Log::info('Vmo3Controller@ucxnCuniCallback: Received user objectId', [
            'userObjectId' => $userObjectId
        ]);

        $this->fetchAndSaveCumiMessage($messageId, $userObjectId);
        
        $this->uploadWavToS3($messageId);

        $this->transcribeWavFile($messageId);

        $transcription = $this->getTranscriptionText($messageId);
        
        \Log::info('Vmo3Controller@ucxnCuniCallback: Posting VM Transcription to Webex Teams');
        
        $client = new Client();
        
        try {
            $res = $client->request('POST', 'https://api.ciscospark.com/v1/messages', [
                'headers' => [
                    'Authorization' => 'Bearer NGIyZmFjYTAtZTU5Yi00YjYwLTgzNjUtYjQ0NWU3ZTJmMzBhNWY2YzY3MjEtYzNj_PF84_1eb65fdf-9643-417f-9974-ad72cae0e10f',
                ],
                'verify' => false,
                RequestOptions::JSON => [
                    'toPersonEmail' => 'masloan@cisco.com',
                    'text' => $transcription
                ]
            ]);
        } catch (RequestException $e) {
            \Log::error('Vmo3Controller@ucxnCuniCallback: Received an error when posting to the Webex Teams room - ', [
                $e->getMessage()
            ]);
        }

        $newWavName = date('Y-m-d') . '_' . time() . '.wav';
        \Log::info('Vmo3Controller@ucxnCuniCallback: Converting wav file name to something easier on the eyes', ['name' => $newWavName]);
        rename(storage_path("$messageId.wav"), storage_path($newWavName));

        \Log::info('Vmo3Controller@ucxnCuniCallback: Completed processing.', [
            'alias' => $alias,
            'messageId' => $messageId,
            'wavFile' => $newWavName
        ]);

        return response()->json("", 200);
    }
    



    /**
        * HELPER FUNCTIONS
    **/

    /**
     * Call AWS Polly API to synthesize text
     */
    private function textToSpeech($message,$callhandler)
    {
        \Log::info('Vmo3Controller@textToSpeech: Starting Process', [
            'message' => $message,
            'callHander' => $callhandler
        ]);

        $speech = [
            'Text' => $message,
            'OutputFormat' => 'mp3',
            'TextType' => 'text',
            'VoiceId' => 'Emma' 
        ];
        
        \Log::info('Vmo3Controller@textToSpeech: Creating AWS Polly Client');
        $client = new PollyClient($this->awsConfig);

        \Log::info('Vmo3Controller@textToSpeech: Trying AWS Polly API');
        try {
            $response = $client->synthesizeSpeech($speech);
        } catch(PollyException $e) {
            \Log::error('Vmo3Controller@textToSpeech: Error calling AWS Polly API', [
                'message' =>  $e->getAwsErrorMessage(),
                'code' => $e->getStatusCode()
            ]);
            return response()->json("Error calling AWS Polly API", 500);
        }

        \Log::info('Vmo3Controller@textToSpeech: Received response from Polly.  Storing file locally.', [
            'fileLocation' => storage_path("$callhandler.mp3")
        ]);
        file_put_contents(storage_path("$callhandler.mp3"), $response['AudioStream']);
    }

    /**
     * Upload synthesized greeting to UCXN
     */
    private function uploadWavFile($callhandler)
    {
        \Log::info('Vmo3Controller@uploadWavFile: Starting Process', [
            'callHander' => $callhandler
        ]);
        
        $url = "/vmrest/handlers/callhandlers/$callhandler/greetings/Alternate/greetingstreamfiles/1033/audio";
        \Log::info('Vmo3Controller@uploadWavFile: Set URL', [
            'url' => $url
        ]);

        $path = storage_path("$callhandler.wav");
        $file_path = fopen($path,'rw');
        \Log::info('Vmo3Controller@uploadWavFile: Set path and file_path', [
            'path' => $path,
            'file_path' => $file_path
        ]);

        \Log::info('Vmo3Controller@uploadWavFile: Trying to upload wav file to UCXN.');
        try {
            $this->guzzle->put($url, [
                'headers' => [
                    'Content-Type' => 'audio/wav'
                ],
                'body' => $file_path
                ]);
        } catch (RequestException $e) {
            \Log::error('Vmo3Controller@uploadWavFile: Error uploading wav file to UCXN', [
                'message' =>  $e->getMessage(),
                'code' => $e->getCode()
            ]);
            return response()->json("Error uploading file to UCXN", 500);
        }
    }

    /**
     * Use SoX to convert mp3 to wav
     */
    private function convertToWav($callhandler)
    {
        \Log::info('Vmo3Controller@convertToWav: Converting mp3 to wav.', [
            'mp3' => storage_path("$callhandler.mp3"),
            'wav' => storage_path("$callhandler.wav")
        ]);
        exec("sox " . storage_path("$callhandler.mp3") . " -r 8000 -c 1 -b 16 " . storage_path("$callhandler.wav"));
    }

    /**
     * Remove synthesized audio files
     */
    private function cleanUpFiles($callhandler)
    {
        \Log::info('Vmo3Controller@cleanUpFiles: Removing mp3 and wav files.', [
            'mp3' => storage_path("$callhandler.mp3"),
            'wav' => storage_path("$callhandler.wav")
        ]);
        exec("rm " . storage_path("$callhandler.mp3") . " " .  storage_path("$callhandler.wav"));
    }

    /**
     * Get a UCXN user object ID
     */
    private function getUserObjectId($alias)
    {
        \Log::info('Vmo3Controller@getUserObjectId: Trying CUPI to get user objectId');
        try {
            $res =  $this->guzzle->get(
                "/vmrest/users?query=(alias is $alias)"
            );
        } catch (RequestException $e) {
            \Log::error("Vmo3Controller@getUserObjectId: Error fetching User objectId.", [
                'alias' => $alias,
                'message' =>  $e->getMessage(),
                'code' => $e->getCode()
            ]);
            return response()->json("", 200);
        }

        return json_decode($res->getBody()->getContents())->User->ObjectId;
    }

    /**
     * Download a voice message from UCXN
     */
    private function fetchAndSaveCumiMessage($messageId, $userObjectId)
    {
        $url = "/vmrest/messages/0:$messageId/attachments/0?userobjectid=$userObjectId";
        \Log::info('Vmo3Controller@ucxnCuniCallback: Set CUMI url to fetch voicemail message', [
            'url' => $url
        ]);
        
        \Log::info('Vmo3Controller@ucxnCuniCallback: Downloading voicemail message from CUMI');
        try {
            $this->guzzle->get($url, [
                'sink' => storage_path("$messageId.wav")
            ]);
        } catch (RequestException $e) {
            Log::error("Vmo3Controller@ucxnCuniCallback: Error fetching wav from CUMI.", [
                'userObjectId' => $userObjectId,
                'messageId' => $messageId,
                'message' =>  $e->getMessage(),
                'code' => $e->getCode()
            ]);
            return response()->json("", 200);
        }

        \Log::info('Vmo3Controller@ucxnCuniCallback: Stored voicemail message locally');

    }

    /**
     * Upload wav file to AWS S3
     */
    private function uploadWavToS3($messageId)
    {
        $this->s3 = new S3Client($this->awsConfig);
        \Log::info('Vmo3Controller@ucxnCuniCallback: Created S3 client');

        $uploader = new MultipartUploader($this->s3, storage_path("$messageId.wav"), [
            'bucket' => $this->bucket,
            'key'    => "$messageId.wav"
        ]);
        \Log::info('Vmo3Controller@ucxnCuniCallback: Created S3 MultipartUploader object');

        \Log::info('Vmo3Controller@ucxnCuniCallback: Uploading voicemail message to S3');
        try {
            $uploader->upload();
        } catch (MultipartUploadException $e) {
            Log::error("Vmo3Controller@ucxnCuniCallback: Error fetching wav from CUMI.", [
                'messageId' => $messageId,
                'message' =>  $e->getMessage(),
                'code' => $e->getCode()
            ]);
            return response()->json("", 200);
        }

        \Log::info('Vmo3Controller@ucxnCuniCallback: Uploaded voicemail message to S3');
    }

    /**
     * Transcribe the wav file on AWS
     */
    private function transcribeWavFile($messageId)
    {
        \Log::info('Vmo3Controller@transcribeWavFile: Created AWS TranscribeClient');

        \Log::info('Vmo3Controller@transcribeWavFile: Sending transcription job request');
        // TODO: try/catch please?
        $result = $this->transcribe->startTranscriptionJob([
            'LanguageCode' => 'en-US',
            'Media' => [
                'MediaFileUri' => "s3://$this->bucket/$messageId.wav",
            ],
            'MediaFormat' => 'wav',
            'OutputBucketName' => $this->bucket,
            'TranscriptionJobName' => "$messageId.wav",
        ]);

        \Log::info('Vmo3Controller@transcribeWavFile: Transcription job sent.  Waiting for it to complete');
        while (true) {
            \Log::info('Vmo3Controller@transcribeWavFile: Fetching job status');
            $result = $this->transcribe->getTranscriptionJob([
                'TranscriptionJobName' => "$messageId.wav",
            ]);
        
            if(in_array($result['TranscriptionJob']['TranscriptionJobStatus'], ['COMPLETED', 'FAILED'])) {
                \Log::info("Vmo3Controller@transcribeWavFile: Job status is {$result['TranscriptionJob']['TranscriptionJobStatus']}.  Breaking.");
                break;
            }
        
            \Log::info('Vmo3Controller@transcribeWavFile: Job not ready yet.  Sleeping 5 seconds...');
            sleep(5);
        }

        if($result['TranscriptionJob']['TranscriptionJobStatus'] == "FAILED") {
            \Log::error("Vmo3Controller@transcribeWavFile: Error transcribing voicemail message in AWS", [
                'messageId' => $messageId,
            ]);
            return response()->json("", 200);
        }

        \Log::info('Vmo3Controller@transcribeWavFile: Job completed successfully');

        \Log::info('Vmo3Controller@transcribeWavFile: Deleting completed transcription job');
        $this->transcribe->deleteTranscriptionJob([
            'TranscriptionJobName' => "$messageId.wav",
        ]);

        \Log::info('Vmo3Controller@transcribeWavFile: Deleting voicemail wav file from S3');
        $this->s3->deleteObject([
            'Bucket' => $this->bucket,
            'Key'    => "$messageId.wav"
        ]);
    }

    private function getTranscriptionText($messageId)
    {
        \Log::info('Vmo3Controller@ucxnCuniCallback: Retrieving transcription text from S3');
        try {
            $result = $this->s3->getObject([
                'Bucket' => $this->bucket,
                'Key'    => "$messageId.wav.json"
            ]);
        } catch (S3Exception $e) {
            \Log::error("Vmo3Controller@ucxnCuniCallback: Error fetching transcription text from S3", [
                'messageId' => $messageId,
                'message' =>  $e->getMessage(),
                'code' => $e->getCode()
            ]);
        }
        \Log::info('Vmo3Controller@ucxnCuniCallback: Got response from S3.  Extracting transcription from json object');

        $transcription = json_decode($result['Body'])->results->transcripts[0]->transcript;
        \Log::info('Vmo3Controller@ucxnCuniCallback: Transcription says - ', [
            'transcription' => $transcription
        ]);

        \Log::info('Vmo3Controller@ucxnCuniCallback: Deleting transcription text from S3');
        $this->s3->deleteObject([
            'Bucket' => $this->bucket,
            'Key'    => "$messageId.wav.json"
        ]);

        return $transcription;
    }
}
