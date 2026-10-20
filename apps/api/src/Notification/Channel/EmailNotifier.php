<?php

declare(strict_types=1);

namespace App\Notification\Channel;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Notifications by email, from the system sender (not from one of the user's own accounts).
 */
final readonly class EmailNotifier
{
    public function __construct(
        private MailerInterface $mailer,
        #[Autowire('%env(default::NOTIFICATIONS_FROM)%')]
        private ?string $from,
        #[Autowire('%env(WEB_APP_URL)%')]
        private string $webAppUrl,
    ) {
    }

    public function send(string $to, PushMessage $message): PushResult
    {
        $link = rtrim($this->webAppUrl, '/').($message->path ?? '/');
        $email = new Email()
            ->from($this->sender())
            ->to($to)
            ->subject($message->title)
            ->text($message->body."\n\n".$link)
            ->html('<p>'.nl2br(htmlspecialchars($message->body)).'</p><p><a href="'.htmlspecialchars($link).'">Open in Tresso</a></p>');
        $email->getHeaders()->addTextHeader('Auto-Submitted', 'auto-generated');

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            return PushResult::failed('The email could not be sent: '.$e->getMessage());
        }

        return PushResult::sent();
    }

    /** The system sender that every email Tresso itself sends comes from. */
    public function sender(): Address
    {
        return Address::create(null !== $this->from && '' !== $this->from ? $this->from : 'Tresso <notifications@localhost>');
    }
}
