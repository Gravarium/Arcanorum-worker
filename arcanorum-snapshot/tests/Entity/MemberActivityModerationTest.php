<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\AdminUser;
use App\Entity\CommentContentType;
use App\Entity\ContentComment;
use App\Entity\MemberAccount;
use App\Entity\MemberActivityModeration;
use App\Entity\MemberActivityModerationStatus;
use App\Entity\MemberActivityType;
use App\Entity\Playlist;
use App\Entity\PublishingStatus;
use App\Entity\Video;
use App\Entity\VideoSourceType;
use App\Entity\VideoSubmission;
use PHPUnit\Framework\TestCase;

final class MemberActivityModerationTest extends TestCase
{
    public function testActivitySourceTypeMustMatchItsFeedKindAndStatusCanBeRestored(): void
    {
        $admin = new AdminUser('activity-moderator@example.test');
        $member = new MemberAccount('activity-member@example.test', 'activitymember', 'Activity member');
        $video = new Video(
            'Moderated video',
            'Public video used to verify activity source integrity.',
            null,
            VideoSourceType::Url,
            'https://video.example.test/moderated.mp4',
            PublishingStatus::Published,
            false,
            true,
        );
        $submission = new VideoSubmission($video, $member);
        $moderation = new MemberActivityModeration(
            MemberActivityType::VideoSubmission,
            MemberActivityModerationStatus::Hidden,
            $admin,
            $submission,
        );

        self::assertSame(MemberActivityType::VideoSubmission, $moderation->getActivityType());
        self::assertSame(MemberActivityModerationStatus::Hidden, $moderation->getStatus());
        self::assertSame($submission, $moderation->getVideoSubmission());
        self::assertNull($moderation->getPlaylist());
        self::assertNull($moderation->getComment());

        $moderation->updateStatus(MemberActivityModerationStatus::Visible, $admin);

        self::assertSame(MemberActivityModerationStatus::Visible, $moderation->getStatus());
        self::assertSame($admin, $moderation->getModerator());
        self::assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/D', $moderation->getId());
    }

    public function testActivityCannotReferenceAnUnrelatedContentType(): void
    {
        $admin = new AdminUser('activity-moderator@example.test');
        $member = new MemberAccount('activity-member@example.test', 'activitymember', 'Activity member');
        $playlist = new Playlist($member, 'Public activity playlist', '', true);

        $this->expectException(\InvalidArgumentException::class);
        new MemberActivityModeration(
            MemberActivityType::VideoSubmission,
            MemberActivityModerationStatus::Removed,
            $admin,
            $playlist,
        );
    }

    public function testNewsActivityRequiresANewsComment(): void
    {
        $admin = new AdminUser('activity-moderator@example.test');
        $videoComment = new ContentComment(CommentContentType::Video, str_repeat('a', 32), 'Video', 'Member', 'Comment');

        $this->expectException(\InvalidArgumentException::class);
        new MemberActivityModeration(
            MemberActivityType::NewsComment,
            MemberActivityModerationStatus::Hidden,
            $admin,
            $videoComment,
        );
    }
}
