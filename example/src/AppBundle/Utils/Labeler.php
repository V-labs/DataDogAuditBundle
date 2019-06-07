<?php
namespace AppBundle\Utils;

use DataDog\AuditBundle\Model\LabelerInterface;
use DataDog\AuditBundle\Model\LabelerTrait;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;

/**
 * Class Labeler
 * @package AppBundle\Utils
 */
class Labeler implements LabelerInterface
{
    use LabelerTrait;

    /**
     * {@inheritdoc}
     */
    public function label($entity){

        if(!$this->em instanceof EntityManagerInterface){
            throw new LogicException('EntityManager should be set before trying to label entity');
        }

        $meta = $this->em->getClassMetadata(get_class($entity));
        switch (true) {
            case $meta->hasField('title'):
                return $meta->getReflectionProperty('title')->getValue($entity);
            case $meta->hasField('name'):
                return $meta->getReflectionProperty('name')->getValue($entity);
            case $meta->hasField('label'):
                return $meta->getReflectionProperty('label')->getValue($entity);
            case $meta->getReflectionClass()->hasMethod('__toString'):
                return (string)$entity;
            default:
                return "Unlabeled";
        }
    }
}