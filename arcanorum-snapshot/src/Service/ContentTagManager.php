<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ContentTag;
use App\Repository\ContentTagRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ContentTagManager
{
    private const int MAX_INPUT_LENGTH = 1000;
    private const int MAX_TAGS = 10;
    private const int MAX_NAME_LENGTH = 100;

    public function __construct(
        private ContentTagRepository $tags,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return list<string>
     */
    public static function parseNames(string $input): array
    {
        if (mb_strlen($input) > self::MAX_INPUT_LENGTH) {
            throw new \InvalidArgumentException('Die Schlagwortliste darf höchstens 1.000 Zeichen lang sein.');
        }

        $parts = preg_split('/[,;\r\n]+/u', $input);
        if (false === $parts) {
            throw new \InvalidArgumentException('Die Schlagwortliste muss gültiges UTF-8 enthalten.');
        }
        $names = [];
        foreach ($parts as $part) {
            $name = trim($part);
            if ('' === $name) {
                continue;
            }
            if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
                throw new \InvalidArgumentException('Ein Schlagwort darf höchstens 100 Zeichen lang sein.');
            }

            $normalizedName = mb_strtolower($name);
            if (isset($names[$normalizedName])) {
                continue;
            }
            if (count($names) >= self::MAX_TAGS) {
                throw new \InvalidArgumentException('Pro Inhalt sind höchstens 10 Schlagwörter erlaubt.');
            }

            $names[$normalizedName] = $name;
        }

        return array_values($names);
    }

    /**
     * @param list<string> $names
     *
     * @return list<ContentTag>
     */
    public function resolve(array $names): array
    {
        $resolved = [];
        foreach ($names as $name) {
            $tag = $this->tags->findByNormalizedName($name);
            if (null === $tag) {
                $tag = new ContentTag($name);
                $this->entityManager->persist($tag);
            }

            $resolved[] = $tag;
        }

        return $resolved;
    }
}
