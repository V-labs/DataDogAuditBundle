<?php
namespace DataDog\AuditBundle\Model;

use Doctrine\ORM\EntityManagerInterface;

trait LabelerTrait
{
    /**
     * @var EntityManagerInterface
     */
    protected $em;

    /**
     * @param EntityManagerInterface $em
     */
    public function setEntityManager(EntityManagerInterface $em){
        $this->em = $em;
    }
}