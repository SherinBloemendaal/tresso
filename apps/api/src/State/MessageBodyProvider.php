<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\MessageBody;
use App\ApiResource\Output\MessageAttachment;
use App\Entity\Message;
use App\Mail\Body\AttachmentUrls;
use App\Mail\Body\MailHtmlSanitizer;
use App\Mail\Body\MediaUrlPolicy;
use App\Mail\Body\MessageBodyParser;
use App\Repository\AttachmentRepository;
use App\Repository\MessageRepository;
use App\Security\CurrentUser;
use App\Storage\ObjectNotFound;
use App\Storage\ObjectStorage;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * @implements ProviderInterface<MessageBody>
 */
final readonly class MessageBodyProvider implements ProviderInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private MessageRepository $messages,
        private AttachmentRepository $attachments,
        private ObjectStorage $storage,
        private MessageBodyParser $parser,
        private MailHtmlSanitizer $sanitizer,
        private AttachmentUrls $attachmentUrls,
        private RequestStack $requests,
        private LoggerInterface $logger,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): MessageBody
    {
        $id = $uriVariables['id'] ?? null;
        $message = \is_string($id) ? $this->messages->findOneOwnedBy($this->currentUser->get(), $id) : null;
        if (!$message instanceof Message) {
            throw new NotFoundHttpException('Message not found.');
        }

        $allowRemote = 'allow' === $this->requests->getCurrentRequest()?->query->get('remoteContent');
        $parsed = $this->parser->parse($this->raw($message));

        $attachments = [];
        $contentIdUrls = [];
        foreach ($this->attachments->findByMessage($message) as $attachment) {
            $url = $this->attachmentUrls->for($attachment);
            $attachments[] = MessageAttachment::fromEntity($attachment, $url, $this->attachmentUrls->access($attachment->getScanStatus()));
            $contentId = $attachment->getContentId();
            if (null !== $url && null !== $contentId) {
                $contentIdUrls[MediaUrlPolicy::normalizeContentId($contentId)] = $url;
            }
        }

        $policy = new MediaUrlPolicy($contentIdUrls, $allowRemote);
        $html = null === $parsed->html ? null : $this->sanitizer->sanitize($parsed->html, $policy);

        return new MessageBody(
            $message->getId()->toRfc4122(),
            $html,
            $parsed->text,
            $policy->remoteContentFound(),
            $allowRemote && $policy->remoteContentFound(),
            $attachments,
        );
    }

    private function raw(Message $message): string
    {
        try {
            return $this->storage->read($message->getRawObjectKey());
        } catch (ObjectNotFound $exception) {
            $this->logger->error('Raw message is missing from object storage.', ['messageId' => $message->getId()->toRfc4122(), 'exception' => $exception]);

            throw new ServiceUnavailableHttpException(null, 'The content of this message is not available.', $exception);
        }
    }
}
