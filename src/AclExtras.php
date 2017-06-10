<?php
/**
 * Acl Extras.
 *
 * Licensed under The MIT License
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright Copyright 2008-2013, Mark Story.
 * @link http://mark-story.com
 * @author Mark Story <mark@mark-story.com>
 * @license http://www.opensource.org/licenses/mit-license.php The MIT License
 */
namespace Acl;

use Acl\Controller\Component\AclComponent;
use Cake\Controller\ComponentRegistry;
use Cake\Controller\Controller;
use Cake\Core\App;
use Cake\Core\Configure;
use Cake\Core\ConventionsTrait;
use Cake\Core\Plugin;
use Cake\Database\Exception\MissingConnectionException;
use Cake\Datasource\ConnectionManager;
use Cake\Filesystem\Folder;
use Cake\Network\Request;
use Cake\ORM\Exception\MissingTableException;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use Cake\Routing\Router;
use Cake\Utility\Hash;
use Cake\Utility\Inflector;

/**
 * Provides features for additional ACL operations.
 * Can be used in either a CLI or Web context.
 */
class AclExtras
{

    use ConventionsTrait;

    /**
     * Contains instance of AclComponent
     *
     * @var \Acl\Controller\Component\AclComponent
     */
    public $Acl;

    /**
     * Contains arguments parsed from the command line.
     *
     * @var array
     */
    public $args;

    /**
     * The db connection being used for building ACL
     *
     * @var string
     */
    public $connection = 'default';

    /**
     * Node Type/Name Mappings
     *
     * @var array
     */
    public $nodeTypeMap = [
        'root' => 'root',
        'controllers' => 'controllers',
        'models' => 'models',
        'prefix' => 'prefix',
        'plugin' => 'plugin',
        'controller' => 'controller',
        'action' => 'action',
        'table' => 'table',
        'column' => 'column',
        'belongsToMany' => 'column'
    ];

    /**
     * Name separator.
     *
     * @var string
     */
    public $nameSeparator = ' ';

    /**
     * Internal Clean Actions switch
     *
     * @var bool
     */
    protected $_clean = false;

    /**
     * Contains app route prefixes
     *
     * @var array
     */
    protected $prefixes = [];

    /**
     * Contains plugins route prefixes
     *
     * @var array
     */
    protected $pluginPrefixes = [];

    /**
     * List of ACOs found during synchronization
     *
     * @var array
     */
    protected $foundACOs = [];

    /**
     * Start up And load Acl Component / Aco model
     *
     * @param  [type] $controller [description]
     * @return void
     */
    public function startup($controller = null)
    {
        if (!$controller) {
            $controller = new Controller(new Request());
        }
        $registry = new ComponentRegistry();
        $this->Acl = new AclComponent($registry, Configure::read('Acl'));
        $this->Aco = $this->Acl->Aco;
        $this->Aro = $this->Acl->Aro;
        $this->controller = $controller;
        $this->_buildPrefixes();
    }

    /**
     * Output a message.
     *
     * Will either use shell->out, or controller->Flash->success()
     *
     * @param string $msg The message to output.
     * @return void|string
     */
    public function out($msg)
    {
        if (!empty($this->controller->Flash)) {
            $this->controller->Flash->success($msg);
        } else {
            return $this->Shell->out($msg);
        }
    }

    /**
     * Output an error message.
     *
     * Will either use shell->err, or controller->Flash->error()
     *
     * @param string $msg The message to output.
     * @return void|string
     */
    public function err($msg)
    {
        if (!empty($this->controller->Flash)) {
            $this->controller->Flash->error($msg);
        } else {
            return $this->Shell->err($msg);
        }
    }

    /**
     * Sync the ACO table
     *
     * @param array $params An array of parameters
     * @return void
     */
    public function acoSync($params = [])
    {
        $this->_clean = true;
        $this->acoUpdate($params);
    }

