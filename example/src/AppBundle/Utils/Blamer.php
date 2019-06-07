<?php


namespace AppBundle\Utils;

use DataDog\AuditBundle\Model\BlamerInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * Class Blamer
 * @package AppBundle\Utils
 */
class Blamer implements BlamerInterface
{


    /**
     * @param UserInterface $user
     */
    public function setBlamed($blamed)
    {
        $this->blamed = $blamed;
    }

    /**
     * {@inheritdoc}
     */
    public function blame(TokenInterface $token = null){

        return $token ? $token->getUser() : null;
    }
}