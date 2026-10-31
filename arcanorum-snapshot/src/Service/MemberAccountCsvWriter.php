<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\MemberAccount;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class MemberAccountCsvWriter
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    /**
     * @param iterable<MemberAccount> $members
     * @param resource                $output
     */
    public function write(iterable $members, mixed $output, string $locale): void
    {
        if (!is_resource($output)) {
            throw new \InvalidArgumentException('Das CSV-Ausgabeziel muss ein Stream sein.');
        }

        fwrite($output, "\xEF\xBB\xBF");
        fputcsv(
            $output,
            array_map(
                fn (string $message): string => $this->translator->trans($message, [], 'messages', $locale),
                ['Kanalname', 'Anzeigename', 'E-Mail', 'Kontostatus', 'Profilsichtbarkeit', 'Registriert'],
            ),
            ';',
            '"',
            '',
            "\r\n",
        );

        foreach ($members as $member) {
            fputcsv(
                $output,
                [
                    self::safeCell($member->getHandle()),
                    self::safeCell($member->getDisplayName()),
                    self::safeCell($member->getEmail()),
                    $this->translator->trans(
                        $member->isBanned() ? 'Gebannt' : ($member->isActive() ? 'Aktiv' : 'Gesperrt'),
                        [],
                        'messages',
                        $locale,
                    ),
                    $this->translator->trans($member->isPublicProfile() ? 'Öffentlich' : 'Privat', [], 'messages', $locale),
                    $member->getCreatedAt()->format(DATE_ATOM),
                ],
                ';',
                '"',
                '',
                "\r\n",
            );
        }
    }

    /**
     * @param iterable<MemberAccount> $members
     * @param resource                $output
     */
    public function writeOutlookContacts(iterable $members, mixed $output): void
    {
        if (!is_resource($output)) {
            throw new \InvalidArgumentException('Das CSV-Ausgabeziel muss ein Stream sein.');
        }

        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, ['Name', 'E-mail Address', 'E-mail Address Type'], ',', '"', '', "\r\n");

        foreach ($members as $member) {
            fputcsv(
                $output,
                [self::safeCell($member->getDisplayName()), self::safeCell($member->getEmail()), 'SMTP'],
                ',',
                '"',
                '',
                "\r\n",
            );
        }
    }

    private static function safeCell(string $value): string
    {
        if (1 === preg_match('/\\A[\\x00-\\x20\\p{Z}\\x{FEFF}]*[=+\\-@]/u', $value)) {
            return "'".$value;
        }

        return $value;
    }
}
