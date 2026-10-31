<?php

declare(strict_types=1);

namespace App\Tests\Platform\Hosting;

use App\Platform\Hosting\CapabilityStatus;
use App\Platform\Hosting\HostingCapability;
use App\Platform\Hosting\HostingProfileDetector;
use App\Platform\Hosting\PrivateStorageProbe;
use PHPUnit\Framework\TestCase;

final class PrivateStorageProbeTest extends TestCase
{
    public function testPrivateWritableStorageOutsidePublicRootIsVerified(): void
    {
        $root = $this->createProjectRoot();

        try {
            $report = new HostingProfileDetector([
                new PrivateStorageProbe(
                    $root.DIRECTORY_SEPARATOR.'public',
                    $root.DIRECTORY_SEPARATOR.'var'.DIRECTORY_SEPARATOR.'private',
                ),
            ])->detect();

            self::assertSame(CapabilityStatus::Verified, $report->statusFor(HostingCapability::PrivateStorage));
        } finally {
            $this->removeTree($root);
        }
    }

    public function testStorageInsidePublicRootFailsClosed(): void
    {
        $root = $this->createProjectRoot();
        $storagePath = $root.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'uploads';

        try {
            $report = new HostingProfileDetector([
                new PrivateStorageProbe(
                    $root.DIRECTORY_SEPARATOR.'public',
                    $storagePath,
                ),
            ])->detect();

            self::assertSame(CapabilityStatus::Failed, $report->statusFor(HostingCapability::PrivateStorage));
            self::assertFalse(is_dir($storagePath));
        } finally {
            $this->removeTree($root);
        }
    }

    public function testNestedStoragePathInsidePublicRootIsRejectedBeforeCreatingDirectories(): void
    {
        $root = $this->createProjectRoot();
        $storagePath = $root.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'uploads'.DIRECTORY_SEPARATOR.'deep'.DIRECTORY_SEPARATOR.'private';

        try {
            $report = new HostingProfileDetector([
                new PrivateStorageProbe(
                    $root.DIRECTORY_SEPARATOR.'public',
                    $storagePath,
                ),
            ])->detect();

            self::assertSame(CapabilityStatus::Failed, $report->statusFor(HostingCapability::PrivateStorage));
            self::assertFalse(is_dir($root.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'uploads'));
        } finally {
            $this->removeTree($root);
        }
    }

    public function testStorageSymlinkIntoPublicRootFailsClosed(): void
    {
        $root = $this->createProjectRoot();
        $storagePath = $root.DIRECTORY_SEPARATOR.'var'.DIRECTORY_SEPARATOR.'private';
        if (!symlink($root.DIRECTORY_SEPARATOR.'public', $storagePath)) {
            $this->removeTree($root);
            self::markTestSkipped('Symlinks are not available in this test environment.');
        }

        try {
            $report = new HostingProfileDetector([
                new PrivateStorageProbe(
                    $root.DIRECTORY_SEPARATOR.'public',
                    $storagePath,
                ),
            ])->detect();

            self::assertSame(CapabilityStatus::Failed, $report->statusFor(HostingCapability::PrivateStorage));
        } finally {
            $this->removeTree($root);
        }
    }

    public function testMissingNestedStoragePathThroughPublicSymlinkIsRejectedBeforeCreatingDirectories(): void
    {
        $root = $this->createProjectRoot();
        $publicDirectory = $root.DIRECTORY_SEPARATOR.'public';
        $publicLink = $root.DIRECTORY_SEPARATOR.'var'.DIRECTORY_SEPARATOR.'public-link';
        if (!symlink($publicDirectory, $publicLink)) {
            $this->removeTree($root);
            self::markTestSkipped('Symlinks are not available in this test environment.');
        }

        try {
            $report = new HostingProfileDetector([
                new PrivateStorageProbe(
                    $publicDirectory,
                    $publicLink.DIRECTORY_SEPARATOR.'uploads'.DIRECTORY_SEPARATOR.'deep'.DIRECTORY_SEPARATOR.'private',
                ),
            ])->detect();

            self::assertSame(CapabilityStatus::Failed, $report->statusFor(HostingCapability::PrivateStorage));
            self::assertFalse(is_dir($publicDirectory.DIRECTORY_SEPARATOR.'uploads'));
        } finally {
            $this->removeTree($root);
        }
    }

    public function testParentTraversalThroughPublicSymlinkIsRejectedBeforeCreatingDirectories(): void
    {
        $root = $this->createProjectRoot();
        $publicDirectory = $root.DIRECTORY_SEPARATOR.'public';
        $publicChild = $publicDirectory.DIRECTORY_SEPARATOR.'child';
        $this->createDirectory($publicChild, 0700);
        $publicLink = $root.DIRECTORY_SEPARATOR.'var'.DIRECTORY_SEPARATOR.'public-child-link';
        if (!symlink($publicChild, $publicLink)) {
            $this->removeTree($root);
            self::markTestSkipped('Symlinks are not available in this test environment.');
        }

        try {
            $report = new HostingProfileDetector([
                new PrivateStorageProbe(
                    $publicDirectory,
                    $publicLink.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'uploads'.DIRECTORY_SEPARATOR.'deep'.DIRECTORY_SEPARATOR.'private',
                ),
            ])->detect();

            self::assertSame(CapabilityStatus::Failed, $report->statusFor(HostingCapability::PrivateStorage));
            self::assertFalse(is_dir($publicDirectory.DIRECTORY_SEPARATOR.'uploads'));
        } finally {
            $this->removeTree($root);
        }
    }

    private function createProjectRoot(): string
    {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'arcanorum-private-'.bin2hex(random_bytes(8));
        if (!mkdir($root, 0700)) {
            throw new \RuntimeException('Temporäres Projektverzeichnis konnte nicht angelegt werden.');
        }

        $this->createDirectory($root.DIRECTORY_SEPARATOR.'public', 0755);
        $this->createDirectory($root.DIRECTORY_SEPARATOR.'var', 0700);

        return $root;
    }

    private function createDirectory(string $path, int $permissions): void
    {
        if (!mkdir($path, $permissions, true) && !is_dir($path)) {
            throw new \RuntimeException('Testverzeichnis konnte nicht angelegt werden.');
        }
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        $entries = scandir($path);
        if (false === $entries) {
            return;
        }

        foreach ($entries as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $this->removeTree($path.DIRECTORY_SEPARATOR.$entry);
        }

        @rmdir($path);
    }
}
