<?php

declare(strict_types=1);

namespace App\Platform\Hosting;

final class PrivateStorageOperationFailed extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Der private Dateispeicher konnte die angeforderte Aktion nicht sicher ausführen.');
    }
}
