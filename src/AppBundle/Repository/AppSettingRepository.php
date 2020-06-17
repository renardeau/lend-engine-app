<?php

namespace AppBundle\Repository;
use AppBundle\Entity\AppSetting;

/**
 * AppSettingRepository
 *
 * This class was generated by the Doctrine ORM. Add your own custom
 * repository methods below.
 */
class AppSettingRepository extends \Doctrine\ORM\EntityRepository
{
    public function save(AppSetting $setting)
    {
        $this->getEntityManager()->persist($setting);
        $this->getEntityManager()->flush($setting);
        return $setting;
    }
}