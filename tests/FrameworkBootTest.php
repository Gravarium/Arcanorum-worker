<?php

declare(strict_types=1);

namespace App\Tests;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

final class FrameworkBootTest extends KernelTestCase
{
    public function testKernelBoots(): void
    {
        self::assertInstanceOf(KernelInterface::class, self::bootKernel());
    }
}
