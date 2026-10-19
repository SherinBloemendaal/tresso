<?php

declare(strict_types=1);

namespace App\Mail\Autoconfig;

use App\Mail\ServerSettings;

final readonly class DetectedServers
{
    /**
     * @param list<string> $authentication sign-in methods the provider lists: "password", "oauth2"
     */
    public function __construct(
        public ServerSettings $imap,
        public ServerSettings $smtp,
        public string $username,
        public array $authentication,
        public ?string $source = null,
    ) {
    }

    /** @param string $source where the settings came from: provider, provider-well-known, ispdb, ispdb-mx */
    public function from(string $source): self
    {
        return new self($this->imap, $this->smtp, $this->username, $this->authentication, $source);
    }
}