    /**
     * Updates the Aco Tree with new controller actions.
     *
     * @param array $params An array of parameters
     * @return void|bool
     */
    public function acoUpdate($params = [])
    {
        $root = $this->_checkNode($this->nodeTypeMap['root'], $this->nodeTypeMap['root'], null, $this->nodeTypeMap['root']);

        if (empty($params['type']) || (isset($params['type']) && $params['type'] == 'actions')) {
            $this->_processActions($root, $params);
        }
        if (empty($params['type']) || (isset($params['type']) && $params['type'] == 'models')) {
            $this->_processModels($root);
        }
        if ($this->_clean) {
            foreach ($this->foundACOs as $parentId => $acosList) {
                $this->_cleaner($parentId, $acosList);
            }
        }
        $this->out(__d('cake_acl', '<success>Aco Update Complete</success>'));

        return true;
    }

    /**
     * Updates the Aco Tree with all plugins, prefixes & controllers
     *
     * @param \Acl\Model\Entity\Aco $parent The Root Aco Node
     * @return bool|void Returns false if Plugin not Found!
     */
    protected function _processActions($parent, $params = [])
    {
        $controllersRoot = $this->_checkNode($this->nodeTypeMap['controllers'], $this->nodeTypeMap['controllers'], $parent->id, $this->nodeTypeMap['controllers']);

        if (empty($params['plugin'])) {
            $plugins = Plugin::loaded();
            $this->_processControllers($controllersRoot);
            $this->_processPrefixes($controllersRoot);
            $this->_processPlugins($controllersRoot, $plugins);
        } else {
            $plugin = $params['plugin'];
            if (!Plugin::loaded($plugin)) {
                $this->err(__d('cake_acl', "<error>Plugin {0} not found or not activated.</error>", [$plugin]));

                return false;
            }
            $plugins = [$params['plugin']];
            $this->_processPlugins($controllersRoot, $plugins);
            $this->foundACOs = array_slice($this->foundACOs, 1, null, true);
        }
    }

    /**
     * Updates the Aco Tree with all App controllers.
     *
     * @param \Acl\Model\Entity\Aco $parent The parent node of Controller side of Aco Tree
     * @return void
     */
    protected function _processControllers($parent)
    {
        $controllers = $this->getControllerList();
        $this->foundACOs[$parent->id] = $this->_updateControllers($parent, $controllers);
    }

    /**
     * Updates the Aco Tree with all App route prefixes.
     *
     * @param \Acl\Model\Entity\Aco $parent The parent node of the controller side of Aco Tree
     * @return void
     */
    protected function _processPrefixes($parent)
    {
        foreach (array_keys($this->prefixes) as $prefix) {
            $controllers = $this->getControllerList(null, $prefix);
            $path = [
                $this->nodeTypeMap['root'],
                $this->nodeTypeMap['controllers'],
                $prefix
            ];
            $path = implode('/', Hash::filter($path));
            $pathNode = $this->_checkNode($path, $prefix, $parent->id, $this->nodeTypeMap['prefix']);
            $this->foundACOs[$parent->id][] = $prefix;
            if (isset($this->foundACOs[$pathNode->id])) {
                $this->foundACOs[$pathNode->id] += $this->_updateControllers($pathNode, $controllers, null, $prefix);
            } else {
                $this->foundACOs[$pathNode->id] = $this->_updateControllers($pathNode, $controllers, null, $prefix);
            }
        }
    }

    /**
     * Returns the aliased name for the plugin (Needed in order to correctly handle nested plugins)
     *
     * @param string $plugin The name of the plugin to alias
     * @return string
     */
    protected function _pluginAlias($plugin)
    {
        return preg_replace('/\//', '\\', Inflector::camelize($plugin));
    }

