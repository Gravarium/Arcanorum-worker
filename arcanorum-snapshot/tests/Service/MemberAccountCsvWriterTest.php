<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\MemberAccount;
use App\Service\MemberAccountCsvWriter;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class MemberAccountCsvWriterTest extends KernelTestCase
{
    public function testCsvEscapesFormulaCellsAndWritesUtf8SemicolonRows(): void
    {
        self::bootKernel();
        $translator = static::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        $member = new MemberAccount('csv-member@example.test', 'csvmember', '=1+1');
        $member->setActive(false);
        $output = fopen('php://temp', 'w+b');

        if (false === $output) {
            self::fail('Could not open a CSV test stream.');
        }
        $writer = new MemberAccountCsvWriter($translator);
        $writer->write([$member], $output, 'de');
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        self::assertIsString($csv);
        self::assertStringStartsWith(
            "\xEF\xBB\xBFKanalname;Anzeigename;E-Mail;Kontostatus;Profilsichtbarkeit;Registriert\r\n",
            $csv,
        );
        self::assertStringContainsString("csvmember;'=1+1;csv-member@example.test;Gesperrt;Privat;", $csv);
        self::assertStringNotContainsString('csvmember;=1+1;', $csv);
    }

    public function testCsvUsesEnglishHeadingsAndAccountStatuses(): void
    {
        self::bootKernel();
        $translator = static::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        $member = new MemberAccount('csv-member@example.test', 'csvmember', '=1+1');
        $member->setActive(false);
        $output = fopen('php://temp', 'w+b');

        if (false === $output) {
            self::fail('Could not open an English CSV test stream.');
        }

        $writer = new MemberAccountCsvWriter($translator);
        $writer->write([$member], $output, 'en');
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        self::assertIsString($csv);
        self::assertStringStartsWith(
            "\xEF\xBB\xBF\"Channel name\";\"Display name\";Email;\"Account status\";\"Profile visibility\";Registered\r\n",
            $csv,
        );
        self::assertStringContainsString("csvmember;'=1+1;csv-member@example.test;Blocked;Private;", $csv);
    }

    public function testOutlookContactCsvUsesOnlyNamesAndEmailAndEscapesFormulaCells(): void
    {
        self::bootKernel();
        $member = new MemberAccount('contact-list@example.test', 'contactlist', '=1+1');
        $output = fopen('php://temp', 'w+b');

        if (false === $output) {
            self::fail('Could not open a contact-list CSV test stream.');
        }

        $translator = static::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);
        $writer = new MemberAccountCsvWriter($translator);
        $writer->writeOutlookContacts([$member], $output);
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        self::assertIsString($csv);
        self::assertStringStartsWith("\xEF\xBB\xBFName,\"E-mail Address\",\"E-mail Address Type\"\r\n", $csv);
        $rows = explode("\r\n", substr($csv, 3));
        self::assertSame(['Name', 'E-mail Address', 'E-mail Address Type'], str_getcsv($rows[0], ',', '"', ''));
        self::assertSame(["'=1+1", 'contact-list@example.test', 'SMTP'], str_getcsv($rows[1], ',', '"', ''));
    }
}
