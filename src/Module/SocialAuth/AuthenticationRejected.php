<?php

declare(strict_types=1);

namespace App\Module\SocialAuth;

final class AuthenticationRejected extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Die Anmeldung konnte nicht abgeschlossen werden.');
    }
}
