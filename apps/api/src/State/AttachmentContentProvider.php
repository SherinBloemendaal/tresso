<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\Attachment;
use App\Enum\ScanStatus;
use App\Repository\AttachmentRepository;
use App\Storage\ObjectNotFound;
use App\Storage\ObjectStorage;
use Symfony\Component\HttpFoundation\Exception\SignedUriException;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * Streams attachment content for a valid signed URL. Every response is locked down as if it were hostile: no type
 * sniffing, no scripts or plugins even when opened directly, and only plain image types render inline.
 *
 * @implements ProviderInterface<StreamedResponse>
 */
final readonly class AttachmentContentProvider implements ProviderInterface
{
    private const array INLINE_TYPES = ['image/png', 'image/gif', 'image/jpeg', 'image/webp', 'image/bmp', 'image/avif'];

    public function __construct(
        private AttachmentRepository $attachments,
        private ObjectStorage $storage,
        private UriSigner $signer,
        private RequestStack $requests,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): StreamedResponse
    {
        $request = $this->requests->getCurrentRequest() ?? throw new \LogicException('Attachment content is only served over HTTP.');
        try {
            $this->signer->verify($request);
        } catch (SignedUriException $exception) {
            throw new AccessDeniedHttpException('This link is invalid or has expired.', $exception);
        }

        $id = $uriVariables['id'] ?? null;
        $attachment = \is_string($id) ? $this->attachments->findById($id) : null;
        if (!$attachment instanceof Attachment) {
            throw new NotFoundHttpException('Attachment not found.');
        }
        if (ScanStatus::Infected === $attachment->getScanStatus()) {
            throw new AccessDeniedHttpException('This attachment is quarantined because it contains malware.');
        }

        try {
            $chunks = $this->storage->stream($attachment->getObjectKey());
        } catch (ObjectNotFound $exception) {
            throw new ServiceUnavailableHttpException(null, 'The content of this attachment is not available.', $exception);
        }

        $contentType = strtolower($attachment->getContentType());
        $inline = \in_array($contentType, self::INLINE_TYPES, true);

        $response = new StreamedResponse(static function () use ($chunks): void {
            foreach ($chunks as $chunk) {
                echo $chunk;
                flush();
            }
        });
        $response->headers->set('Content-Type', $inline ? $contentType : 'application/octet-stream');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            $inline ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT,
            $attachment->getFilename(),
            self::asciiFallback($attachment->getFilename()),
        ));
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Security-Policy', "default-src 'none'; sandbox");
        $response->headers->set('Cross-Origin-Resource-Policy', 'cross-origin');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->setPrivate();
        $response->setMaxAge(3600);
        $response->setEtag($attachment->getSha256());

        return $response;
    }

    private static function asciiFallback(string $filename): string
    {
        $ascii = (string) preg_replace('~[^\x20-\x7e]|[%/\\\\"]~u', '_', $filename);

        return '' === trim($ascii, '_ ') ? 'attachment' : $ascii;
    }
}
