<?php

declare(strict_types=1);

namespace App\Entity;

enum VideoPopularitySort: string
{
    case TotalViews = 'views';
    case RecentViews = 'recent_views';
    case MemberRatings = 'ratings';
}
