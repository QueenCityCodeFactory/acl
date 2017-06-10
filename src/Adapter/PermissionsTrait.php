<?php
namespace Acl\Adapter;

use Cake\Cache\Cache;
use Cake\Core\Configure;
use Cake\ORM\Entity;
use Cake\ORM\TableRegistry;
use Cake\Utility\Inflector;
use ErrorException;
use Exception;

trait PermissionsTrait
{

    /**
     * Checks if the given $aro has access to action $action in $aco
     *
     * @param string $aro ARO The requesting object identifier.
     * @param string $aco ACO The controlled object identifier.
     * @param string $action Action (defaults to *)
     * @return bool Success (true if ARO has access to action in ACO, false otherwise)
     * @link http://book.cakephp.org/2.0/en/core-libraries/components/access-control-lists.html#checking-permissions-the-acl-component
     */
    public function check($aro, $aco, $action = "*")
    {
        $key = $this->_getCacheKey($aco, $action);
        $cacheConfig = $this->_getNodeCacheKey($aro);
        if (empty($cacheConfig) || (isset($aro[Configure::read('Acl.userModel')]) && empty($aro[Configure::read('Acl.userModel')][Configure::read('Acl.userForeignKey')]))) {
            return false;
        }
        $this->_cacheConfig($cacheConfig);
        $permission = Cache::remember($key, function () use ($aro, $aco, $action) {
            set_error_handler(
                function ($errorNumber, $errorText, $errorFile, $errorLine) {
                    throw new ErrorException($errorText, 0, $errorNumber, $errorFile, $errorLine);
                },
                E_USER_WARNING | E_USER_NOTICE
            );
            try {
                $permission = $this->Permissions->check($aro, $aco, $action) === true ? 'true' : 'false';
            } catch (ErrorException $e) {
                $permission = null;
            }
            restore_error_handler();

            return $permission;
        }, $cacheConfig);

        if ($permission === null) {
            Cache::delete($key, $cacheConfig);
            $permission = 'false';
        }

        return $permission === 'true';
    }

    /**
     * Create Acl cache config if it doesn't exist
     *
     * @param string $aro The ARO
     * @return bool
     */
    protected function _cacheConfig($aro)
    {
        if (Cache::config($aro) === null) {
            $cacheConfig = Configure::read('Acl.cacheConfig');
            $cacheConfig['prefix'] = isset($cacheConfig['prefix']) ? $cacheConfig['prefix'] . $aro . '_' : 'acl_' . $aro . '_';
            Cache::config($aro, $cacheConfig);

            return false;
        }

        return true;
    }

    /**
     * Clear the Acl Cache
     *
     * @param Cake\ORM\Entity $aro The Aro Entity
     * @return void
     */
    protected function _clearCache($aro)
    {
        $cacheConfig = $this->_getNodeCacheKey($aro);
        if ($this->_cacheConfig($cacheConfig) === true) {
            Cache::clear(false, $cacheConfig);
        }
    }

    /**
     * Generates a string cache key for an ACO
     *
     * @param string|array|Entity $aco The controlled object identifier.
     * @param string $action Action
     * @return string
     */
    protected function _getCacheKey($aco, $action = '*')
    {
        return strtolower($this->_getNodeCacheKey($aco) . ($action == '*' ? '' : '_' . $action));
    }

    /**
     * Generates a key string to use for the cache
     *
     * @param string|array|Entity $ref Array with 'model' and 'foreign_key', model object, or string value
     * @return string
     */
    protected function _getNodeCacheKey($ref)
    {
        if (empty($ref)) {
            return '';
        } elseif (is_string($ref)) {
            return Inflector::slug($ref, '_');
        } elseif (is_object($ref) && $ref instanceof Entity) {
            return $ref->source() . '_' . $ref->id;
        } elseif (is_array($ref) && !(isset($ref['model']) && isset($ref['foreign_key']))) {
            $name = key($ref);
            list(, $alias) = pluginSplit($name);

            $bindTable = TableRegistry::get($name);
            $entityClass = $bindTable->entityClass();

            if ($entityClass) {
                $entity = new $entityClass();
            }

            if (empty($entity)) {
                throw new Exception(
                    __d(
                        'cake_dev',
                        "Entity class {0} not found in CachedDbAcl::_getNodeCacheKey() when trying to bind {1} object",
                        [$type, $this->alias()]
                    )
                );
            }

            $tmpRef = null;
            if (method_exists($entity, 'bindNode')) {
                $tmpRef = $entity->bindNode($ref);
            }

            if (empty($tmpRef)) {
                $ref = [
                    'model' => $alias,
                    'foreign_key' => $ref[$name][$bindTable->primaryKey()]
                ];
            } else {
                $ref = $tmpRef;
            }

            return $ref['model'] . '_' . $ref['foreign_key'];
        } elseif (is_array($ref)) {
            return $ref['model'] . '_' . $ref['foreign_key'];
        }

        return '';
    }
}
