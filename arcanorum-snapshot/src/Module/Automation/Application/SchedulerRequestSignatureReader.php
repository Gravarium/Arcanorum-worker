<?php

declare(strict_types=1);

namespace App\Module\Automation\Application;

final class SchedulerRequestSignatureReader implements SchedulerRequestSignatureReaderInterface
{
    private const int MAX_INPUT_BYTES = 66;
    private const int INPUT_TIMEOUT_SECONDS = 5;

    /** @param resource $stream */
    public function read($stream): ?string
    {
        if (!is_resource($stream)) {
            return null;
        }

        stream_set_timeout($stream, self::INPUT_TIMEOUT_SECONDS);
        $input = fread($stream, self::MAX_INPUT_BYTES + 1);
        /** @var array<string, mixed> $metadata */
        $metadata = stream_get_meta_data($stream);
        $timedOut = true === ($metadata['timed_out'] ?? false);
        if (!is_string($input)
            || $timedOut
            || 1 !== preg_match('/\A([a-f0-9]{64})(?:\r?\n)?\z/D', $input, $matches)
        ) {
            return null;
        }

        return $matches[1];
    }
}
