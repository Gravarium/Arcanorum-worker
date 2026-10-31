<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\ContentCategory;
use App\Entity\ContentCategoryType;
use App\Entity\ContentInlineImage;
use App\Repository\ContentInlineImageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ContentInlineImageRepositoryTest extends KernelTestCase
{
    public function testCategoryUsageAndOwnershipAreIsolatedFromOtherCategories(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $repository = static::getContainer()->get(ContentInlineImageRepository::class);
        self::assertInstanceOf(ContentInlineImageRepository::class, $repository);

        $suffix = bin2hex(random_bytes(6));
        $owner = new ContentCategory('Owner '.$suffix, ContentCategoryType::News);
        $other = new ContentCategory('Other '.$suffix, ContentCategoryType::Video);
        $first = new ContentInlineImage(str_repeat('1', 64), null, null, 'image/png', 128, str_repeat('a', 64), $owner);
        $second = new ContentInlineImage(str_repeat('2', 64), null, null, 'image/webp', 256, str_repeat('b', 64), $owner);
        $foreign = new ContentInlineImage(str_repeat('3', 64), null, null, 'image/jpeg', 512, str_repeat('c', 64), $other);

        try {
            foreach ([$owner, $other, $first, $second, $foreign] as $entity) {
                $entityManager->persist($entity);
            }
            $entityManager->flush();

            self::assertTrue($repository->allBelongToCategory($owner->getId(), [$first->getId(), $second->getId()]));
            self::assertFalse($repository->allBelongToCategory($owner->getId(), [$first->getId(), $foreign->getId()]));
            self::assertFalse($repository->allBelongToCategory($owner->getId(), [str_repeat('f', 64)]));
            self::assertSame(['count' => 2, 'bytes' => 384], $repository->usageForCategory($owner->getId()));
            self::assertSame([$first->getId(), $second->getId()], array_map(
                static fn (ContentInlineImage $image): string => $image->getId(),
                $repository->findForCategory($owner->getId()),
            ));
        } finally {
            $entityManager->getConnection()->executeStatement('DELETE FROM content_categories WHERE id IN (:owner, :other)', [
                'owner' => $owner->getId(),
                'other' => $other->getId(),
            ], [
                'owner' => \Doctrine\DBAL\ParameterType::STRING,
                'other' => \Doctrine\DBAL\ParameterType::STRING,
            ]);
        }
    }
}