    /**
     * Updates the Aco Tree with all Plugins.
     *
     * @param \Acl\Model\Entity\Aco $parent The parent node of the controller side of Aco Tree
     * @param array $plugins list of App plugins
     * @return void
     */
    protected function _processPlugins($parent, array $plugins = [])
    {
        foreach ($plugins as $plugin) {
            $controllers = $this->getControllerList($plugin);
            $pluginAlias = $this->_pluginAlias($plugin);
            $path = [
                $this->nodeTypeMap['root'],
                $this->nodeTypeMap['controllers'],
                $pluginAlias
            ];
            $path = implode('/', Hash::filter($path));
            $pathNode = $this->_checkNode($path, $pluginAlias, $parent->id, $this->nodeTypeMap['plugin']);
            $this->foundACOs[$parent->id][] = $pluginAlias;

            if (isset($this->foundACOs[$pathNode->id])) {
                $this->foundACOs[$pathNode->id] += $this->_updateControllers($pathNode, $controllers, $plugin);
            } else {
                $this->foundACOs[$pathNode->id] = $this->_updateControllers($pathNode, $controllers, $plugin);
            }

            if (isset($this->pluginPrefixes[$plugin])) {
                foreach (array_keys($this->pluginPrefixes[$plugin]) as $prefix) {
                    $path = [
                        $this->nodeTypeMap['root'],
                        $this->nodeTypeMap['controllers'],
                        $pluginAlias
                    ];
                    $path = implode('/', Hash::filter($path));
                    $pluginNode = $this->_checkNode($path, $pluginAlias, $parent->id, $this->nodeTypeMap['plugin']);
                    $this->foundACOs[$parent->id][] = $pluginAlias;

                    $path = [
                        $this->nodeTypeMap['root'],
                        $this->nodeTypeMap['controllers'],
                        $pluginAlias,
                        $prefix,
                    ];
                    $path = implode('/', Hash::filter($path));
                    $pathNode = $this->_checkNode($path, $prefix, $pluginNode->id, $this->nodeTypeMap['plugin']);
                    $this->foundACOs[$pluginNode->id][] = $prefix;

                    $controllers = $this->getControllerList($plugin, $prefix);
                    if (isset($this->foundACOs[$pathNode->id])) {
                        $this->foundACOs[$pathNode->id] += $this->_updateControllers($pathNode, $controllers, $pluginAlias, $prefix);
                    } else {
                        $this->foundACOs[$pathNode->id] = $this->_updateControllers($pathNode, $controllers, $pluginAlias, $prefix);
                    }
                }
            }
        }
    }

    /**
     * Updates a collection of controllers.
     *
     * @param array $parent Array or ACO information for parent node.
     * @param array $controllers Array of Controllers
     * @param string $plugin Name of the plugin you are making controllers for.
     * @param string $prefix Name of the prefix you are making controllers for.
     * @return array
     */
    protected function _updateControllers($parent, $controllers, $plugin = null, $prefix = null)
    {
        $pluginPath = $this->_pluginAlias($plugin);

        // look at each controller
        $controllersNames = [];
        foreach ($controllers as $controller) {
            $tmp = explode('/', $controller);
            $controllerName = str_replace('Controller.php', '', array_pop($tmp));
            if ($controllerName == 'App') {
                continue;
            }
            $controllersNames[] = $controllerName;
            $path = [
                $this->nodeTypeMap['root'],
                $this->nodeTypeMap['controllers'],
                $pluginPath,
                $prefix,
                $controllerName
            ];
            $path = implode('/', Hash::filter($path));
            $controllerNode = $this->_checkNode($path, $controllerName, $parent->id, $this->nodeTypeMap['controller']);
            $this->_checkMethods($controller, $controllerName, $controllerNode, $pluginPath, $prefix);
        }

        return $controllersNames;
    }

    /**
     * Get a list of controllers in the app and plugins.
     *
     * Returns an array of path => import notation.
     *
     * @param string $plugin Name of plugin to get controllers for
     * @param string $prefix Name of prefix to get controllers for
     * @return array
     */
    public function getControllerList($plugin = null, $prefix = null)
    {
        if (!$plugin) {
            $path = App::path('Controller' . (empty($prefix) ? '' : DS . Inflector::camelize($prefix)));
            $dir = new Folder($path[0]);
            $controllers = $dir->find('.*Controller\.php');
        } else {
            $path = App::path('Controller' . (empty($prefix) ? '' : DS . Inflector::camelize($prefix)), $plugin);
            $dir = new Folder($path[0]);
            $controllers = $dir->find('.*Controller\.php');
        }

        return $controllers;
    }

