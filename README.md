# VMO<sup>3</sup>: uc-connector

## Description
The uc-connector component bridges together the Cisco UC servers and services with the VMO<sup>3</sup> mediator and monitor API's found in the [VMO<sup>3</sup> repository](https://github.com/clintmann/vmo3).
## Functional Details  
This connector acts as a gateway to trigger UC-specific activities when a user's Out of Office status has changed.  The component pieces and API's leveraged by the uc-connector are listed below.

* Unity Connection
    * [CUPI REST API](https://www.cisco.com/c/en/us/td/docs/voice_ip_comm/connection/REST-API/CUPI_API/b_CUPI-API/b_CUPI-API_chapter_01.html) for administrative data
    * [CUNI SOAP API](https://www.cisco.com/c/en/us/td/docs/voice_ip_comm/connection/REST-API/CUNI_API/b_CUC_CUNI_API.html) for message subscription and notification
    * [CUMI REST XML](https://www.cisco.com/c/en/us/td/docs/voice_ip_comm/connection/REST-API/CUMI_API/b_CUMI-API.html) for message delivery and retrieval
* Unified Communications Manager
    * [AXL SOAP API](https://developer.cisco.com/site/axl/) for provisioning call forward settings
* Webex Teams
    * [Messages REST API](https://developer.webex.com/docs/api/v1/messages) for message transcription delivery
* AWS
    * [S3 REST API](https://docs.aws.amazon.com/AmazonS3/latest/API/Welcome.html) for message and transcription storage
    * [Polly REST API](https://aws.amazon.com/polly/developers/) for text synthesis
    * [Transcription REST API](https://aws.amazon.com/transcribe/) for voicemail transcription
      
## Overview
The uc-connector uses an open-source PHP microservices MVC framework called [Lumen](https://lumen.laravel.com/) to provide the interface for VMO<sup>3</sup> interop.  The main files of interest for the uc-connector component of VMO<sup>3</sup> are described below:

* **routes/web.php** - this file serves the web routes for the app.
* **app/Http/Controllers/VmoController.php** - The main entrypoint for program logic containing the class and methods used to process requests and return responses.
* **app/Jobs/TranscribeVoiceMessageJob.php** - When performing message transcriptions, the process can be time consuming and so an asynchronous Job queue was created to offload this process so that requests can be responded to without risking timeout.  This file handles generating Job classes which process message transcription requests.  
## Message Flow and Operations

### Get all Unity Connection Users
The initial communication between the uc-connector and the mediator involves learning about the available users in Cisco Unity Connection.  In order to synchronize the data, the mediator makes a call to the uc-connector service as described below:

- PATH: **/ucxn/users**
- METHOD: **GET**
- RETURN: **Array**
- FORMAT: **JSON**

```json
[
    {
        "ObjectId": "3b82980a-f9ba-4492-ad09-b4d202c1789c",
        "Alias": "marty@karmatek.io",
        "Extension": "88109",
        "CallHandlerObjectId": "49ec1351-afed-410a-9e5d-684dea9a2fd8",
        "AlternateGreetingEnabled": "true"
    },
    {
        "ObjectId": "074ed3f3-8d01-4d93-bbb0-73ac6e03acf4",
        "Alias": "clint@karmatek.io",
        "Extension": "78109",
        "CallHandlerObjectId": "ecf36271-6cd0-4cf1-aa49-8fbc2d6059ed",
        "AlternateGreetingEnabled": "false"
    },
    {
        "ObjectId": "56a6d927-4935-417b-810d-04bcd06f9fbb",
        "Alias": "chris@karmatek.io",
        "Extension": "68109",
        "CallHandlerObjectId": "0fc7d7c6-a9cf-4233-b027-6ffb1265f351",
        "AlternateGreetingEnabled": "false"
    }
]
```

### Update User Out of Office Status
When a user has modified their Out of Office status, a message flow will begin with the VMO<sup>3</sup> Outlook monitor, passing through to the mediator and finally hitting the uc-connector to perform UC-related functions.  The API for this is described below.

- PATH: **/ucxn/{callHandlerId}/greeting**
- BODY: **action (boolean, required)** | **message (string, optional)**
- METHOD: **POST**
- RETURN: **NULL**

Setting an out of office status will trigger different workflows depending on whether the status is being enabled or disabled.  The workflows for each are described below.

## Enable Out of Office

1. Enable the `Alternate` greeting in Cisco Unity Connection using the CUPI API
    ```php
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
    ```
2. Generate Text to Speech for the Unity Connection outgoing message

    The uc-connector will take the string received in the POST's **message** parameter and submit it to the AWS Polly API for voice synthesis.
    ```php
    try {
        $response = $client->synthesizeSpeech($speech);
    } catch(PollyException $e) {
        \Log::error('Vmo3Controller@textToSpeech: Error calling AWS Polly API', [
            'message' =>  $e->getAwsErrorMessage(),
            'code' => $e->getStatusCode()
        ]);
        return response()->json("Error calling AWS Polly API", 500);
    }
    ```
3. Convert the .wav file from AWS into the proper format for Unity Connection.  The audio file requirements are very specific (PCM 8k 16bit mono) and so we use the `sox` utility to do our conversion.
    ```php
    exec("sox " . storage_path("$callhandler.mp3") . " -r 8000 -c 1 -b 16 " . storage_path("$callhandler.wav"));
    ```
4. Upload the file to the Unity Connection Call Handler responsible for the User greeting using the CUPI API
    ```php
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
    ```
5. Update the Cisco UCM call forwarding status to `True` using the AXL API
    ```php
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
    ```
    
## Disable Out of Office

 Disabling the OOO status is much simpler.  We take steps `1` and `5` from above but set each value to `False`
## Subscribing to Unity Connection `NEW_MESSAGE` notifications
The Cisco Unity Connection Notification API (CUNI) allows remote system to subscribe to notifications, such as new messages.  The uc-connector component uses the API call below in order to register a callback URL for this purpose.

```php
    try {
        $res = $cuni->subscribe([
            'resourceIdList' => [
                'string' => $userAlias,
            ],
            'callbackServiceInfo' => [
                'callbackServiceUrl' => 'http://' . env('CALLBACK_HOST') . /callback',
                'hostname' => env('CALLBACK_HOST')
            ],
            'eventTypeList' => [
                'string' => 'NEW_MESSAGE'
            ],
            'expiration' => date(DATE_ATOM, mktime(0, 0, 0, 7, 1, 2020))
        ]);
    } catch (\SoapFault $e) {
        var_dump($e->getCode(), $cuni->__getLastRequest(), $cuni->__getLastResponse());
        exit;
    }
```

## Retrieving new voice messages

When a notification is received via CUNI callback URL, uc-connector processes the message by requesting a download of the new voice message from the Unity Connection server.

Since the process of downloading and transcribing the voice message can be time consuming, the `ucxnCuniCallback` method in `Vmo3Controller` initiates an async job to perform this process so that it can respond to the Unity Connection server with a `200 OK` right away.  The message attributes are extracted and injected into the Job class.

```php
dispatch(new TranscribeVoiceMessageJob($alias, $displayName, $messageId, $callerAni));
```

The Unity Connection CUMI API is used to download the voice message from the server, as shown below.

```php
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
```

### Uploading to AWS S3
In order to use the AWS transcription service, we need to place the newly downloaded voice message in an AWS S3 bucket.  The following code performs the upload into S3.

```php
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
``` 

## Transcribe the Message using AWS Transcription Service
After the new voice message is uploaded in the S3, we can kick off the process to transcribe the voice message.  The below code will initiate the transcription process and check intermittently until the process is complete, so that it knows when to download the transcription file.

```php
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
```

## Get Transcription Text from S3
When the transcription is complete, the output will be placed in the same S3 bucket used for the audio file.  The below code will download the transcription file for local processing.

```php
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
```

## Sending the Transcription to Webex Teams
After the new voice message is transcribed, it will be posted to Webex Teams.  We're currently using a group space while the product has been in development, but it could easily be adjusted to post a message to the individual that received the message.

```php
    try {
        $res = $client->request('POST', 'https://api.ciscospark.com/v1/messages', [
            'headers' => [
                'Authorization' => 'Bearer ' . env('TEAMS_TOKEN'),
            ],
            'verify' => false,
            RequestOptions::JSON => [
                'roomId' => env('TEAMS_ROOM_ID'),
                'text' => $transcription
            ]
        ]);
    } catch (RequestException $e) {
        \Log::error('TranscribeVoiceMessageJob@handle: Received an error when posting to the Webex Teams room - ', [
            $e->getMessage()
        ]);
    }
```
