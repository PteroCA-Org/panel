<?php

namespace App\Core\Repository;

use App\Core\Entity\Voucher;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class VoucherRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Voucher::class);
    }

    public function save(Voucher $voucher): void
    {
        $this->getEntityManager()->persist($voucher);
        $this->getEntityManager()->flush();
    }

    public function getVoucherByCode(string $code): ?Voucher
    {
        return $this->createQueryBuilder('v')
            ->andWhere('v.code = :code')
            ->andWhere('v.deletedAt IS NULL')
            ->setParameter('code', $code)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
