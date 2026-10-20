<?php

declare(strict_types=1);

namespace App\Storage;

use AsyncAws\Core\Exception\Http\ClientException;
use AsyncAws\Core\Waiter;
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

    public function write(string $key, mixed $content, string $contentType, array $metadata = []): void
    {
        if (!\is_string($content) && !\is_resource($content)) {
            throw new \InvalidArgumentException('Object content is a string or a stream.');
        }
        $this->client->putObject(['Bucket' => $this->bucket, 'Key' => $key, 'Body' => $content, 'ContentType' => $contentType, 'Metadata' => $metadata])->resolve();
    }

    public function exists(string $key): bool
    {
        // A missing object leaves the waiter pending (404); any other answer than 200 or 404 is an error.
        return match ($this->client->objectExists(['Bucket' => $this->bucket, 'Key' => $key])->getState()) {
            Waiter::STATE_SUCCESS => true,
            Waiter::STATE_PENDING => false,
            default => throw new \RuntimeException(\sprintf('Could not tell whether object "%s" exists.', $key)),
        };
    }

    public function list(string $prefix): iterable
    {
        foreach ($this->client->listObjectsV2(['Bucket' => $this->bucket, 'Prefix' => $prefix])->getContents() as $object) {
            $key = $object->getKey();
            if (null !== $key) {
                yield $key;
            }
        }
    }

    public function move(string $from, string $to): void
    {
        $this->copy($from, $to);
        $this->client->deleteObject(['Bucket' => $this->bucket, 'Key' => $from])->resolve();
    }

    public function copy(string $from, string $to): void
    {
        try {
            $this->client->copyObject(['Bucket' => $this->bucket, 'Key' => $to, 'CopySource' => rawurlencode($this->bucket).'/'.str_replace('%2F', '/', rawurlencode($from))])->resolve();
        } catch (ClientException $exception) {
            if ('NoSuchKey' === $exception->getAwsCode() || 404 === $exception->getCode()) {
                throw ObjectNotFound::forKey($from, $exception);
            }
            throw $exception;
        }
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
