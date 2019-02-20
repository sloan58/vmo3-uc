<?php

require 'vendor/autoload.php';

use Aws\S3\S3Client;

use Aws\Exception\AwsException;

//Create a S3Client
$s3 = new Aws\S3\S3Client([
    'profile' => 'default',
    'version' => 'latest',
    'region' => 'us-east-2'
]);

$buckets = $s3->listBuckets()['Buckets'];

foreach($buckets as $bucket)
{
    var_dump($bucket['Name']);
}