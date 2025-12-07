<?php

namespace App\Core\Repository;

use App\Core\Entity\Permission;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class PermissionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Permission::class);
    }

    public function save(Permission $permission, bool $flush = true): void
    {
        $this->getEntityManager()->persist($permission);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Permission $permission, bool $flush = true): void
    {
        $this->getEntityManager()->remove($permission);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findByCode(string $code): ?Permission
    {
        return $this->findOneBy(['code' => $code]);
    }

    public function findSystemPermissions(): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.isSystem = :isSystem')
            ->setParameter('isSystem', true)
            ->orderBy('p.section', 'ASC')
            ->addOrderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findBySection(string $section): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.section = :section')
            ->setParameter('section', $section)
            ->orderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByPlugin(string $pluginName): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.pluginName = :pluginName')
            ->setParameter('pluginName', $pluginName)
            ->orderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all active permissions (core + enabled plugins only)
     */
    public function findActivePermissions(array $enabledPluginNames = []): array
    {
        $qb = $this->createQueryBuilder('p');

        // Core permissions (pluginName IS NULL) OR permissions from enabled plugins
        $qb->where(
            $qb->expr()->orX(
                $qb->expr()->isNull('p.pluginName'),
                $qb->expr()->in('p.pluginName', ':enabledPlugins')
            )
        )
        ->setParameter('enabledPlugins', $enabledPluginNames)
        ->orderBy('p.section', 'ASC')
        ->addOrderBy('p.name', 'ASC');

        return $qb->getQuery()->getResult();
    }

    /**
     * Get all sections with their permissions count
     */
    public function getSectionsWithCount(): array
    {
        $results = $this->createQueryBuilder('p')
            ->select('p.section, COUNT(p.id) as permissionCount')
            ->groupBy('p.section')
            ->orderBy('p.section', 'ASC')
            ->getQuery()
            ->getResult();

        $sections = [];
        foreach ($results as $result) {
            $sections[$result['section']] = (int) $result['permissionCount'];
        }

        return $sections;
    }

    /**
     * Find permissions by multiple codes
     */
    public function findByCodes(array $codes): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.code IN (:codes)')
            ->setParameter('codes', $codes)
            ->getQuery()
            ->getResult();
    }

    /**
     * Delete all permissions for a specific plugin
     */
    public function deleteByPlugin(string $pluginName): int
    {
        return $this->createQueryBuilder('p')
            ->delete()
            ->where('p.pluginName = :pluginName')
            ->setParameter('pluginName', $pluginName)
            ->getQuery()
            ->execute();
    }
}