    /**
     * Check a node for existence, create it if it doesn't exist.
     *
     * @param string $path The path to check
     * @param string $alias The alias to create
     * @param int $parentId The parent id to use when creating.
     * @param string $nodeType The type of node
     * @return array Aco Node array
     */
    protected function _checkNode($path, $alias, $parentId = null, $nodeType = null)
    {
        $node = $this->Aco->node($path);
        if (!$node) {
            $data = [
                'parent_id' => $parentId,
                'model' => null,
                'alias' => $alias,
                'name' => Inflector::humanize($alias),
                'node_type' => $nodeType,
            ];
            $entity = $this->Aco->newEntity($data);
            $node = $this->Aco->save($entity);
            $this->out(__d('cake_acl', 'Created Aco node: <success>{0}</success>', $path));
        } else {
            $node = $node->first();
        }

        return $node;
    }

    /**
     * Get a list of registered callback methods
     *
     * @param string $className The class to reflect on.
     * @param string $pluginPath The plugin path.
     * @param string $prefixPath The prefix path.
     * @return array
     */
    protected function _getCallbacks($className, $pluginPath = null, $prefixPath = null)
    {
        $callbacks = [];
        $namespace = $this->_getNamespace($className, $pluginPath, $prefixPath);
        $reflection = new \ReflectionClass($namespace);
        if ($reflection->isAbstract()) {
            return $callbacks;
        }
        try {
            $method = $reflection->getMethod('implementedEvents');
        } catch (ReflectionException $e) {
            return $callbacks;
        }
        if (version_compare(phpversion(), '5.4', '>=')) {
            $object = $reflection->newInstanceWithoutConstructor();
        } else {
            $object = unserialize(
                sprintf('O:%d:"%s":0:{}', strlen($className), $className)
            );
        }
        $implementedEvents = $method->invoke($object);
        foreach ($implementedEvents as $event => $callable) {
            if (is_string($callable)) {
                $callbacks[] = $callable;
            }
            if (is_array($callable) && isset($callable['callable'])) {
                $callbacks[] = $callable['callable'];
            }
        }

        return $callbacks;
    }

    /**
     * Check and Add/delete controller Methods
     *
     * @param string $className The classname to check
     * @param string $controllerName The controller name
     * @param array $node The node to check.
     * @param string $pluginPath The plugin path to use.
     * @param string $prefixPath The prefix path to use.
     * @return bool
     */
    protected function _checkMethods($className, $controllerName, $node, $pluginPath = null, $prefixPath = null)
    {
        $excludes = $this->_getCallbacks($className, $pluginPath, $prefixPath);
        $baseMethods = get_class_methods(new Controller);
        $namespace = $this->_getNamespace($className, $pluginPath, $prefixPath);
        $methods = get_class_methods(new $namespace);
        if ($methods == null) {
            $this->err(__d('cake_acl', 'Unable to get methods for {0}', $className));

            return false;
        }
        $actions = array_diff($methods, $baseMethods);
        $actions = array_diff($actions, $excludes);
        foreach ($actions as $key => $action) {
            if (strpos($action, '_', 0) === 0) {
                continue;
            }
            $path = [
                $this->nodeTypeMap['root'],
                $this->nodeTypeMap['controllers'],
                $pluginPath,
                $prefixPath,
                $controllerName,
                $action
            ];
            $path = implode('/', Hash::filter($path));
            $this->_checkNode($path, $action, $node->id, $this->nodeTypeMap['action']);
            $actions[$key] = $action;
        }
        if ($this->_clean) {
            $this->_cleaner($node->id, $actions);
        }

        return true;
    }

