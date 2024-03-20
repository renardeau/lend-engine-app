<?php

namespace AppBundle\Services\Membership;

use AppBundle\Entity\Contact;
use AppBundle\Entity\Membership;
use AppBundle\Entity\MembershipType;
use AppBundle\Entity\Note;
use AppBundle\Services\Contact\ContactService;
use Doctrine\DBAL\DBALException;
use Doctrine\ORM\EntityManager;

class MembershipService
{

    /** @var EntityManager */
    private $em;

    /** @var ContactService */
    private $contactService;

    /** @var array */
    public $errors = [];

    public function __construct(EntityManager $em, ContactService $contactService)
    {
        $this->em        = $em;
        $this->contactService = $contactService;
    }

    /**
     * @return array
     * @throws DBALException
     */
    public function membershipsAddedByMonth()
    {
        $sql = "SELECT DATE(m.created_at) AS d,
                  count(*) AS c
                  FROM membership m
                  GROUP BY DATE(m.created_at)";

        $stmt = $this->em->getConnection()->prepare($sql);
        $stmt->execute();
        $results = $stmt->fetchAll();

        // key by "Y-m"
        $data = [];
        foreach ($results AS $result) {
            $key = substr($result['d'], 0, 7);
            if (!isset($data[$key])) {
                $data[$key] = 0;
            }
            $data[$key] += $result['c'];
        }
        return $data;
    }

    /**
     * Count memberships that where ACTIVE on given date (might be EXPIRED now)
     * Eliminate duplicates for contact as multiple expired memberships might span same period
     * but only one was ACTIVE at any given time
     * @return int count of active memberships at given date
     * @throws DBALException
     */
    public function countActiveMemberships(\DateTime $date = null)
    {
        $repository = $this->em->getRepository('AppBundle:Membership');
        $builder = $repository->createQueryBuilder('m');
        $builder->add('select', 'COUNT( DISTINCT(m.contact)) AS qty');
        $builder->where("m.status IN ('ACTIVE', 'EXPIRED')");
        if ($date) {
            $builder->andWhere("m.startsAt < :date");
            $builder->andWhere("m.expiresAt >= :date");
            $builder->setParameter('date', $date->format("Y-m-d"));
        }
        $query = $builder->getQuery();
        if ( $results = $query->getResult() ) {
            $total = $results[0]['qty'];
        } else {
            $total = 0;
        }
        return $total;
    }

}