<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Input\ConfirmUnscannedInput;
use App\ApiResource\UnscannedAttachmentLink;
use App\Mail\Body\AttachmentUrls;
use App\Repository\AttachmentRepository;
use App\Repository\DraftAttachmentRepository;
use App\Scan\DownloadPolicy;
use App\Security\CurrentUser;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @implements ProcessorInterface<ConfirmUnscannedInput, UnscannedAttachmentLink>
 */
final readonly class UnscannedAttachmentLinkProcessor implements ProcessorInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private AttachmentRepository $attachments,
        private DraftAttachmentRepository $draftAttachments,
        private AttachmentUrls $urls,
        private DownloadPolicy $downloads,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): UnscannedAttachmentLink
    {
        $user = $this->currentUser->get();
        $id = \is_string($uriVariables['id'] ?? null) ? $uriVariables['id'] : '';

        if (UnscannedAttachmentLink::DRAFT === $operation->getName()) {
            $attachment = $this->draftAttachments->findById($id);
            if (null === $attachment || !$attachment->getDraft()->getOwner()->getId()->equals($user->getId())) {
                throw new NotFoundHttpException('Attachment not found.');
            }
            $status = $attachment->getScanStatus();
            $url = $this->urls->forDraft($attachment) ?? $this->urls->confirmedUnscannedForDraft($attachment);
        } else {
            $attachment = $this->attachments->findById($id);
            if (null === $attachment || !$attachment->getMailAccount()->getOwner()->getId()->equals($user->getId())) {
                throw new NotFoundHttpException('Attachment not found.');
            }
            $status = $attachment->getScanStatus();
            $url = $this->urls->for($attachment) ?? $this->urls->confirmedUnscanned($attachment);
        }

        if (null === $url) {
            throw $this->downloads->refusal($status) ?? new \LogicException('An attachment without a link has a reason for it.');
        }

        return new UnscannedAttachmentLink($url);
    }
}
