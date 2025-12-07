<?php

namespace App\Core\Repository;

use App\Core\Entity\Role;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class RoleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Role::class);
    }

    public function save(Role $role, bool $flush = true): void
    {
        $this->getEntityManager()->persist($role);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Role $role, bool $flush = true): void
    {
        $this->getEntityManager()->remove($role);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findByName(string $name): ?Role
    {
        return $this->findOneBy(['name' => $name]);
    }

    public function findSystemRoles(): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.isSystem = :isSystem')
            ->setParameter('isSystem', true)
            ->orderBy('r.displayName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findNonSystemRoles(): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.isSystem = :isSystem')
            ->setParameter('isSystem', false)
            ->orderBy('r.displayName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('r')
            ->orderBy('r.isSystem', 'DESC')
            ->addOrderBy('r.displayName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countRolesWithPermission(int $permissionId): int
    {
        return $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->join('r.permissions', 'p')
            ->where('p.id = :permissionId')
            ->setParameter('permissionId', $permissionId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findRolesWithUser(int $userId): array
    {
        return $this->createQueryBuilder('r')
            ->join('r.users', 'u')
            ->where('u.id = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('r.displayName', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
