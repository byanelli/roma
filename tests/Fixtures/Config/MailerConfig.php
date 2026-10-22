<?php

namespace BYanelli\Roma\Tests\Fixtures\Config;

use BYanelli\Roma\Config\ConfigObject;

#[ConfigObject('services.mailer')]
final readonly class MailerConfig
{
    public function __construct(
        #[\SensitiveParameter]
        public string $apiKey,
        public string $from = 'noreply@example.com',
    ) {}
}
