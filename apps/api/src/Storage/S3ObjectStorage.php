<?php

declare(strict_types=1);

namespace App\Storage;

use AsyncAws\S3\Exception\NoSuchKeyException;
use AsyncAws\S3\Result\GetObjectOutput;
use AsyncAws\S3\S3Client;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class S3ObjectStorage implements ObjectStorage
{
    private S3Client $client;

    public function __construct(
        #[Autowire(env: 'S3_ENDPOINT')]
        string $endpoint,
        #[Autowire(env: 'S3_REGION')]
        string $region,
        #[Autowire(env: 'S3_ACCESS_KEY')]
        string $accessKey,
        #[Autowire(env: 'S3_SECRET_KEY')]
        #[\SensitiveParameter]
        string $secretKey,
        #[Autowire(env: 'S3_BUCKET')]
        private string $bucket,
        HttpClientInterface $httpClient,
        LoggerInterface $logger,
    ) {
        $this->client = new S3Client([
            'endpoint' => $endpoint,
            'region' => $region,
            'accessKeyId' => $accessKey,
            'accessKeySecret' => $secretKey,
            'pathStyleEndpoint' => 'true',
            'sharedConfigFile' => '/dev/null',
            'sharedCredentialsFile' => '/dev/null',
        ], null, $httpClient, $logger);
    }

    public function read(string $key): string
    {
        return $this->open($key)->getBody()->getContentAsString();
    }

    public function stream(string $key): iterable
    {
        return $this->open($key)->getBody()->getChunks();
    }

    public function write(string $key, string $content, string $contentType): void
    {
        $this->client->putObject(['Bucket' => $this->bucket, 'Key' => $key, 'Body' => $content, 'ContentType' => $contentType])->resolve();
    }

    public function delete(string $key): void
    {
        // S3 answers a delete of a missing key with success too.
        $this->client->deleteObject(['Bucket' => $this->bucket, 'Key' => $key])->resolve();
    }

    private function open(string $key): GetObjectOutput
    {
        $output = $this->client->getObject(['Bucket' => $this->bucket, 'Key' => $key]);
        try {
            $output->resolve();
        } catch (NoSuchKeyException $exception) {
            throw ObjectNotFound::forKey($key, $exception);
        }

        return $output;
    }
}
