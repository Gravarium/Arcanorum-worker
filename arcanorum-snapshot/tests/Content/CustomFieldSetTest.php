<?php

declare(strict_types=1);

namespace App\Tests\Content;

use App\Content\CustomFieldSet;
use PHPUnit\Framework\TestCase;

final class CustomFieldSetTest extends TestCase
{
    public function testNormalizationKeepsTextAndIgnoresCompletelyEmptyRows(): void
    {
        self::assertSame([
            ['key' => 'Studio', 'value' => 'A <script> stays text & escaped'],
            ['key' => 'Release', 'value' => '2026'],
        ], CustomFieldSet::normalize([
            ['key' => ' Studio ', 'value' => 'A <script> stays text & escaped'],
            ['key' => '', 'value' => " \t "],
            ['key' => 'Release', 'value' => '2026'],
        ]));
    }

    public function testNormalizationRejectsMalformedShapeAndUnknownKeys(): void
    {
        foreach ([
            null,
            'not-an-array',
            ['key' => 'Studio', 'value' => 'A'],
            [['key' => 'Studio', 'value' => 'A', 'html' => '<b>unsafe</b>']],
            [['key' => ['nested'], 'value' => 'A']],
            [['key' => 'Studio']],
            [['key' => 'Studio', 'value' => "bad\x00text"]],
        ] as $invalid) {
            self::assertNull(CustomFieldSet::normalize($invalid));
        }
    }

    public function testNormalizationRejectsReservedDuplicateAndOversizedFields(): void
    {
        self::assertNull(CustomFieldSet::normalize([['key' => '_internal', 'value' => 'reserved']]));
        self::assertNull(CustomFieldSet::normalize([
            ['key' => 'Studio', 'value' => 'one'],
            ['key' => ' studio ', 'value' => 'two'],
        ]));
        self::assertNull(CustomFieldSet::normalize([['key' => str_repeat('k', 256), 'value' => 'too long']]));
        self::assertNull(CustomFieldSet::normalize([['key' => 'Value', 'value' => str_repeat('v', 4097)]]));
        self::assertNull(CustomFieldSet::normalize(array_fill(0, 31, ['key' => 'K', 'value' => 'V'])));
        $oversizedTotal = [];
        for ($index = 0; $index < 8; ++$index) {
            $oversizedTotal[] = ['key' => 'Field'.$index, 'value' => str_repeat('v', 4096)];
        }
        self::assertNull(CustomFieldSet::normalize($oversizedTotal));
    }
}
