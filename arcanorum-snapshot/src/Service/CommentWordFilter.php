<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CommentFilterAction;
use App\Entity\CommentFilterTerm;
use App\Repository\CommentFilterTermRepository;

final readonly class CommentWordFilter
{
    public function __construct(
        private CommentFilterTermRepository $terms,
    ) {
    }

    public function apply(string $comment): CommentWordFilterResult
    {
        $normalizedComment = CommentWordFilterNormalizer::normalizeText($comment);
        if ('' === $normalizedComment) {
            return new CommentWordFilterResult($comment, false, null);
        }

        $matchedTerm = null;
        $hasRejectedTerm = false;
        $censoredTerms = [];
        foreach ($this->terms->findForMatching() as $term) {
            if (!CommentWordFilterMatcher::matchesNormalizedText(
                $normalizedComment,
                $term->getNormalizedTerm(),
            )) {
                continue;
            }

            $matchedTerm ??= $term->getTerm();
            if (CommentFilterAction::Reject === $term->getAction()) {
                $hasRejectedTerm = true;
            } elseif (CommentFilterAction::Censor === $term->getAction()) {
                $censoredTerms[] = $term;
            }
        }

        if ($hasRejectedTerm) {
            return new CommentWordFilterResult($comment, true, $matchedTerm);
        }

        usort($censoredTerms, static function (CommentFilterTerm $left, CommentFilterTerm $right): int {
            $lengthOrder = mb_strlen($right->getNormalizedTerm(), 'UTF-8') <=> mb_strlen($left->getNormalizedTerm(), 'UTF-8');

            return 0 !== $lengthOrder ? $lengthOrder : strcmp($left->getNormalizedTerm(), $right->getNormalizedTerm());
        });

        $filteredComment = $comment;
        foreach ($censoredTerms as $term) {
            $filteredComment = CommentWordFilterMatcher::replaceOccurrences(
                $filteredComment,
                $term->getNormalizedTerm(),
            );
        }

        return new CommentWordFilterResult($filteredComment, false, $matchedTerm);
    }
}
