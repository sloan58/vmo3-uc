<?php

namespace App\Http\Controllers;

use SoapFault;
use SoapClient;

use Illuminate\Http\Request;

use App\Jobs\TranscribeVoiceMessageJob;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

use Aws\Polly\PollyClient;
use Aws\Polly\Exception\PollyException;

use Aws\S3\S3Client;

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
            // Only match users with an email address as their alias
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
     * @param Request $request
     * @param $callhandler
     * @return \Illuminate\Http\JsonResponse
     * @throws SoapFault
     */
    public function updateCallHandlerGreeting(Request $request, $callhandler)
    {
        \Log::info("Vmo3Controller@updateCallHandlerGreeting: Hit");
        
        $action = strtolower($request->input('action', FALSE));
        $message = $request->input('message', FALSE);

        \Log::info("Vmo3Controller@updateCallHandlerGreeting: Received input", [
            'action' => $action,
            'message' => $message,
            'callhandler' => $callhandler
        ]);
        
        $body = json_encode([
                "TimeExpires" => "",
                "Enabled" => $action
            ]);

        \Log::info("Vmo3Controller@updateCallHandlerGreeting: Setting UCXN greeting update body", [
            "TimeExpires" => "",
            "Enabled" => $action
        ]);

        \Log::info("Vmo3Controller@updateCallHandlerGreeting: Trying UCXN API to toggle greeting.");
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

        
        if(filter_var($action, FILTER_VALIDATE_BOOLEAN)) {
            \Log::info("Vmo3Controller@updateCallHandlerGreeting: 'action' is True so we will hit the AWS Polly API.");
            $this->textToSpeech($message, $callhandler);
            $this->convertToWav($callhandler);
            $this->uploadWavFile($callhandler);
            $this->cleanupFiles($callhandler);
        }

        $this->updateUcmDnForwarding($action);

        \Log::info("Vmo3Controller@updateCallHandlerGreeting: OOO synthesis completed.  Returning 200 OK");
        return response()->json(
            json_decode($res->getBody()->getContents()), 200
        );
    }

    /**
     * Callback endpoint for UCXN webhook notification
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function ucxnCuniCallback(Request $request)
    {
        \Log::info('Vmo3Controller@ucxnCuniCallback: Hit');

        $simpleXml = simplexml_load_string($request->getContent());
        
        \Log::info('Vmo3Controller@ucxnCuniCallback: Loaded XML from callback', [$simpleXml]);

        if((string) $simpleXml->attributes()->eventType != "NEW_MESSAGE") {
            \Log::info('Vmo3Controller@ucxnCuniCallback: This is not a NEW_MESSAGE event.  We don\'t care about it...', [
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
        dispatch(new TranscribeVoiceMessageJob($alias, $displayName, $messageId, $callerAni));

        return response()->json("Message transcription initiated", 200);
    }
    



    /**
        * HELPER FUNCTIONS
    **/

    /**
     * Call AWS Polly API to synthesize text
     * @param $message
     * @param $callhandler
     * @return \Illuminate\Http\JsonResponse
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
     * @param $callhandler
     * @return \Illuminate\Http\JsonResponse
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
     * @param $callhandler
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
     * @param $callhandler
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
     * Update Cisco UCM Call Forwarding for a DN (Line)
     * @param $dn
     * @param $action
     * @throws SoapFault
     */
    private function updateUcmDnForwarding($action, $dn = '88109')
    {
        \Log::info('Vmo3Controller@updateUcmDnForwarding: Updating UCM call forwarding status.', [
            'dn' => $dn,
            'action' => $action
        ]);

        \Log::info('Vmo3Controller@updateUcmDnForwarding: Creating SOAP Client');
        $axl = new SoapClient(storage_path('schema/10.5/AXLAPI.wsdl'),
            [
                'trace'=>1,
                'exceptions'=>true,
                'location'=>"https://" . env('UCM_SERVER') . ":9111/axl/",
                'login'=> env('UCM_USER'),
                'password'=> env('UCM_PASSWORD'),
                'stream_context' => stream_context_create([
                    'ssl' => [
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                        'ciphers' => 'SHA1'
                    ]
                ])
            ]
        );

        \Log::info('Vmo3Controller@updateUcmDnForwarding: Sending updateLine() API method call');
        try {
            $response = $axl->updateLine([
                'pattern' => $dn,
                'routePartitionName' => 'KARMA_DN_PT',
                'callForwardAll' => [
                    'forwardToVoiceMail' => $action,
                    'callingSearchSpaceName' => [
                        '_' => 'KIDS_CSS'
                    ]
                ]
            ]);
        } catch (SoapFault $e) {
            \Log::error('Vmo3Controller@updateUcmDnForwarding: Received SOAP Client Error', [
                'request' => $axl->__getLastRequest(),
                'response' => $axl->__getLastResponse()
            ]);
            return;
        }
        \Log::info('Vmo3Controller@updateUcmDnForwarding: Updated the forwarding settings');
    }
}
