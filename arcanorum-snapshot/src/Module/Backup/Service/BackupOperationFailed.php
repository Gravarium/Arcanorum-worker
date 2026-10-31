<?php

declare(strict_types=1);

namespace App\Module\Backup\Service;

final class BackupOperationFailed extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Die Sicherungsaktion konnte nicht sicher ausgeführt werden.');
    }
}
