<?php
namespace DataDog\AuditBundle\Model;

use Doctrine\ORM\EntityManagerInterface;

interface LabelerInterface
{
    /**
     * @param EntityManagerInterface $em
     */
    public function setEntityManager(EntityManagerInterface $em);

    /**
     * @param  $entity
     * @return string
     */
    public function label($entity);
}