<?php

namespace App\Repository;

use App\Base\BaseRepository;
use App\Entity\Structure;
use App\Entity\User;
use App\Entity\Volunteer;
use Bundles\PegassCrawlerBundle\Entity\Pegass;
use Doctrine\Bundle\DoctrineBundle\Registry;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\QueryBuilder;

/**
 * @method Volunteer|null find($id, $lockMode = null, $lockVersion = null)
 * @method Volunteer|null findOneBy(array $criteria, array $orderBy = null)
 * @method Volunteer[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class VolunteerRepository extends BaseRepository
{
    public function __construct(Registry $registry)
    {
        parent::__construct($registry, Volunteer::class);
    }

    public function disable(Volunteer $volunteer)
    {
        if ($volunteer->isEnabled()) {
            $volunteer->setEnabled(false);
            $this->save($volunteer);
        }
    }

    public function enable(Volunteer $volunteer)
    {
        if (!$volunteer->isEnabled()) {
            $volunteer->setEnabled(true);
            $this->save($volunteer);
        }
    }

    public function lock(Volunteer $volunteer)
    {
        if (!$volunteer->isLocked()) {
            $volunteer->setLocked(true);
            $this->save($volunteer);
        }
    }

    public function unlock(Volunteer $volunteer)
    {
        if ($volunteer->isLocked()) {
            $volunteer->setLocked(false);
            $this->save($volunteer);
        }
    }

    public function findOneByNivol(string $nivol) : ?Volunteer
    {
        return $this->findOneBy([
            'nivol' => ltrim($nivol, '0'),
        ]);
    }

    /**
     * @param string $keyword
     * @param int    $maxResults
     * @param User   $user
     *
     * @return Volunteer[]
     */
    public function searchForUser(User $user, ?string $keyword, int $maxResults, bool $onlyEnabled = false) : array
    {
        return $this->searchForUserQueryBuilder($user, $keyword, $onlyEnabled)
                    ->getQuery()
                    ->setMaxResults($maxResults)
                    ->getResult();
    }

    public function searchForUserQueryBuilder(User $user,
        ?string $keyword,
        bool $onlyEnabled = false,
        bool $onlyUsers = true) : QueryBuilder
    {
        $qb = $this->createAccessibleVolunteersQueryBuilder($user, $onlyEnabled);

        if ($keyword) {
            $this->addSearchCriteria($qb, $keyword);
        }

        if ($onlyUsers) {
            $this->addUserCriteria($qb);
        }

        return $qb;
    }

    /**
     * @param string|null $keyword
     * @param int         $maxResults
     *
     * @return Volunteer[]
     */
    public function searchAll(?string $keyword, int $maxResults) : array
    {
        return $this->searchAllQueryBuilder($keyword)
                    ->getQuery()
                    ->setMaxResults($maxResults)
                    ->getResult();
    }

    public function searchInStructureQueryBuilder(Structure $structure,
        ?string $keyword,
        bool $onlyEnabled = true,
        bool $onlyUsers = false) : QueryBuilder
    {
        $qb = $this->searchAllQueryBuilder($keyword, $onlyEnabled)
                   ->join('v.structures', 's')
                   ->andWhere('s.id = :structure')
                   ->setParameter('structure', $structure);

        if ($onlyUsers) {
            $this->addUserCriteria($qb);
        }

        return $qb;
    }

    public function findInStructureQueryBuilder(Structure $structure, bool $enabled = false) : QueryBuilder
    {
        return $this->createVolunteersQueryBuilder($enabled)
                    ->join('v.structures', 's')
                    ->andWhere('s.id = :structure')
                    ->setParameter('structure', $structure);
    }

    public function searchAllQueryBuilder(?string $keyword, bool $enabled = false) : QueryBuilder
    {
        $qb = $this->createVolunteersQueryBuilder($enabled);

        if ($keyword) {
            $this->addSearchCriteria($qb, $keyword);
        }

        return $qb;
    }

    public function searchAllWithFiltersQueryBuilder(?string $criteria,
        bool $onlyEnabled,
        bool $onlyUsers) : QueryBuilder
    {
        $qb = $this->searchAllQueryBuilder($criteria, $onlyEnabled);

        if ($onlyUsers) {
            $this->addUserCriteria($qb);
        }

        return $qb;
    }

    public function foreach(callable $callback, bool $onlyEnabled = true)
    {
        $count = $this->createQueryBuilder('v')
                      ->select('COUNT(v.id)')
                      ->getQuery()
                      ->getSingleScalarResult();

        $offset = 0;
        $stop   = false;
        while ($offset < $count) {
            $qb = $this->createQueryBuilder('v');

            if ($onlyEnabled) {
                $qb->where('v.enabled = true');
            }

            $qb->setFirstResult($offset)
               ->setMaxResults(1000);

            $iterator = $qb->getQuery()->iterate();

            while (($row = $iterator->next()) !== false) {
                /* @var Volunteer $entity */
                $entity = reset($row);

                if (false === $return = $callback($entity)) {
                    $stop = true;
                    break;
                }

                if (true === $return) {
                    continue;
                }

                $this->_em->persist($entity);
                unset($entity);
            }

            $this->_em->flush();
            $this->_em->clear();

            if ($stop) {
                break;
            }

            $offset += 1000;
        }
    }

    public function getIssues(User $user) : array
    {
        $qb = $this->createAccessibleVolunteersQueryBuilder($user);

        return $qb
            ->leftJoin('v.phones', 'p')
            ->andWhere(
                $qb->expr()->orX(
                    'v.email IS NULL or v.email = \'\'',
                    'p.id is null'
                )
            )
            ->getQuery()
            ->getResult();
    }

    public function synchronizeWithPegass()
    {
        $qb = $this->createQueryBuilder('v');

        $sub = $this->_em->createQueryBuilder()
                         ->select('p.identifier')
                         ->from(Pegass::class, 'p')
                         ->where('p.type = :type')
                         ->andWhere('p.enabled = :enabled');

        $qb
            ->setParameter('type', Pegass::TYPE_VOLUNTEER)
            ->setParameter('enabled', false);

        $qb
            ->update()
            ->set('v.enabled', ':enabled')
            ->where($qb->expr()->in('v.identifier', $sub->getDQL()))
            ->getQuery()
            ->execute();
    }

    /**
     * @param array $nivols
     *
     * @return Volunteer[]
     */
    public function filterByNivols(array $nivols) : array
    {
        return $this->createVolunteersQueryBuilder()
                    ->andWhere('v.nivol IN (:nivols)')
                    ->setParameter('nivols', $nivols, Connection::PARAM_STR_ARRAY)
                    ->getQuery()
                    ->getResult();
    }

    /**
     * @param array $nivols
     * @param User  $user
     *
     * @return Volunteer[]
     */
    public function filterByNivolsAndAccess(array $nivols, User $user) : array
    {
        return $this->createAccessibleVolunteersQueryBuilder($user)
                    ->andWhere('v.nivol IN (:nivols)')
                    ->setParameter('nivols', $nivols, Connection::PARAM_STR_ARRAY)
                    ->getQuery()
                    ->getResult();
    }

    /**
     * @param array $ids
     *
     * @return Volunteer[]
     */
    public function filterByIds(array $ids) : array
    {
        return $this->createVolunteersQueryBuilder()
                    ->andWhere('v.id IN (:ids)')
                    ->setParameter('ids', $ids, Connection::PARAM_INT_ARRAY)
                    ->getQuery()
                    ->getResult();
    }

    /**
     * @param array $ids
     * @param User  $user
     *
     * @return Volunteer[]
     */
    public function filterByIdsAndAccess(array $ids, User $user) : array
    {
        return $this->createAccessibleVolunteersQueryBuilder($user)
                    ->andWhere('v.id IN (:ids)')
                    ->setParameter('ids', $ids, Connection::PARAM_INT_ARRAY)
                    ->getQuery()
                    ->getResult();
    }

    public function createAcessibleNivolsFilterQueryBuilder(array $nivols, User $user) : QueryBuilder
    {
        return $this->createAccessibleVolunteersQueryBuilder($user)
                    ->select('v.nivol')
                    ->andWhere("TRIM(LEADING '0' FROM v.nivol) IN (:nivols)")
                    ->setParameter('nivols', $nivols, Connection::PARAM_STR_ARRAY);
    }

    public function filterReachableNivols(array $nivols, User $user) : array
    {
        $valid = $this->createAcessibleNivolsFilterQueryBuilder($nivols, $user)
                      ->leftJoin('v.phones', 'p')
                      ->andWhere('p.id IS NOT NULL')
                      ->andWhere('v.phoneNumberOptin = true')
                      ->andWhere('v.email IS NOT NULL')
                      ->andWhere('v.emailOptin = true')
                      ->getQuery()
                      ->getArrayResult();

        return array_column($valid, 'nivol');
    }

    public function filterInvalidNivols(array $nivols) : array
    {
        $valid = $this->createVolunteersQueryBuilder(false)
                      ->select('v.nivol')
                      ->andWhere('v.nivol IN (:nivols)')
                      ->setParameter('nivols', $nivols)
                      ->getQuery()
                      ->getArrayResult();

        return array_filter(array_diff($nivols, array_column($valid, 'nivol')));
    }

    public function filterDisabledNivols(array $nivols) : array
    {
        $disabled = $this->createVolunteersQueryBuilder(false)
                         ->select('v.nivol')
                         ->andWhere('v.nivol IN (:nivols)')
                         ->setParameter('nivols', $nivols)
                         ->andWhere('v.enabled = false')
                         ->getQuery()
                         ->getArrayResult();

        return array_column($disabled, 'nivol');
    }

    public function filterNoPhoneNivols(array $nivols, User $user) : array
    {
        $filtered = $this->createAcessibleNivolsFilterQueryBuilder($nivols, $user)
                         ->leftJoin('v.phones', 'p')
                         ->andWhere('p.id IS NULL')
                         ->getQuery()
                         ->getArrayResult();

        return array_column($filtered, 'nivol');
    }

    public function filterPhoneOptOutNivols(array $nivols, User $user) : array
    {
        $filtered = $this->createAcessibleNivolsFilterQueryBuilder($nivols, $user)
                         ->leftJoin('v.phones', 'p')
                         ->andWhere('p.id IS NOT NULL')
                         ->andWhere('v.phoneNumberOptin = false')
                         ->getQuery()
                         ->getArrayResult();

        return array_column($filtered, 'nivol');
    }

    public function filterNoEmailNivols(array $nivols, User $user) : array
    {
        $filtered = $this->createAcessibleNivolsFilterQueryBuilder($nivols, $user)
                         ->andWhere('v.email IS NULL')
                         ->getQuery()
                         ->getArrayResult();

        return array_column($filtered, 'nivol');
    }

    public function filterEmailOptOutNivols(array $nivols, User $user) : array
    {
        $filtered = $this->createAcessibleNivolsFilterQueryBuilder($nivols, $user)
                         ->andWhere('v.email IS NOT NULL')
                         ->andWhere('v.emailOptin = false')
                         ->getQuery()
                         ->getArrayResult();

        return array_column($filtered, 'nivol');
    }

    public function loadVolunteersAudience(Structure $structure, array $nivols) : array
    {
        return $this->findInStructureQueryBuilder($structure, true)
                    ->select('v.firstName, v.lastName, v.nivol')
                    ->andWhere('v.nivol IN (:nivols)')
                    ->setParameter('nivols', $nivols)
                    ->addOrderBy('v.firstName', 'ASC')
                    ->getQuery()
                    ->getArrayResult();
    }

    public function searchVolunteersAudience(Structure $structure, string $criteria) : array
    {
        return $this->searchInStructureQueryBuilder($structure, $criteria, true)
                    ->select('v.firstName, v.lastName, concat(v.firstName, \' \', v.lastName) as firstLast, concat(v.lastName, \' \', v.firstName) as lastFirst, v.nivol')
                    ->addOrderBy('v.firstName', 'ASC')
                    ->setMaxResults(25)
                    ->getQuery()
                    ->getArrayResult();
    }

    public function searchVolunteerAudienceByTags(array $tags, Structure $structure) : array
    {
        $rows = $this->createVolunteersQueryBuilder(true)
                     ->select('v.nivol')
                     ->join('v.structures', 's')
                     ->andWhere('s.id = :structure')
                     ->setParameter('structure', $structure)
                     ->join('v.tags', 't')
                     ->andWhere('t.id IN (:tags)')
                     ->setParameter('tags', $tags)
                     ->getQuery()
                     ->getArrayResult();

        return array_column($rows, 'nivol');
    }

    public function getNivolsAndStructures(array $structures, array $nivols) : array
    {
        return $this->createVolunteersQueryBuilder(true)
                    ->select('v.nivol, s.id as structure_id')
                    ->join('v.structures', 's')
                    ->andWhere('s.id IN (:structures)')
                    ->setParameter('structures', $structures)
                    ->andWhere('v.nivol IN (:nivols)')
                    ->setParameter('nivols', $nivols)
                    ->getQuery()
                    ->getArrayResult();
    }

    private function createVolunteersQueryBuilder(bool $enabled = true) : QueryBuilder
    {
        $qb = $this->createQueryBuilder('v')
                   ->distinct();

        if ($enabled) {
            $qb->andWhere('v.enabled = true');
        }

        return $qb;
    }

    private function createAccessibleVolunteersQueryBuilder(User $user, bool $enabled = true) : QueryBuilder
    {
        return $this->createVolunteersQueryBuilder($enabled)
                    ->join('v.structures', 's')
                    ->join('s.users', 'u')
                    ->andWhere('u.id = :user')
                    ->setParameter('user', $user);
    }

    private function addSearchCriteria(QueryBuilder $qb, string $criteria)
    {
        $qb
            ->leftJoin('v.phones', 'p')
            ->andWhere(
                $qb->expr()->orX(
                    'v.nivol LIKE :criteria',
                    'v.firstName LIKE :criteria',
                    'v.lastName LIKE :criteria',
                    'p.e164 LIKE :criteria',
                    'p.national LIKE :criteria',
                    'p.international LIKE :criteria',
                    'v.email LIKE :criteria',
                    'CONCAT(v.firstName, \' \', v.lastName) LIKE :criteria',
                    'CONCAT(v.lastName, \' \', v.firstName) LIKE :criteria'
                )
            )
            ->setParameter('criteria', sprintf('%%%s%%', $criteria));
    }

    private function addUserCriteria(QueryBuilder $qb)
    {
        $qb
            ->join('v.user', 'vu');
    }
}
