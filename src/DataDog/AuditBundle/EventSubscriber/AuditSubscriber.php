<?php

namespace DataDog\AuditBundle\EventSubscriber;

use DataDog\AuditBundle\DBAL\AuditLogger;
use DataDog\AuditBundle\Entity\AuditLog;
use DataDog\AuditBundle\Entity\Association;

use DataDog\AuditBundle\Model\BlamerInterface;
use DataDog\AuditBundle\Model\FlusherInterface;
use DataDog\AuditBundle\Model\LabelerInterface;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\PersistentCollection;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Logging\LoggerChain;
use Doctrine\DBAL\Logging\SQLLogger;
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Event\OnFlushEventArgs;

/**
 * Class AuditSubscriber
 * @package DataDog\AuditBundle\EventSubscriber
 */
class AuditSubscriber implements EventSubscriber, FlusherInterface
{
    /**
     * @var EntityManagerInterface
     */
    protected $em;

    /**
     * @var TokenStorageInterface
     */
    protected $securityTokenStorage;

    /**
     * @var LabelerInterface|null
     */
    protected $labeler;

    /**
     * @var BlamerInterface|null
     */
    protected $blamer;

    /**
     * @var SQLLogger
     */
    protected $old;

    /**
     * @var array
     */
    protected $auditedEntities = [];

    /**
     * @var array
     */
    protected $unauditedEntities = [];

    protected $inserted = []; // [$source, $changeset]
    protected $updated = []; // [$source, $changeset]
    protected $removed = []; // [$source, $id]
    protected $associated = [];   // [$source, $target, $mapping]
    protected $dissociated = []; // [$source, $target, $id, $mapping]

    /**
     * @var Statement
     */
    protected $assocInsertStmt;

    /**
     * @var Statement
     */
    protected $auditInsertStmt;

    /**
     * AuditSubscriber constructor.
     * @param TokenStorageInterface $securityTokenStorage
     */
    public function __construct(TokenStorageInterface $securityTokenStorage)
    {
        $this->securityTokenStorage = $securityTokenStorage;
    }

    /**
     * @param LabelerInterface|null $labeler
     * @return $this
     */
    public function setLabeler(LabelerInterface $labeler = null)
    {
        $this->labeler = $labeler;
        return $this;
    }

    /**
     * @return LabelerInterface|null
     */
    protected function getLabeler()
    {
        return $this->labeler;
    }

    /**
     * @param BlamerInterface|null $blamer
     * @return $this
     */
    public function setBlamer(BlamerInterface $blamer = null)
    {
        $this->blamer = $blamer;
        return $this;
    }

    /**
     * @return BlamerInterface|null
     */
    protected function getBlamer()
    {
        return $this->blamer;
    }

    /**
     * @param array $auditedEntities
     */
    public function addAuditedEntities(array $auditedEntities)
    {
        // use entity names as array keys for easier lookup
        foreach ($auditedEntities as $auditedEntity) {
            $this->auditedEntities[$auditedEntity] = true;
        }
    }

    /**
     * @param array $unauditedEntities
     */
    public function addUnauditedEntities(array $unauditedEntities)
    {
        // use entity names as array keys for easier lookup
        foreach ($unauditedEntities as $unauditedEntity) {
            $this->unauditedEntities[$unauditedEntity] = true;
        }
    }

    /**
     * @return array
     */
    public function getUnauditedEntities()
    {
        return array_keys($this->unauditedEntities);
    }

    /**
     * @param $entity
     * @return bool
     */
    protected function isEntityUnaudited($entity)
    {
        if (!empty($this->auditedEntities)) {
            // only selected entities are audited
            $isEntityUnaudited = TRUE;
            foreach (array_keys($this->auditedEntities) as $auditedEntity) {
                if ($entity instanceof $auditedEntity) {
                    $isEntityUnaudited = FALSE;
                    break;
                }
            }
        } else {
            $isEntityUnaudited = FALSE;
            foreach (array_keys($this->unauditedEntities) as $unauditedEntity) {
                if ($entity instanceof $unauditedEntity) {
                    $isEntityUnaudited = TRUE;
                    break;
                }
            }
        }

        return $isEntityUnaudited;
    }

