<?php


namespace DataDog\AuditBundle\Model;


use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

interface BlamerInterface
{
    /**
     * @param TokenInterface|null $token
     * @return mixed|null
     */
    public function blame(TokenInterface $token = null);
}