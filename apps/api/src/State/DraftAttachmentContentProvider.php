<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\DraftAttachment;
use App\Mail\Body\AttachmentResponse;
use App\Mail\Body\AttachmentUrls;
use App\Repository\DraftAttachmentRepository;
use App\Scan\DownloadPolicy;
use App\Storage\ObjectNotFound;
use App\Storage\ObjectStorage;
use Symfony\Component\HttpFoundation\Exception\SignedUriException;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * @implements ProviderInterface<StreamedResponse>
 */
final readonly class DraftAttachmentContentProvider implements ProviderInterface
{
    public function __construct(
        private DraftAttachmentRepository $attachments,
        private ObjectStorage $storage,
        private UriSigner $signer,
        private RequestStack $requests,
        private DownloadPolicy $downloads,
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
        if (!$attachment instanceof DraftAttachment) {
            throw new NotFoundHttpException('Attachment not found.');
        }
        $refusal = $this->downloads->refusal($attachment->getScanStatus(), AttachmentUrls::isConfirmedUnscanned($request));
        if (null !== $refusal) {
            throw $refusal;
        }

        try {
            $chunks = $this->storage->stream($attachment->getObjectKey());
        } catch (ObjectNotFound $exception) {
            throw new ServiceUnavailableHttpException(null, 'The content of this attachment is not available.', $exception);
        }

        return AttachmentResponse::stream($chunks, $attachment->getFilename(), $attachment->getContentType(), $attachment->getSha256());
    }
}
