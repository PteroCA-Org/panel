<?php

namespace App\Core\Repository;

use App\Core\Entity\SettingOption;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SettingOption>
 */
class SettingOptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SettingOption::class);
    }

    public function save(SettingOption $entity, bool $flush = true): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(SettingOption $entity, bool $flush = true): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * @return array<string, string>
     */
    public function getOptionsForSetting(string $settingName): array
    {
        $qb = $this->createQueryBuilder('so')
            ->select('so.optionKey', 'so.optionValue')
            ->where('so.settingName = :settingName')
            ->setParameter('settingName', $settingName)
            ->orderBy('so.sortOrder', 'ASC')
            ->addOrderBy('so.optionValue', 'ASC');

        $results = $qb->getQuery()->getArrayResult();

        $options = [];
        foreach ($results as $result) {
            $options[$result['optionKey']] = $result['optionValue'];
        }

        return $options;
    }

    /**
     * @return SettingOption[]
     */
    public function findBySettingName(string $settingName): array
    {
        return $this->findBy(['settingName' => $settingName], ['sortOrder' => 'ASC', 'optionValue' => 'ASC']);
    }
}
