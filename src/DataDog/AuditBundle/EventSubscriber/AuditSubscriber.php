<?php

namespace DataDog\AuditBundle\EventSubscriber;

use DataDog\AuditBundle\DBAL\AuditLogger;
use DataDog\AuditBundle\Entity\AuditLog;
use DataDog\AuditBundle\Entity\Association;

use DataDog\AuditBundle\Model\BlamerInterface;
use DataDog\AuditBundle\Model\LabelerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;
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
class AuditSubscriber implements EventSubscriber
{
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
     * @var TokenStorage
     */
    protected $securityTokenStorage;

    protected $auditedEntities = [];
    protected $unauditedEntities = [];

    protected $inserted = []; // [$source, $changeset]
    protected $updated = []; // [$source, $changeset]
    protected $removed = []; // [$source, $id]
    protected $associated = [];   // [$source, $target, $mapping]
    protected $dissociated = []; // [$source, $target, $id, $mapping]

    protected $assocInsertStmt;
    protected $auditInsertStmt;

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
    public function getLabeler()
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
    public function getBlamer()
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
     * @param OnFlushEventArgs $args
     */
    public function onFlush(OnFlushEventArgs $args)
    {
        $em = $args->getEntityManager();
        $uow = $em->getUnitOfWork();

        // extend the sql logger
        $this->old = $em->getConnection()->getConfiguration()->getSQLLogger();
        $new = new LoggerChain();
        $new->addLogger(new AuditLogger(function () use($em) {
            $this->flush($em);
        }));
        if ($this->old instanceof SQLLogger) {
            $new->addLogger($this->old);
        }
        $em->getConnection()->getConfiguration()->setSQLLogger($new);

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if ($this->isEntityUnaudited($entity)) {
                continue;
            }
            $this->updated[] = [$entity, $uow->getEntityChangeSet($entity)];
        }
        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if ($this->isEntityUnaudited($entity)) {
                continue;
            }
            $this->inserted[] = [$entity, $ch = $uow->getEntityChangeSet($entity)];
        }
        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            if ($this->isEntityUnaudited($entity)) {
                continue;
            }
            $uow->initializeObject($entity);
            $this->removed[] = [$entity, $this->id($em, $entity)];
        }
        foreach ($uow->getScheduledCollectionUpdates() as $collection) {
            if ($this->isEntityUnaudited($collection->getOwner())) {
                continue;
            }
            $mapping = $collection->getMapping();
            if (!$mapping['isOwningSide'] || $mapping['type'] !== ClassMetadataInfo::MANY_TO_MANY) {
                continue; // ignore inverse side or one to many relations
            }
            foreach ($collection->getInsertDiff() as $entity) {
                if ($this->isEntityUnaudited($entity)) {
                    continue;
                }
                $this->associated[] = [$collection->getOwner(), $entity, $mapping];
            }
            foreach ($collection->getDeleteDiff() as $entity) {
                if ($this->isEntityUnaudited($entity)) {
                    continue;
                }
                $this->dissociated[] = [$collection->getOwner(), $entity, $this->id($em, $entity), $mapping];
            }
        }
        foreach ($uow->getScheduledCollectionDeletions() as $collection) {
            if ($this->isEntityUnaudited($collection->getOwner())) {
                continue;
            }
            $mapping = $collection->getMapping();
            if (!$mapping['isOwningSide'] || $mapping['type'] !== ClassMetadataInfo::MANY_TO_MANY) {
                continue; // ignore inverse side or one to many relations
            }
            foreach ($collection->toArray() as $entity) {
                if ($this->isEntityUnaudited($entity)) {
                    continue;
                }
                $this->dissociated[] = [$collection->getOwner(), $entity, $this->id($em, $entity), $mapping];
            }
        }
    }

    /**
     * @param EntityManager $em
     * @throws \Doctrine\DBAL\DBALException
     * @throws \ReflectionException
     */
    protected function flush(EntityManager $em)
    {
        $em->getConnection()->getConfiguration()->setSQLLogger($this->old);
        $uow = $em->getUnitOfWork();

        $auditPersister = $uow->getEntityPersister(AuditLog::class);
        $rmAuditInsertSQL = new \ReflectionMethod($auditPersister, 'getInsertSQL');
        $rmAuditInsertSQL->setAccessible(true);
        $this->auditInsertStmt = $em->getConnection()->prepare($rmAuditInsertSQL->invoke($auditPersister));
        $assocPersister = $uow->getEntityPersister(Association::class);
        $rmAssocInsertSQL = new \ReflectionMethod($assocPersister, 'getInsertSQL');
        $rmAssocInsertSQL->setAccessible(true);
        $this->assocInsertStmt = $em->getConnection()->prepare($rmAssocInsertSQL->invoke($assocPersister));

        foreach ($this->updated as $entry) {
            list($entity, $ch) = $entry;
            // the changeset might be updated from UOW extra updates
            $ch = array_merge($ch, $uow->getEntityChangeSet($entity));
            $this->update($em, $entity, $ch);
        }

        foreach ($this->inserted as $entry) {
            list($entity, $ch) = $entry;
            // the changeset might be updated from UOW extra updates
            $ch = array_merge($ch, $uow->getEntityChangeSet($entity));
            $this->insert($em, $entity, $ch);
        }

        foreach ($this->associated as $entry) {
            list($source, $target, $mapping) = $entry;
            $this->associate($em, $source, $target, $mapping);
        }

        foreach ($this->dissociated as $entry) {
            list($source, $target, $id, $mapping) = $entry;
            $this->dissociate($em, $source, $target, $id, $mapping);
        }

        foreach ($this->removed as $entry) {
            list($entity, $id) = $entry;
            $this->remove($em, $entity, $id);
        }

        $this->inserted = [];
        $this->updated = [];
        $this->removed = [];
        $this->associated = [];
        $this->dissociated = [];
    }

    /**
     * @param EntityManager $em
     * @param               $source
     * @param               $target
     * @param array         $mapping
     */
    protected function associate(EntityManager $em, $source, $target, array $mapping)
    {
        $this->audit($em, [
            'source' => $this->assoc($em, $source),
            'target' => $this->assoc($em, $target),
            'action' => 'associate',
            'blame'  => $this->blame($em),
            'diff'   => null,
            'tbl'    => $mapping['joinTable']['name'],
        ]);
    }

    /**
     * @param EntityManager $em
     * @param               $source
     * @param               $target
     * @param               $id
     * @param array         $mapping
     */
    protected function dissociate(EntityManager $em, $source, $target, $id, array $mapping)
    {
        $this->audit($em, [
            'source' => $this->assoc($em, $source),
            'target' => array_merge($this->assoc($em, $target), ['fk' => $id]),
            'action' => 'dissociate',
            'blame'  => $this->blame($em),
            'diff'   => null,
            'tbl'    => $mapping['joinTable']['name'],
        ]);
    }

    /**
     * @param EntityManager $em
     * @param               $entity
     * @param array         $ch
     */
    protected function insert(EntityManager $em, $entity, array $ch)
    {
        $diff = $this->diff($em, $entity, $ch);
        if (empty($diff)) {
            return; // if there is no entity diff, do not log it
        }
        $meta = $em->getClassMetadata(get_class($entity));
        $this->audit($em, [
            'action' => 'insert',
            'source' => $this->assoc($em, $entity),
            'target' => null,
            'blame'  => $this->blame($em),
            'diff'   => json_encode($diff),
            'tbl'    => $meta->table['name'],
        ]);
    }

    /**
     * @param EntityManager $em
     * @param               $entity
     * @param array         $ch
     */
    protected function update(EntityManager $em, $entity, array $ch)
    {
        $diff = $this->diff($em, $entity, $ch);
        if (empty($diff)) {
            return; // if there is no entity diff, do not log it
        }
        $meta = $em->getClassMetadata(get_class($entity));
        $this->audit($em, [
            'action' => 'update',
            'source' => $this->assoc($em, $entity),
            'target' => null,
            'blame'  => $this->blame($em),
            'diff'   => json_encode($diff),
            'tbl'    => $meta->table['name'],
        ]);
    }

    /**
     * @param EntityManager $em
     * @param               $entity
     * @param               $id
     * @throws \Doctrine\DBAL\DBALException
     */
    protected function remove(EntityManager $em, $entity, $id)
    {
        $meta = $em->getClassMetadata(get_class($entity));
        $source = array_merge($this->assoc($em, $entity), ['fk' => $id]);
        $this->audit($em, [
            'action' => 'remove',
            'source' => $source,
            'target' => null,
            'blame'  => $this->blame($em),
            'diff'   => null,
            'tbl'    => $meta->table['name'],
        ]);
    }

    /**
     * @param EntityManager $em
     * @param array         $data
     * @throws \Doctrine\DBAL\DBALException
     */
    protected function audit(EntityManager $em, array $data)
    {
        $c = $em->getConnection();
        $p = $c->getDatabasePlatform();
        $q = $em->getConfiguration()->getQuoteStrategy();

        foreach (['source', 'target', 'blame'] as $field) {
            if (null === $data[$field]) {
                continue;
            }
            $meta = $em->getClassMetadata(Association::class);
            $idx = 1;
            foreach ($meta->reflFields as $name => $f) {
                if ($meta->isIdentifier($name)) {
                    continue;
                }
                $typ = $meta->fieldMappings[$name]['type'];

                $this->assocInsertStmt->bindValue($idx++, $data[$field][$name], $typ);
            }
            $this->assocInsertStmt->execute();
            // use id generator, it will always use identity strategy, since our
            // audit association explicitly sets that.
            $data[$field] = $meta->idGenerator->generate($em, null);
        }

        $meta = $em->getClassMetadata(AuditLog::class);
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
     * @param EntityManager $em
     * @param               $entity
     * @return mixed|string
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    protected function id(EntityManager $em, $entity)
    {
        $meta = $em->getClassMetadata(get_class($entity));
        $pk = $meta->getSingleIdentifierFieldName();
        $pk = $this->value(
            $em,
            Type::getType($meta->fieldMappings[$pk]['type']),
            $meta->getReflectionProperty($pk)->getValue($entity)
        );
        return $pk;
    }

    /**
     * @param EntityManager $em
     * @param               $entity
     * @param array         $ch
     * @return array
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    protected function diff(EntityManager $em, $entity, array $ch)
    {
        $uow = $em->getUnitOfWork();
        $meta = $em->getClassMetadata(get_class($entity));
        $diff = [];
        foreach ($ch as $fieldName => list($old, $new)) {
            if ($meta->hasField($fieldName) && !array_key_exists($fieldName, $meta->embeddedClasses)) {
                $mapping = $meta->fieldMappings[$fieldName];
                $diff[$fieldName] = [
                    'old' => $this->value($em, Type::getType($mapping['type']), $old),
                    'new' => $this->value($em, Type::getType($mapping['type']), $new),
                    'col' => $mapping['columnName'],
                ];
            } elseif ($meta->hasAssociation($fieldName) && $meta->isSingleValuedAssociation($fieldName)) {
                $mapping = $meta->associationMappings[$fieldName];
                $colName = $meta->getSingleAssociationJoinColumnName($fieldName);
                $assocMeta = $em->getClassMetadata($mapping['targetEntity']);
                $diff[$fieldName] = [
                    'old' => $this->assoc($em, $old),
                    'new' => $this->assoc($em, $new),
                    'col' => $colName,
                ];
            }
        }
        return $diff;
    }

    /**
     * @param EntityManager $em
     * @param null          $association
     * @return array|null
     */
    protected function assoc(EntityManager $em, $association = null)
    {
        if (null === $association) {
            return null;
        }

        $meta = get_class($association);
        $res = ['class' => $meta, 'typ' => $this->typ($meta), 'tbl' => null, 'label' => null];

        try {
            $meta = $em->getClassMetadata($meta);
            $res['tbl'] = $meta->table['name'];
            $em->getUnitOfWork()->initializeObject($association); // ensure that proxies are initialized
            $res['fk'] = (string)$this->id($em, $association);
            $res['label'] = $this->label($em, $association);
        } catch (\Exception $e) {
            $res['fk'] = (string) $association->getId();
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
     * @param EntityManager $em
     * @param               $entity
     * @return mixed|string
     */
    protected function label(EntityManager $em, $entity)
    {
        if ($this->labeler instanceof LabelerInterface) {
            $this->labeler->setEntityManager($em);
            return $this->labeler->label($entity);
        }

        return "Unlabeled";
    }

    /**
     * @param EntityManager $em
     * @param Type          $type
     * @param               $value
     * @return mixed
     * @throws \Doctrine\DBAL\DBALException
     */
    protected function value(EntityManager $em, Type $type, $value)
    {
        $platform = $em->getConnection()->getDatabasePlatform();
        switch ($type->getName()) {
        case Type::BOOLEAN:
            return $type->convertToPHPValue($value, $platform); // json supports boolean values
        default:
            return $type->convertToDatabaseValue($value, $platform);
        }
    }

    /**
     * @param EntityManager $em
     * @return array|null
     */
    protected function blame(EntityManager $em)
    {
        if ($this->blameUser instanceof UserInterface) {
            return $this->assoc($em, $this->blameUser);
        }
        if ($this->blamer instanceof BlamerInterface) {
            $blamed = $this->blamer->blame($this->securityTokenStorage->getToken());
            return $blamed ? $this->assoc($em, $blamed) : null;
        }
        $token = $this->securityTokenStorage->getToken();
        if ($token && $token->getUser() instanceof UserInterface) {
            return $this->assoc($em, $token->getUser());
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
