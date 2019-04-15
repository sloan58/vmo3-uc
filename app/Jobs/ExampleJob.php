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

class ExampleJob extends Job
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
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct()
    {
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
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        \Log::info("Let's get this job done!");
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
}