    /**
     * Recover an Acl Tree
     *
     * @param string $type The Tree type to recover ACO|ARO
     * @return void
     */
    public function recover($type = null)
    {
        if (!empty($type)) {
            $type = Inflector::camelize($type);
        } else {
            $type = Inflector::camelize($this->args[0]);
        }
        $this->{$type}->recover();
        $this->out(__d('cake_acl', 'Tree has been recovered, or tree did not need recovered.'));
    }

    /**
     * Get the namespace for a given class.
     *
     * @param string $className The class you want a namespace for.
     * @param string $pluginPath The plugin path.
     * @param string $prefixPath The prefix path.
     * @return string
     */
    protected function _getNamespace($className, $pluginPath = null, $prefixPath = null)
    {
        $namespace = preg_replace('/(.*)Controller\//', '', $className);
        $namespace = preg_replace('/\//', '\\', $namespace);
        $namespace = preg_replace('/\.php/', '', $namespace);
        $prefixPath = preg_replace('/\//', '\\', Inflector::camelize($prefixPath));
        if (!$pluginPath) {
            $parentNamespace = Configure::read('App.namespace');
        } else {
            $parentNamespace = preg_replace('/\//', '\\', $pluginPath);
        }
        $namespace = [
            $parentNamespace,
            'Controller',
            $prefixPath,
            $namespace
        ];

        return implode('\\', Hash::filter($namespace));
    }

    /**
     * Build prefixes for App and Plugins based on configured routes
     *
     * @return void
     */
    protected function _buildPrefixes()
    {
        $routes = Router::routes();
        foreach ($routes as $key => $route) {
            if (isset($route->defaults['prefix'])) {
                $prefix = Inflector::camelize($route->defaults['prefix']);
                if (!isset($route->defaults['plugin'])) {
                    $this->prefixes[$prefix] = true;
                } else {
                    $this->pluginPrefixes[$route->defaults['plugin']][$prefix] = true;
                }
            }
        }
    }

    /**
     * Delete unused ACOs.
     *
     * @param int $parentId Id of the parent node.
     * @param array $preservedItems list of items that will not be erased.
     * @return void
     */
    protected function _cleaner($parentId, $preservedItems = [])
    {
        $nodes = $this->Aco->find()->where(['parent_id' => $parentId]);
        $methodFlip = array_flip($preservedItems);
        foreach ($nodes as $node) {
            if (!isset($methodFlip[$node->alias])) {
                $crumbs = $this->Aco->find('path', ['for' => $node->id, 'order' => 'lft']);
                $path = null;
                foreach ($crumbs as $crumb) {
                    $path .= '/' . $crumb->alias;
                }
                $entity = $this->Aco->get($node->id);
                if ($this->Aco->delete($entity)) {
                    $this->out(__d('cake_acl', 'Deleted Aco node: <warning>{0}</warning> and all children', $path));
                }
            }
        }
    }

    /**
     * Updates the Aco Tree with all tables, columns and BelongsToMany psudo columns
     *
     * @param \Acl\Model\Entity\Aco $parent The parent node of the model side of the Aco Tree
     * @return void
     */
    protected function _processModels($parent)
    {
        $modelsRoot = $this->_checkNode($this->nodeTypeMap['models'], $this->nodeTypeMap['models'], $parent->id, $this->nodeTypeMap['models']);
        $this->_processTables($modelsRoot);
    }

    /**
     * Updates the Aco Tree with all tables.
     *
     * @param \Acl\Model\Entity\Aco $parent The parent node of the model side of the Aco Tree
     * @return void
     */
    protected function _processTables($parent)
    {
        $tables = $this->getTables();
        $this->foundACOs[$parent->id] = $this->_updateTables($parent, $tables);
    }

