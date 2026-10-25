<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\CommentFilterTermRepository;

final readonly class CommentWordFilter
{
    public function __construct(
        private CommentFilterTermRepository $terms,
    ) {
    }

    public function findMatchingTerm(string $comment): ?string
    {
        $normalizedComment = CommentWordFilterNormalizer::normalizeText($comment);
        if ('' === $normalizedComment) {
            return null;
        }

        foreach ($this->terms->findForMatching() as $term) {
            if (CommentWordFilterMatcher::matchesNormalizedText(
                $normalizedComment,
                $term->getNormalizedTerm(),
            )) {
                return $term->getTerm();
            }
        }

        return null;
    }
}
