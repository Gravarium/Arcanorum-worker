<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MemberAccount;
use App\Entity\MemberExternalIdentity;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MemberExternalIdentity>
 */
final class MemberExternalIdentityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MemberExternalIdentity::class);
    }

    public function findByProviderSubject(string $provider, string $subject): ?MemberExternalIdentity
    {
        $identity = $this->findOneBy(['provider' => $provider, 'subject' => $subject]);

        return $identity instanceof MemberExternalIdentity ? $identity : null;
    }

    public function findForMember(MemberAccount $member, string $provider): ?MemberExternalIdentity
    {
        $identity = $this->findOneBy(['member' => $member, 'provider' => $provider]);

        return $identity instanceof MemberExternalIdentity ? $identity : null;
    }
}
