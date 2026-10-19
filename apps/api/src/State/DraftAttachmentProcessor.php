<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\ApiResource\Output\DraftAttachmentOutput;
use App\ApiResource\Input\Draft\ForwardAttachmentInput;
use App\Entity\Attachment;
use App\Entity\Draft;
use App\Entity\DraftAttachment;
use App\Entity\User;
use App\Enum\ScanStatus;
use App\Mail\Body\AttachmentUrls;
use App\Outbox\AttachmentLimits;
use App\Outbox\CollectUpload;
use App\Outbox\UploadCollector;
use App\Repository\AttachmentRepository;
use App\Repository\DraftAttachmentRepository;
use App\Repository\DraftRepository;
use App\Security\CurrentUser;
use App\Storage\ObjectStorage;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * Attaches files to a draft (uploaded, or taken from a received message for a forward) and removes them. A draft
 * being sent takes no changes; its attachments are what goes out.
 *
 * @implements ProcessorInterface<ForwardAttachmentInput|null, DraftAttachmentOutput|null>
 */
final readonly class DraftAttachmentProcessor implements ProcessorInterface
{
    private const string CONTENT_TYPE = '~^[a-z0-9][a-z0-9!#$&^_.+-]{0,126}/[a-z0-9][a-z0-9!#$&^_.+-]{0,126}$~i';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private CurrentUser $currentUser,
        private DraftRepository $drafts,
        private DraftAttachmentRepository $draftAttachments,
        private AttachmentRepository $attachments,
        private ObjectStorage $storage,
        private UploadCollector $uploads,
        private AttachmentLimits $limits,
        private AttachmentUrls $urls,
        private RequestStack $requests,
        private MessageBusInterface $bus,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?DraftAttachmentOutput
    {
        $user = $this->currentUser->get();
        $draftId = $uriVariables['draftId'] ?? null;
        if (!\is_string($draftId) || !$this->drafts->findOneOwnedBy($user, $draftId) instanceof Draft) {
            throw new NotFoundHttpException('Draft not found.');
        }

        if ($operation instanceof DeleteOperationInterface) {
            $id = $uriVariables['id'] ?? null;
            $this->entityManager->wrapInTransaction(function () use ($user, $draftId, $id): void {
                $draft = $this->lock($user, $draftId);
                $attachment = \is_string($id) ? $this->draftAttachments->findOneOwnedBy($user, $draft, $id) : null;
                if (!$attachment instanceof DraftAttachment) {
                    throw new NotFoundHttpException('Attachment not found.');
                }
                $draft->detach($attachment);
                $this->entityManager->remove($attachment);
                $this->uploads->releaseAll([$attachment]);
                $this->entityManager->flush();
            });

            return null;
        }

        $attachment = $data instanceof ForwardAttachmentInput ? $this->forward($user, $draftId, $data) : $this->upload($user, $draftId);

        return DraftAttachmentOutput::fromEntity($attachment, $this->urls->forDraft($attachment));
    }

    private function upload(User $user, string $draftId): DraftAttachment
    {
        $file = $this->requests->getCurrentRequest()?->files->get('file');
        if (!$file instanceof UploadedFile) {
            throw self::invalid('file', 'Send the file as multipart/form-data in the field "file".');
        }
        if (!$file->isValid()) {
            throw self::invalid('file', \in_array($file->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true)
                ? \sprintf('The file is too large (at most %s).', AttachmentLimits::describe($this->limits->maxAttachmentBytes))
                : 'The file did not arrive completely. Try again.');
        }
        $size = (int) $file->getSize();
        if (0 === $size) {
            throw self::invalid('file', 'The file is empty.');
        }
        $sha = hash_file('sha256', $file->getPathname());
        if (false === $sha) {
            throw new \RuntimeException('The uploaded file could not be read.');
        }
        $filename = self::filename($file->getClientOriginalName());
        $contentType = self::contentType($file);
        $key = DraftAttachment::uploadKey($sha);

        try {
            return $this->entityManager->wrapInTransaction(function () use ($user, $draftId, $file, $size, $sha, $filename, $contentType, $key): DraftAttachment {
                $draft = $this->lock($user, $draftId);
                $this->fits($draft, $size);
                // Locked like a removal, so the file is never collected between being stored and being referred to.
                $this->uploads->lock($key);
                $this->storage->write($key, (string) file_get_contents($file->getPathname()), $contentType);
                $attachment = new DraftAttachment($draft, $filename, $contentType, $size, $sha, $key, $draft->nextAttachmentPosition(), new \DateTimeImmutable());
                $this->entityManager->persist($attachment);
                $draft->attach($attachment);
                $this->entityManager->flush();

                return $attachment;
            });
        } catch (\Throwable $exception) {
            // Stored but never referred to: it goes again unless another draft has it.
            $this->bus->dispatch(new CollectUpload($key), [new DelayStamp(60_000)]);
            throw $exception;
        }
    }

    private function forward(User $user, string $draftId, ForwardAttachmentInput $input): DraftAttachment
    {
        $source = $this->attachments->findById($input->attachmentId);
        if (!$source instanceof Attachment || !$source->getMailAccount()->getOwner()->getId()->equals($user->getId())) {
            throw self::invalid('attachmentId', 'No attachment of yours has that id.');
        }
        if (ScanStatus::Infected === $source->getScanStatus()) {
            throw self::invalid('attachmentId', \sprintf('"%s" contains malware and cannot be forwarded.', $source->getFilename()));
        }

        return $this->entityManager->wrapInTransaction(function () use ($user, $draftId, $source): DraftAttachment {
            $draft = $this->lock($user, $draftId);
            // Forwarding the same file again (a retry) does not attach it twice.
            foreach ($draft->getAttachments() as $existing) {
                if ($existing->getObjectKey() === $source->getObjectKey() && $existing->getFilename() === $source->getFilename()) {
                    return $existing;
                }
            }
            $this->fits($draft, $source->getSize());
            $attachment = new DraftAttachment(
                $draft,
                self::filename($source->getFilename()),
                $source->getContentType(),
                $source->getSize(),
                $source->getSha256(),
                $source->getObjectKey(),
                $draft->nextAttachmentPosition(),
                new \DateTimeImmutable(),
            );
            $attachment->takeScanFrom($source);
            $this->entityManager->persist($attachment);
            $draft->attach($attachment);
            $this->entityManager->flush();

            return $attachment;
        });
    }

    private function lock(User $user, string $draftId): Draft
    {
        $owned = $this->drafts->findOneOwnedBy($user, $draftId);
        $draft = null === $owned ? null : $this->entityManager->find(Draft::class, $owned->getId(), LockMode::PESSIMISTIC_WRITE);
        if (!$draft instanceof Draft || $draft->isSending()) {
            throw new NotFoundHttpException('Draft not found.');
        }

        return $draft;
    }

    /** The attachments of a message together stay within what mail servers take. */
    private function fits(Draft $draft, int $size): void
    {
        $total = $this->draftAttachments->totalSize($draft) + $size;
        if ($total > $this->limits->maxAttachmentBytes) {
            throw self::invalid('file', \sprintf('This would make the attachments too large together (at most %s).', AttachmentLimits::describe($this->limits->maxAttachmentBytes)));
        }
    }

    /** The name as shown and sent: no path, no control characters, at most 255 characters. */
    private static function filename(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = trim((string) preg_replace('/[\x00-\x1f\x7f]+/u', '', $name));
        $name = mb_substr($name, 0, 255);

        return '' === $name || '.' === $name || '..' === $name ? 'attachment' : $name;
    }

    /** What the content is, from the file itself where it can tell; otherwise what the sender said, if well-formed. */
    private static function contentType(UploadedFile $file): string
    {
        $guessed = $file->getMimeType();
        if (null !== $guessed && 'application/octet-stream' !== $guessed && 1 === preg_match(self::CONTENT_TYPE, $guessed)) {
            return strtolower($guessed);
        }
        $claimed = $file->getClientMimeType();

        return 1 === preg_match(self::CONTENT_TYPE, $claimed) ? strtolower($claimed) : 'application/octet-stream';
    }

    private static function invalid(string $path, string $message): ValidationException
    {
        return new ValidationException(new ConstraintViolationList([new ConstraintViolation($message, null, [], null, $path, null)]));
    }
}
