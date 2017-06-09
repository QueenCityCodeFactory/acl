<?php
/**
 * CakePHP(tm) : Rapid Development Framework (http://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (http://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (http://cakefoundation.org)
 * @link          http://cakephp.org CakePHP(tm) Project
 * @license       http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace Acl\Adapter;

use Acl\AclInterface;
use Acl\Adapter\PermissionsTrait;

/**
 * CachedDbAcl extends DbAcl to add caching of permissions.
 *
 * Its usage is identical to that of DbAcl, however it supports a `Acl.cacheConfig` configuration value
 * This configuration value tells CachedDbAcl what cache config should be used.
 */
class CachedDbAcl extends DbAcl implements AclInterface
{

    use PermissionsTrait;

    /**
     * Allow $aro to have access to action $actions in $aco
     *
     * @param string $aro ARO The requesting object identifier.
     * @param string $aco ACO The controlled object identifier.
     * @param string $actions Action (defaults to *)
     * @param int $value Value to indicate access type (1 to give access, -1 to deny, 0 to inherit)
     * @return bool Success
     * @link http://book.cakephp.org/2.0/en/core-libraries/components/access-control-lists.html#assigning-permissions
     */
    public function allow($aro, $aco, $actions = "*", $value = 1)
    {
        $this->_clearCache($aro);

        return parent::allow($aro, $aco, $actions, $value);
    }

    /**
     * Deny access for $aro to action $action in $aco
     *
     * @param string $aro ARO The requesting object identifier.
     * @param string $aco ACO The controlled object identifier.
     * @param string $action Action (defaults to *)
     * @return bool Success
     * @link http://book.cakephp.org/2.0/en/core-libraries/components/access-control-lists.html#assigning-permissions
     */
    public function deny($aro, $aco, $action = "*")
    {
        $this->_clearCache($aro);

        return parent::deny($aro, $aco, $action);
    }

    /**
     * Let access for $aro to action $action in $aco be inherited
     *
     * @param string $aro ARO The requesting object identifier.
     * @param string $aco ACO The controlled object identifier.
     * @param string $action Action (defaults to *)
     * @return bool Success
     */
    public function inherit($aro, $aco, $action = "*")
    {
        $this->_clearCache($aro);

        return parent::inherit($aro, $aco, $action);
    }

    /**
     * Allow $aro to have access to action $actions in $aco
     *
     * @param string $aro ARO The requesting object identifier.
     * @param string $aco ACO The controlled object identifier.
     * @param string $action Action (defaults to *)
     * @return bool Success
     * @see allow()
     */
    public function grant($aro, $aco, $action = "*")
    {
        $this->_clearCache($aro);

        return parent::grant($aro, $aco, $action);
    }

    /**
     * Deny access for $aro to action $action in $aco
     *
     * @param string $aro ARO The requesting object identifier.
     * @param string $aco ACO The controlled object identifier.
     * @param string $action Action (defaults to *)
     * @return bool Success
     * @see deny()
     */
    public function revoke($aro, $aco, $action = "*")
    {
        $this->_clearCache($aro);

        return parent::revoke($aro, $aco, $action);
    }
}