    /**
     * Get an Array of all the tables in the supplied connection
     * will halt the script if no tables are found.
     *
     * @return array Array of tables in the database.
     * @throws \InvalidArgumentException When connection class
     *   does not have a schemaCollection method.
     */
    public function getTables()
    {
        $db = ConnectionManager::get($this->connection);
        if (!method_exists($db, 'schemaCollection')) {
            throw new MissingConnectionException('Connections need to implement schemaCollection() to be used with bake.');
        }
        $schema = $db->schemaCollection();
        $tables = $schema->listTables();
        if (empty($tables)) {
            throw new MissingTableException('Your database does not have any tables.');
        }
        sort($tables);

        return $tables;
    }

    /**
     * Find the BelongsToMany relations and add them to associations list
     *
     * @param \Cake\ORM\Table $model Model instance being generated
     * @param array $associations Array of in-progress associations
     * @return array Associations with belongsToMany added in.
     */
    protected function _findBelongsToMany($model, array $associations)
    {
        $schema = $model->schema();
        $tableName = $schema->name();
        $foreignKey = $this->_modelKey($tableName);

        $tables = $this->getTables();
        foreach ($tables as $otherTable) {
            $assocTable = null;
            $offset = strpos($otherTable, $tableName . '_');
            $otherOffset = strpos($otherTable, '_' . $tableName);

            if ($offset !== false) {
                $assocTable = substr($otherTable, strlen($tableName . '_'));
            } elseif ($otherOffset !== false) {
                $assocTable = substr($otherTable, 0, $otherOffset);
            }
            if ($assocTable && in_array($assocTable, $tables)) {
                $associations['belongsToMany'][] = $this->_camelize($assocTable);
            }
        }

        return $associations;
    }

    /**
     * Updates a collection of tables.
     *
     * @param \Acl\Model\Entity\Aco $parent The parent node of the model side of the Aco Tree
     * @param array $tables List of found tables
     * @return array table names
     */
    protected function _updateTables($parent, $tables)
    {
        foreach ($tables as $table) {
            $path = [
                $this->nodeTypeMap['root'],
                $this->nodeTypeMap['models'],
                $table
            ];
            $path = implode('/', Hash::filter($path));
            $tableNode = $this->_checkNode($path, $table, $parent->id, $this->nodeTypeMap['table']);
            $this->_checkColumns($table, $tableNode);
        }

        return $tables;
    }

    /**
     * Check and Add/delete columns
     *
     * @param string $tableName The table to check
     * @param array $node The node to check.
     * @return void|bool
     */
    protected function _checkColumns($tableName, $node)
    {
        $name = Inflector::underscore($tableName);
        if (TableRegistry::exists($tableName)) {
            return TableRegistry::get($tableName);
        }
        $table = TableRegistry::get($tableName, [
            'name' => $tableName,
            'table' => $name,
            'connection' => ConnectionManager::get($this->connection)
        ]);
        $associations = $this->_findBelongsToMany($table, ['belongsToMany' => []]);
        $columns = $table->schema()->columns();
        $columnNames = [];
        foreach ($columns as $key => $column) {
            $path = [
                $this->nodeTypeMap['root'],
                $this->nodeTypeMap['models'],
                $tableName,
                $column
            ];
            $path = implode('/', Hash::filter($path));
            $this->_checkNode($path, $column, $node->id, $this->nodeTypeMap['column']);
            $columnNames[$key] = $column;
        }
        foreach ($associations['belongsToMany'] as $belongsToMany) {
            $column = Inflector::underscore($belongsToMany);
            $path = [
                $this->nodeTypeMap['root'],
                $this->nodeTypeMap['models'],
                $tableName,
                $column
            ];
            $path = implode('/', Hash::filter($path));
            $this->_checkNode($path, $column, $node->id, $this->nodeTypeMap['belongsToMany']);
            $columnNames[] = $column;
        }
        if ($this->_clean) {
            if ($this->_clean) {
                $this->_cleaner($node->id, $columnNames);
            }
        }

        return true;
    }

