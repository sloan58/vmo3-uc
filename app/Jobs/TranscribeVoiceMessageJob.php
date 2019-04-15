<?php

namespace App\Jobs;

use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Aws\S3\MultipartUploader;
use Aws\Exception\MultipartUploadException;
use Aws\TranscribeService\TranscribeServiceClient as TranscribeClient;

use GuzzleHttp\Client;
use Illuminate\Http\Request;
use GuzzleHttp\RequestOptions;
use GuzzleHttp\Exception\RequestException;

class TranscribeVoiceMessageJob extends Job
{
    /**
     * @var Client
     */
    private $guzzle;

    /**
     * @var array
     */
    private $awsConfig;

    /**
     * @var S3Client
     */
    private $s3;

    /**
     * AWS S3 Bucket
     **/
    private $bucket;
    
    private $alias;
    private $displayName;
    private $messageId;
    private $callerAni;

    /**
     * Create a new job instance.
     *
     * @param $alias
     * @param $displayName
     * @param $messageId
     * @param $callerAni
     */
    public function __construct($alias, $displayName, $messageId, $callerAni)
    {
        $this->bucket = 'asic-transcribe';

        $this->alias = $alias;
        $this->displayName = $displayName;
        $this->messageId = $messageId;
        $this->callerAni = $callerAni;
    }