    /**
     * @return LoggerChain
     */
    protected function extendsSQLLogger(){
        $new = new LoggerChain();
        $new->addLogger(new AuditLogger($this));

        $this->old = $this->em->getConnection()->getConfiguration()->getSQLLogger();
        if ($this->old instanceof SQLLogger) {
            $new->addLogger($this->old);
        }

        $this->em->getConnection()->getConfiguration()->setSQLLogger($new);

        return $new;
    }

    /**
     * @param OnFlushEventArgs $args
     * @throws \Doctrine\DBAL\DBALException
     */
    public function onFlush(OnFlushEventArgs $args)
    {
        $this->em = $args->getEntityManager();
        $this->extendsSQLLogger();

        $uow = $this->em->getUnitOfWork();

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if ($this->isEntityUnaudited($entity)) continue;
            $this->updated[] = [$entity, $uow->getEntityChangeSet($entity)];
        }

        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if ($this->isEntityUnaudited($entity)) continue;
            $this->inserted[] = [$entity, $ch = $uow->getEntityChangeSet($entity)];
        }

        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            if ($this->isEntityUnaudited($entity)) continue;
            $uow->initializeObject($entity);
            $this->removed[] = [$entity, $this->id($entity)];
        }

        /** @var PersistentCollection $collection */
        foreach ($uow->getScheduledCollectionUpdates() as $collection) {
            if ($this->isEntityUnaudited($collection->getOwner())) continue;
            $mapping = $collection->getMapping();
            if (!$mapping['isOwningSide'] || $mapping['type'] !== ClassMetadataInfo::MANY_TO_MANY) continue; // ignore inverse side or one to many relations
            foreach ($collection->getInsertDiff() as $entity) {
                if ($this->isEntityUnaudited($entity)) continue;
                $this->associated[] = [$collection->getOwner(), $entity, $mapping];
            }
            foreach ($collection->getDeleteDiff() as $entity) {
                if ($this->isEntityUnaudited($entity)) continue;
                $this->dissociated[] = [$collection->getOwner(), $entity, $this->id($entity), $mapping];
            }
        }

        /** @var PersistentCollection $collection */
        foreach ($uow->getScheduledCollectionDeletions() as $collection) {
            if ($this->isEntityUnaudited($collection->getOwner())) continue;
            $mapping = $collection->getMapping();
            if (!$mapping['isOwningSide'] || $mapping['type'] !== ClassMetadataInfo::MANY_TO_MANY)  continue; // ignore inverse side or one to many relations
            foreach ($collection->toArray() as $entity) {
                if ($this->isEntityUnaudited($entity)) {
                    continue;
                }
                $this->dissociated[] = [$collection->getOwner(), $entity, $this->id($entity), $mapping];
            }
        }
    }

    /**
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     * @throws \ReflectionException
     */
    public function flush()
    {
        $this->em->getConnection()->getConfiguration()->setSQLLogger($this->old);
        $uow = $this->em->getUnitOfWork();

        $auditPersister = $uow->getEntityPersister(AuditLog::class);
        $rmAuditInsertSQL = new \ReflectionMethod($auditPersister, 'getInsertSQL');
        $rmAuditInsertSQL->setAccessible(true);
        $this->auditInsertStmt = $this->em->getConnection()->prepare($rmAuditInsertSQL->invoke($auditPersister));
        $assocPersister = $uow->getEntityPersister(Association::class);
        $rmAssocInsertSQL = new \ReflectionMethod($assocPersister, 'getInsertSQL');
        $rmAssocInsertSQL->setAccessible(true);
        $this->assocInsertStmt = $this->em->getConnection()->prepare($rmAssocInsertSQL->invoke($assocPersister));

        foreach ($this->updated as $entry) {
            list($entity, $ch) = $entry;
            // the changeset might be updated from UOW extra updates
            $ch = array_merge($ch, $uow->getEntityChangeSet($entity));
            $this->update($entity, $ch);
        }

        foreach ($this->inserted as $entry) {
            list($entity, $ch) = $entry;
            // the changeset might be updated from UOW extra updates
            $ch = array_merge($ch, $uow->getEntityChangeSet($entity));
            $this->insert($entity, $ch);
        }

        foreach ($this->associated as $entry) {
            list($source, $target, $mapping) = $entry;
            $this->associate($source, $target, $mapping);
        }

        foreach ($this->dissociated as $entry) {
            list($source, $target, $id, $mapping) = $entry;
            $this->dissociate($source, $target, $id, $mapping);
        }

        foreach ($this->removed as $entry) {
            list($entity, $id) = $entry;
            $this->remove($entity, $id);
        }

        $this->inserted    = [];
        $this->updated     = [];
        $this->removed     = [];
        $this->associated  = [];
        $this->dissociated = [];
    }

    /**
     * @param       $source
     * @param       $target
     * @param array $mapping
     * @throws \Doctrine\DBAL\DBALException
     */
    protected function associate($source, $target, array $mapping)
    {
        $this->audit([
            'source' => $this->assoc($source),
            'target' => $this->assoc($target),
            'action' => 'associate',
            'blame'  => $this->blame(),
            'diff'   => null,
            'tbl'    => $mapping['joinTable']['name'],
        ]);
    }

    /**
     * @param       $source
     * @param       $target
     * @param       $id
     * @param array $mapping
     * @throws \Doctrine\DBAL\DBALException
     */
    protected function dissociate($source, $target, $id, array $mapping)
    {
        $this->audit([
            'source' => $this->assoc($source),
            'target' => array_merge($this->assoc($target), ['fk' => $id]),
            'action' => 'dissociate',
            'blame'  => $this->blame(),
            'diff'   => null,
            'tbl'    => $mapping['joinTable']['name'],
        ]);
    }

    /**
     * @param       $entity
     * @param array $ch
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    protected function insert($entity, array $ch)
    {
        $diff = $this->diff($entity, $ch);
        if (empty($diff)) {
            return; // if there is no entity diff, do not log it
        }
        $meta = $this->em->getClassMetadata(get_class($entity));
        $this->audit([
            'action' => 'insert',
            'source' => $this->assoc($entity),
            'target' => null,
            'blame'  => $this->blame(),
            'diff'   => $diff,
            'tbl'    => $meta->table['name'],
        ]);
    }

    /**
     * @param       $entity
     * @param array $ch
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    protected function update($entity, array $ch)
    {
        $diff = $this->diff($entity, $ch);
        if (empty($diff)) return; // if there is no entity diff, do not log it

        $meta = $this->em->getClassMetadata(get_class($entity));
        $this->audit([
            'action' => 'update',
            'source' => $this->assoc($entity),
            'target' => null,
            'blame'  => $this->blame(),
            'diff'   => $diff,
            'tbl'    => $meta->table['name'],
        ]);
    }

    /**
     * @param $entity
     * @param $id
     * @throws \Doctrine\DBAL\DBALException
     */
    protected function remove($entity, $id)
    {
        $meta   = $this->em->getClassMetadata(get_class($entity));
        $source = array_merge($this->assoc($entity), ['fk' => $id]);
        $this->audit([
            'action' => 'remove',
            'source' => $source,
            'target' => null,
            'blame'  => $this->blame(),
            'diff'   => null,
            'tbl'    => $meta->table['name'],
        ]);
    }

    /**
     * @param array         $data
     * @throws \Doctrine\DBAL\DBALException
     */
    protected function audit(array $data)
    {
        $meta = $this->em->getClassMetadata(Association::class);
        foreach (['source', 'target', 'blame'] as $field) {
            if (null === $data[$field]) continue;

            $idx = 1;
            foreach ($meta->reflFields as $name => $f) {
                if ($meta->isIdentifier($name)) continue;
                $typ = $meta->fieldMappings[$name]['type'];

                $this->assocInsertStmt->bindValue($idx++, $data[$field][$name], $typ);
            }
            $this->assocInsertStmt->execute();
            // use id generator, it will always use identity strategy, since our
            // audit association explicitly sets that.
            $data[$field] = $meta->idGenerator->generate($this->em, null);
        }

        $meta = $this->em->getClassMetadata(AuditLog::class);
        $data['loggedAt'] = new \DateTime();
        $idx = 1;
        foreach ($meta->reflFields as $name => $f) {
            if ($meta->isIdentifier($name)) {
                continue;
            }
            if (isset($meta->fieldMappings[$name]['type'])) {
                $typ = $meta->fieldMappings[$name]['type'];
            } else {
                $typ = Type::getType(Type::BIGINT); // relation
            }
            // @TODO: this check may not be necessary, simply it ensures that empty values are nulled
            if (in_array($name, ['source', 'target', 'blame']) && $data[$name] === false) {
                $data[$name] = null;
            }
            $this->auditInsertStmt->bindValue($idx++, $data[$name], $typ);
        }
        $this->auditInsertStmt->execute();
    }

    /**
     * @param               $entity
     * @return array
     * @throws \Doctrine\DBAL\DBALException
     */
    protected function id($entity)
    {
        $meta = $this->em->getClassMetadata(get_class($entity));
        $identifiers = $meta->getIdentifierFieldNames();

        $result = [];
        foreach($identifiers as $pk){
            if(isset($meta->fieldMappings[$pk])){
                $result[$pk] = $this->value(
                    Type::getType($meta->fieldMappings[$pk]['type']),
                    $meta->getReflectionProperty($pk)->getValue($entity)
                );
            }else if(isset($meta->associationMappings[$pk]) && $meta->associationMappings[$pk]['id']){
                $entityKey = $meta->getReflectionProperty($pk)->getValue($entity);
                $result[$pk] = $this->id($entityKey, false);
            }

        }
        return $result;
    }

    /**
     * @param       $entity
     * @param array $ch
     * @return array
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    protected function diff($entity, array $ch)
    {
        $meta = $this->em->getClassMetadata(get_class($entity));
        $diff = [];
        foreach ($ch as $fieldName => list($old, $new)) {
            if ($meta->hasField($fieldName) && !array_key_exists($fieldName, $meta->embeddedClasses)) {
                $mapping = $meta->fieldMappings[$fieldName];
                $diff[$fieldName] = [
                    'old' => $this->value(Type::getType($mapping['type']), $old),
                    'new' => $this->value(Type::getType($mapping['type']), $new),
                    'col' => $mapping['columnName'],
                ];
            } elseif ($meta->hasAssociation($fieldName) && $meta->isSingleValuedAssociation($fieldName)) {
                $colName = $meta->getSingleAssociationJoinColumnName($fieldName);
                $diff[$fieldName] = [
                    'old' => $this->assoc($old),
                    'new' => $this->assoc($new),
                    'col' => $colName,
                ];
            }
        }
        return $diff;
    }

    /**
     * @param null          $association
     * @return array|null
     */
    protected function assoc($association = null)
    {
        if (null === $association) {
            return null;
        }

        $class = get_class($association);

        try {
            $meta = $this->em->getClassMetadata($class);
            $this->em->getUnitOfWork()->initializeObject($association);
            $res = [
                'class' => $class,
                'typ'   => $this->typ($class),
                'tbl'   => $meta->table['name'],
                'label' => $this->label($association),
                'fk'    => $this->id($association)
            ];
        } catch (\Exception $e) {
            $res = [
                'class' => $class,
                'typ'   => $this->typ($class),
                'tbl'   => null,
                'label' => null,
                'fk'    => $association->getId() //make test for existing getId() method on entity
            ];
        }

        return $res;
    }

    /**
     * @param $className
     * @return string
     */
    protected function typ($className)
    {
        // strip prefixes and repeating garbage from name
        $className = preg_replace("/^(.+\\\)?(.+)(Bundle\\\Entity)/", "$2", $className);
        // underscore and lowercase each subdirectory
        return implode('.', array_map(function($name) {
            return strtolower(preg_replace('/(?<=\\w)(?=[A-Z])/', '_$1', $name));
        }, explode('\\', $className)));
    }

    /**
     * @param               $entity
     * @return mixed|string
     */
    protected function label($entity)
    {
        if ($this->labeler instanceof LabelerInterface) {
            $this->labeler->setEntityManager($this->em);
            return $this->labeler->label($entity);
        }

        return "Unlabeled";
    }

    /**
     * @param Type          $type
     * @param               $value
     * @return mixed
     * @throws \Doctrine\DBAL\DBALException
     */
    protected function value(Type $type, $value)
    {
        switch ($type->getName()) {
            case Type::BOOLEAN:
                return $type->convertToPHPValue($value, $this->em->getConnection()->getDatabasePlatform()); // json supports boolean values
            default:
                return $type->convertToDatabaseValue($value, $this->em->getConnection()->getDatabasePlatform());
        }
    }

    /**
     * @param EntityManager $em
     * @return array|null
     */
    protected function blame()
    {
        if ($this->blamer instanceof BlamerInterface) {
            $blamed = $this->blamer->blame($this->securityTokenStorage->getToken());
            if($blamed) return $this->assoc($blamed);
        }

        return null;
    }

    /**
     * @return array|string[]
     */
    public function getSubscribedEvents()
    {
        return [Events::onFlush];
    }
}