    /**
     * Sync the ARO table
     *
     * @param array $params An array of parameters
     * @return void
     */
    public function aroSync($params = [])
    {
        $this->_clean = true;
        $this->aroUpdate($params);
    }

    /**
     * Updates the Aco Tree with new controller actions.
     *
     * @param array $params An array of parameters
     * @return void|bool
     */
    public function aroUpdate($params = [])
    {
        $this->recover('aro');

        $groupsTable = TableRegistry::get(Configure::read('Acl.groupsModel'), [
            'connection' => ConnectionManager::get($this->connection)
        ]);
        $usersTable = TableRegistry::get(Configure::read('Acl.usersModel'), [
            'connection' => ConnectionManager::get($this->connection)
        ]);

        if (!TableRegistry::exists('Acl.Aros')) {
            $arosTable = TableRegistry::get('Acl.Aros', [
                'connection' => ConnectionManager::get($this->connection)
            ]);
        } else {
            $arosTable = TableRegistry::get('Acl.Aros');
        }

        $groups = $groupsTable->find('all')->orderAsc(Configure::read('Acl.groupsModel') . '.lft')->contain(['Parent' . Configure::read('Acl.groupsModel')]);
        foreach ($groups as $group) {
            $aro = $arosTable->find()->where(['Aros.model' => Configure::read('Acl.groupsModel'), 'Aros.foreign_key' => $group->id])->first();
            if (!isset($aro->id)) {
                $parentGroupProperty = 'parent_' . Inflector::underscore(Inflector::singularize(Configure::read('Acl.groupsModel')));
                if ($group->has($parentGroupProperty)) {
                    $parentAro = $arosTable->find()->where(['Aros.model' => Configure::read('Acl.groupsModel'), 'Aros.foreign_key' => $group->{$parentGroupProperty}->id])->first();
                }
                $aro = $arosTable->newEntity([
                    'parent_id' => isset($parentAro->id) ? $parentAro->id : null,
                    'model' => Configure::read('Acl.groupsModel'),
                    'foreign_key' => $group->id,
                    'alias' => $group->alias,
                ]);
                if ($arosTable->save($aro)) {
                    $this->out(__d('cake_acl', 'Saved Missing Group: <warning>{0}</warning>', $aro->alias));
                } else {
                    $this->out(__d('cake_acl', 'Failed to save Missing Group: <error>{0}</error>', $aro->alias));
                }
            } else {
                if (empty($aro->alias)) {
                    $aro->alias = $group->alias;
                    $arosTable->save($aro);
                }
                $this->out(__d('cake_acl', 'Group Exists: <success>{0}</success>', $aro->alias));
            }
        }

        $users = $usersTable->find('all');
        foreach ($users as $user) {
            $aro = $arosTable->find()->where(['Aros.model' => Configure::read('Acl.usersModel'), 'Aros.foreign_key' => $user->id])->first();
            if (!isset($aro->id)) {
                $parentAro = $arosTable->find()->where(['Aros.model' => Configure::read('Acl.groupsModel'), 'Aros.foreign_key' => $user->{Configure::read('Acl.userForeignKey')}])->first();
                $aro = $arosTable->newEntity([
                    'parent_id' => isset($parentAro->id) ? $parentAro->id : null,
                    'model' => Configure::read('Acl.usersModel'),
                    'foreign_key' => $user->id,
                    'alias' => $user->alias,
                ]);
                if ($arosTable->save($aro)) {
                    $this->out(__d('cake_acl', 'Saved Missing User: <warning>{0}</warning>', $aro->alias));
                } else {
                    $this->out(__d('cake_acl', 'Failed to save Missing User: <error>{0}</error>', $aro->alias));
                }
            } else {
                if (empty($aro->alias)) {
                    $aro->alias = $user->alias;
                    $arosTable->save($aro);
                }
                $this->out(__d('cake_acl', 'User Exists: <success>{0}</success>', $aro->alias));
            }
        }

        $this->out(__d('cake_acl', '<success>Aro Update Complete</success>'));

        return true;
    }
}
