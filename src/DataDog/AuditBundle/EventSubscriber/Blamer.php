<?php
namespace DataDog\AuditBundle\EventSubscriber;

use DataDog\AuditBundle\Model\BlamerInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Class Blamer
 * @package DataDog\AuditBundle\EventSubscriber
 */
class Blamer implements BlamerInterface
{
    /**
     * {@inheritDoc}
     */
    public function blame(TokenInterface $token = null)
    {
        if($token){
            if($token->getUser() instanceof UserInterface) return $token->getUser();
        }

        return null;
    }
}