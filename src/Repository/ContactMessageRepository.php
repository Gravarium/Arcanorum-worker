<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ContactMessage;
use App\Entity\ContactMessageStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ContactMessage>
 */
final class ContactMessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContactMessage::class);
    }

    public function countForStatus(ContactMessageStatus $status): int
    {
        return (int) $this->createQueryBuilder('message')
            ->select('COUNT(message.id)')
            ->andWhere('message.status = :status')
            ->setParameter('status', $status)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return list<ContactMessage>
     */
    public function findForAdmin(?ContactMessageStatus $status, string $search): array
    {
        $queryBuilder = $this->createQueryBuilder('message');

        if (null !== $status) {
            $queryBuilder->andWhere('message.status = :status')->setParameter('status', $status);
        }

        if ('' !== $search) {
            $queryBuilder
                ->andWhere('(message.name LIKE :search OR message.email LIKE :search OR message.subject LIKE :search OR message.body LIKE :search)')
                ->setParameter('search', '%'.$search.'%');
        }

        /** @var list<ContactMessage> $messages */
        $messages = $queryBuilder
            ->orderBy('message.createdAt', \SortDirection::Descending)
            ->setMaxResults(100)
            ->getQuery()
            ->getResult();

        return $messages;
    }
}
