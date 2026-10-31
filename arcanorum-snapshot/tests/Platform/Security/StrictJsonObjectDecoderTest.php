<?php

declare(strict_types=1);

namespace App\Tests\Platform\Security;

use App\Platform\Security\StrictJsonObjectDecoder;
use PHPUnit\Framework\TestCase;

final class StrictJsonObjectDecoderTest extends TestCase
{
    public function testDecodesNestedObjectWithUniqueKeys(): void
    {
        $decoded = StrictJsonObjectDecoder::decode('{"schema":"ok","nested":{"value":1},"items":[{"name":"one"}]}');

        self::assertSame([
            'schema' => 'ok',
            'nested' => ['value' => 1],
            'items' => [['name' => 'one']],
        ], $decoded);
    }

    public function testRejectsDuplicateKeysAtEveryObjectDepth(): void
    {
        $duplicates = [
            '{"key":"first","key":"second"}',
            '{"nested":{"key":"first","key":"second"}}',
            '{"nested":{"value":1},"nested":{"value":2}}',
            '{"key":1,"\\u006bey":2}',
        ];

        foreach ($duplicates as $json) {
            self::assertNull(StrictJsonObjectDecoder::decode($json));
        }
    }

    public function testRejectsNonObjectsMalformedJsonAndExcessiveDepth(): void
    {
        self::assertNull(StrictJsonObjectDecoder::decode('[]'));
        self::assertNull(StrictJsonObjectDecoder::decode('{}'));
        self::assertNull(StrictJsonObjectDecoder::decode('{"unterminated":'));
        self::assertNull(StrictJsonObjectDecoder::decode('{"value":{"nested":true}}', 1));
    }

    public function testStringContentsAreNotTreatedAsObjectKeys(): void
    {
        self::assertSame(
            ['text' => '{"key":"first","key":"second"}'],
            StrictJsonObjectDecoder::decode('{"text":"{\\"key\\":\\"first\\",\\"key\\":\\"second\\"}"}'),
        );
    }
}
