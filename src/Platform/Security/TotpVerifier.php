<?php

declare(strict_types=1);

namespace App\Platform\Security;

final readonly class TotpVerifier
{
    private const int PERIOD_SECONDS = 30;
    private const int DIGITS = 6;
    private const int DRIFT_STEPS = 1;
    private const string ALGORITHM = 'sha1';

    public function codeAtTime(string $secret, int $unixTime): string
    {
        if ('' === $secret || $unixTime < 0) {
            throw new \InvalidArgumentException('Ein TOTP-Geheimnis und ein nichtnegativer Zeitpunkt sind erforderlich.');
        }

        return $this->codeForCounter($secret, intdiv($unixTime, self::PERIOD_SECONDS));
    }

    public function matchingCounter(string $secret, string $code, int $unixTime): ?int
    {
        if ('' === $secret || $unixTime < 0 || 1 !== preg_match('/\A[0-9]{6}\z/', $code)) {
            return null;
        }

        $currentCounter = intdiv($unixTime, self::PERIOD_SECONDS);

        for ($offset = self::DRIFT_STEPS; $offset >= -self::DRIFT_STEPS; --$offset) {
            $counter = $currentCounter + $offset;
            if ($counter < 0) {
                continue;
            }

            if (hash_equals($this->codeForCounter($secret, $counter), $code)) {
                return $counter;
            }
        }

        return null;
    }

    private function codeForCounter(string $secret, int $counter): string
    {
        if ($counter < 0) {
            throw new \InvalidArgumentException('Der TOTP-Zähler darf nicht negativ sein.');
        }

        $counterBytes = pack('N2', intdiv($counter, 4_294_967_296), $counter % 4_294_967_296);
        $digest = hash_hmac(self::ALGORITHM, $counterBytes, $secret, true);
        $offset = ord($digest[strlen($digest) - 1]) & 15;
        $binary = ((ord($digest[$offset]) & 127) << 24)
            | ((ord($digest[$offset + 1]) & 255) << 16)
            | ((ord($digest[$offset + 2]) & 255) << 8)
            | (ord($digest[$offset + 3]) & 255);

        return str_pad((string) ($binary % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }
}
