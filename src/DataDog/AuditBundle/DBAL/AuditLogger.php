<?php

namespace DataDog\AuditBundle\DBAL;

use DataDog\AuditBundle\Model\FlusherInterface;
use Doctrine\DBAL\Logging\SQLLogger;

class AuditLogger implements SQLLogger
{
    /**
     * @var FlusherInterface
     */
    private $flusher;

    /**
     * AuditLogger constructor.
     * @param FlusherInterface $flusher
     */
    public function __construct(FlusherInterface $flusher)
    {
        $this->flusher = $flusher;
    }

    /**
     * {@inheritdoc}
     */
    public function startQuery($sql, array $params = null, array $types = null)
    {
        // right before commit insert all audit entries
        if ($sql === '"COMMIT"') {
            $this->flusher->flush();
        }
    }

    /**
     * {@inheritdoc}
     */
    public function stopQuery()
    {
    }
}