    /**
     * Execute the job.
     *
     * @return void
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function handle()
    {
        \Log::info("TranscribeVoiceMessageJob@handle: Let's get this job done!");

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

        $credentials = new \Aws\Credentials\Credentials(env('AWS_KEY'), env('AWS_SECRET'));
        $this->awsConfig = [
            'version' => 'latest',
            'region' => 'us-east-1',
            'credentials' => $credentials
        ];

        $this->s3 = new S3Client($this->awsConfig);
        $this->transcribe = new TranscribeClient($this->awsConfig);

        \Log::info('TranscribeVoiceMessageJob@handle: Extracted message metadata', [
            'alias' => $this->alias,
            'displayName' => $this->displayName,
            'message' => $this->messageId,
            'callerAni' => $this->callerAni
        ]);

        \Log::info('TranscribeVoiceMessageJob@handle: Checking to see if this message has already been requested');
        $file = storage_path('messages.json');
        $json = json_decode(file_get_contents($file), true);

        if(in_array($this->messageId, $json['messages']))
        {
            \Log::info('TranscribeVoiceMessageJob@handle: Duplicate message transcription received.  Responding with 200 OK to stop the madness.', [
                'alias' => $this->alias,
                'displayName' => $this->displayName,
                'message' => $this->messageId,
                'callerAni' => $this->callerAni
            ]);

            exit;
        } else {
            \Log::info('TranscribeVoiceMessageJob@handle: This is a new request.  Storing to json file and processing.');
            array_push($json['messages'], $this->messageId);
            file_put_contents($file, json_encode($json));
        }

        $userObjectId = $this->getUserObjectId();
        \Log::info('TranscribeVoiceMessageJob@handle: Received user objectId', [
            'userObjectId' => $userObjectId
        ]);

        $this->fetchAndSaveCumiMessage($userObjectId);

        $this->uploadWavToS3();

        $this->transcribeWavFile();

        $transcription = $this->getTranscriptionText();

        \Log::info('TranscribeVoiceMessageJob@handle: Posting VM Transcription to Webex Teams');

        $client = new Client();

        try {
            $res = $client->request('POST', 'https://api.ciscospark.com/v1/messages', [
                'headers' => [
                    'Authorization' => 'Bearer ' . env('TEAMS_TOKEN'),
                ],
                'verify' => false,
                RequestOptions::JSON => [
//                    'toPersonEmail' => 'masloan@cisco.com',
                    'roomId' => env('TEAMS_ROOM_ID'),
                    'text' => $transcription
                ]
            ]);
        } catch (RequestException $e) {
            \Log::error('TranscribeVoiceMessageJob@handle: Received an error when posting to the Webex Teams room - ', [
                $e->getMessage()
            ]);
        }

        $newWavName = date('Y-m-d') . '_' . time() . '.wav';
        \Log::info('TranscribeVoiceMessageJob@handle: Converting wav file name to something easier on the eyes', ['name' => $newWavName]);
        rename(storage_path("$this->messageId.wav"), storage_path($newWavName));

        \Log::info('TranscribeVoiceMessageJob@handle: Completed processing.', [
            'alias' => $this->alias,
            'messageId' => $this->messageId,
            'wavFile' => $newWavName
        ]);
    }


    /**
     * Get a UCXN user object ID
     */
    private function getUserObjectId()
    {
        \Log::info('TranscribeVoiceMessageJob@getUserObjectId: Trying CUPI to get user objectId');
        try {
            $res =  $this->guzzle->get(
                "/vmrest/users?query=(alias is $this->alias)"
            );
        } catch (RequestException $e) {
            \Log::error("TranscribeVoiceMessageJob@getUserObjectId: Error fetching User objectId.", [
                'alias' => $this->alias,
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
    private function fetchAndSaveCumiMessage($userObjectId)
    {
        $url = "/vmrest/messages/0:$this->messageId/attachments/0?userobjectid=$userObjectId";
        \Log::info('TranscribeVoiceMessageJob@handle: Set CUMI url to fetch voicemail message', [
            'url' => $url
        ]);

        \Log::info('TranscribeVoiceMessageJob@handle: Downloading voicemail message from CUMI');
        try {
            $this->guzzle->get($url, [
                'sink' => storage_path("$this->messageId.wav")
            ]);
        } catch (RequestException $e) {
            \Log::error("TranscribeVoiceMessageJob@handle: Error fetching wav from CUMI.", [
                'userObjectId' => $userObjectId,
                'messageId' => $this->messageId,
                'message' =>  $e->getMessage(),
                'code' => $e->getCode()
            ]);
            return response()->json("", 200);
        }

        \Log::info('TranscribeVoiceMessageJob@handle: Stored voicemail message locally');

    }

    /**
     * Upload wav file to AWS S3
     */
    private function uploadWavToS3()
    {
        $this->s3 = new S3Client($this->awsConfig);
        \Log::info('TranscribeVoiceMessageJob@handle: Created S3 client');

        $uploader = new MultipartUploader($this->s3, storage_path("$this->messageId.wav"), [
            'bucket' => $this->bucket,
            'key'    => "$this->messageId.wav"
        ]);
        \Log::info('TranscribeVoiceMessageJob@handle: Created S3 MultipartUploader object');

        \Log::info('TranscribeVoiceMessageJob@handle: Uploading voicemail message to S3');
        try {
            $uploader->upload();
        } catch (MultipartUploadException $e) {
            \Log::error("TranscribeVoiceMessageJob@handle: Error fetching wav from CUMI.", [
                'messageId' => $this->messageId,
                'message' =>  $e->getMessage(),
                'code' => $e->getCode()
            ]);
            return response()->json("", 200);
        }

        \Log::info('TranscribeVoiceMessageJob@handle: Uploaded voicemail message to S3');
    }

    private function getTranscriptionText()
    {
        \Log::info('TranscribeVoiceMessageJob@handle: Retrieving transcription text from S3');
        try {
            $result = $this->s3->getObject([
                'Bucket' => $this->bucket,
                'Key'    => "$this->messageId.wav.json"
            ]);
        } catch (S3Exception $e) {
            \Log::error("TranscribeVoiceMessageJob@handle: Error fetching transcription text from S3", [
                'messageId' => $this->messageId,
                'message' =>  $e->getMessage(),
                'code' => $e->getCode()
            ]);
        }
        \Log::info('TranscribeVoiceMessageJob@handle: Got response from S3.  Extracting transcription from json object');

        $transcription = json_decode($result['Body'])->results->transcripts[0]->transcript;
        \Log::info('TranscribeVoiceMessageJob@handle: Transcription says - ', [
            'transcription' => $transcription
        ]);

        \Log::info('TranscribeVoiceMessageJob@handle: Deleting transcription text from S3');
        $this->s3->deleteObject([
            'Bucket' => $this->bucket,
            'Key'    => "$this->messageId.wav.json"
        ]);

        return $transcription;
    }

    /**
     * Transcribe the wav file on AWS
     */
    private function transcribeWavFile()
    {
        \Log::info('TranscribeVoiceMessageJob@transcribeWavFile: Created AWS TranscribeClient');

        \Log::info('TranscribeVoiceMessageJob@transcribeWavFile: Sending transcription job request');
        // TODO: try/catch please?
        $result = $this->transcribe->startTranscriptionJob([
            'LanguageCode' => 'en-US',
            'Media' => [
                'MediaFileUri' => "s3://$this->bucket/$this->messageId.wav",
            ],
            'MediaFormat' => 'wav',
            'OutputBucketName' => $this->bucket,
            'TranscriptionJobName' => "$this->messageId.wav",
        ]);

        \Log::info('TranscribeVoiceMessageJob@transcribeWavFile: Transcription job sent.  Waiting for it to complete');
        while (true) {
            \Log::info('TranscribeVoiceMessageJob@transcribeWavFile: Fetching job status');
            $result = $this->transcribe->getTranscriptionJob([
                'TranscriptionJobName' => "$this->messageId.wav",
            ]);

            if(in_array($result['TranscriptionJob']['TranscriptionJobStatus'], ['COMPLETED', 'FAILED'])) {
                \Log::info("TranscribeVoiceMessageJob@transcribeWavFile: Job status is {$result['TranscriptionJob']['TranscriptionJobStatus']}.  Breaking.");
                break;
            }

            \Log::info('TranscribeVoiceMessageJob@transcribeWavFile: Job not ready yet.  Sleeping 5 seconds...');
            sleep(5);
        }

        if($result['TranscriptionJob']['TranscriptionJobStatus'] == "FAILED") {
            \Log::error("TranscribeVoiceMessageJob@transcribeWavFile: Error transcribing voicemail message in AWS", [
                'messageId' => $this->messageId,
            ]);
            return response()->json("", 200);
        }

        \Log::info('TranscribeVoiceMessageJob@transcribeWavFile: Job completed successfully');

        \Log::info('TranscribeVoiceMessageJob@transcribeWavFile: Deleting completed transcription job');
        $this->transcribe->deleteTranscriptionJob([
            'TranscriptionJobName' => "$this->messageId.wav",
        ]);

        \Log::info('TranscribeVoiceMessageJob@transcribeWavFile: Deleting voicemail wav file from S3');
        $this->s3->deleteObject([
            'Bucket' => $this->bucket,
            'Key'    => "$this->messageId.wav"
        ]);
    }
}
