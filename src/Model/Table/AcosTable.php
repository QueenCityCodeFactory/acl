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
namespace Acl\Model\Table;

use Acl\Adapter\PermissionsTrait;
use Acl\Model\Table\AclNodesTable;
use Cake\Collection\Collection;
use Cake\Collection\ExtractTrait;
use Cake\Collection\Iterator\MapReduce;
use Cake\Core\App;
use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\ORM\Query;
use Cake\Utility\Hash;
use Cake\Validation\Validator;

/**
 * Access Control Object
 *
 */
class AcosTable extends AclNodesTable
{

    use ExtractTrait;
    use PermissionsTrait;

    /**
     * {@inheritDoc}
     *
     * @param array $config Config
     * @return void
     */
    public function initialize(array $config)
    {
        parent::initialize($config);
        $this->alias('Acos');
        $this->table('acos');
        $this->addBehavior('Tree');

        $this->belongsToMany('Aros', [
            'through' => App::className('Acl.PermissionsTable', 'Model/Table'),
            'className' => App::className('Acl.ArosTable', 'Model/Table'),
        ]);
        $this->hasMany('AcoChildren', [
            'className' => App::className('Acl.AcosTable', 'Model/Table'),
            'foreignKey' => 'parent_id'
        ]);
        $this->hasMany('Permissions', [
            'className' => App::className('Acl.PermissionsTable', 'Model/Table'),
            'foreignKey' => 'aco_id'
        ]);
        $this->entityClass(App::className('Acl.Aco', 'Model/Entity'));
    }

    /**
     * Default validation rules.
     *
     * @param \Cake\Validation\Validator $validator Validator instance.
     * @return \Cake\Validation\Validator
     */
    public function validationDefault(Validator $validator)
    {
        $validator
            ->requirePresence('name', 'update')
            ->notEmpty('name');

        return $validator;
    }

    /**
     * Results for this finder will be a nested array, and is appropriate if you want
     * to use the parent_id field of your model data to build nested results.
     *
     * Values belonging to a parent row based on their parent_id value will be
     * recursively nested inside the parent row values using the `children` property
     *
     * You can customize what fields are used for nesting results, by default the
     * primary key and the `parent_id` fields are used. If you wish to change
     * these defaults you need to provide the keys `keyField` or `parentField` in
     * `$options`:
     *
     * ```
     * $table->find('treeview', [
     *  'keyField' => 'id',
     *  'parentField' => 'ancestor_id'
     * ]);
     * ```
     *
     * @param \Cake\ORM\Query $query The query to find with
     * @param array $options The options to find with
     * @return \Cake\ORM\Query The query builder
     */
    public function findTreeview(Query $query, array $options)
    {
        $options += [
            'keyField' => $this->primaryKey(),
            'parentField' => 'parent_id',
        ];

        if (isset($options['idField'])) {
            $options['keyField'] = $options['idField'];
            unset($options['idField']);
            trigger_error('Option "idField" is deprecated, use "keyField" instead.', E_USER_WARNING);
        }

        $options = $this->_setFieldMatchers($options, ['keyField', 'parentField']);

        return $query->formatResults(function ($results) use ($options) {

            $parents = [];
            $idPath = $this->_propertyExtractor($options['keyField']);
            $parentPath = $this->_propertyExtractor($options['parentField']);
            $isObject = true;

            $mapper = function ($row, $key, $mapReduce) use (&$parents, $idPath, $parentPath) {
                $id = $idPath($row, $key);
                $parentId = $parentPath($row, $key);
                $parents[$id] =& $row;
                $mapReduce->emitIntermediate($id, $parentId);
            };

            $reducer = function ($values, $key, $mapReduce) use (&$parents, &$isObject) {
                static $foundOutType = false;
                if (!$foundOutType) {
                    $isObject = is_object(current($parents));
                    $foundOutType = true;
                }
                if (empty($key) || !isset($parents[$key])) {
                    foreach ($values as $id) {
                        $parents[$id] = $isObject ? $parents[$id] : new ArrayIterator($parents[$id], 1);
                        $mapReduce->emit($parents[$id]);
                    }

                    return;
                }

                $children = [];
                foreach ($values as $id) {
                    $children[] =& $parents[$id];
                }
                if (!empty($children)) {
                    $parents[$key]['nodes'] = $children;
                }
            };

            return (new Collection(new MapReduce($results->unwrap(), $mapper, $reducer)))
                ->map(function ($value) use (&$isObject) {
                    return $isObject ? $value : $value->getArrayCopy();
                });
        });
    }

    /**
     * Results for this finder will be a nested array, and is appropriate if you want
     * to use the parent_id field of your model data to build nested results.
     *
     * Values belonging to a parent row based on their parent_id value will be
     * recursively nested inside the parent row values using the `children` property
     *
     * You can customize what fields are used for nesting results, by default the
     * primary key and the `parent_id` fields are used. If you wish to change
     * these defaults you need to provide the keys `keyField` or `parentField` in
     * `$options`:
     *
     * ```
     * $table->find('permissions', [
     *  'keyField' => 'id',
     *  'parentField' => 'ancestor_id'
     * ]);
     * ```
     *
     * @param \Cake\ORM\Query $query The query to find with
     * @param array $options The options to find with
     * @return \Cake\ORM\Query The query builder
     */
    public function findPermissions(Query $query, array $options)
    {
        if (empty($options['aro'])) {
            throw new RecordNotFoundException('Missing ARO!');
        }

        $options += [
            'keyField' => $this->primaryKey(),
            'parentField' => 'parent_id',
        ];

        if (isset($options['idField'])) {
            $options['keyField'] = $options['idField'];
            unset($options['idField']);
            trigger_error('Option "idField" is deprecated, use "keyField" instead.', E_USER_WARNING);
        }

        $options = $this->_setFieldMatchers($options, ['keyField', 'parentField']);

        return $query->formatResults(function ($results) use ($options) {

            $parents = [];
            $idPath = $this->_propertyExtractor($options['keyField']);
            $parentPath = $this->_propertyExtractor($options['parentField']);
            $isObject = true;

            $mapper = function ($row, $key, $mapReduce) use (&$parents, $idPath, $parentPath, $options) {

                $path = $this->find('path', ['for' => $row['id']])->select(['alias'])->hydrate(false);
                $aco = implode('/', Hash::extract($path->toArray(), '{n}.alias'));

                $row['permissions'] = $this->check($options['aro'], $aco);
                $row['path'] = $aco;
                $row['tags'] = [];

                if (in_array($row['type'], ['models', 'table', 'column'])) {
                    if ($this->check($options['aro'], $aco, 'update')) {
                        $row['tags'][] = 'update';
                        $row['permissions'] = true;
                    } elseif ($this->check($options['aro'], $aco, 'read')) {
                        $row['tags'][] = 'read';
                        $row['permissions'] = true;
                    }
                }

                $row['color'] = 'red';
                if ($row['permissions'] === true) {
                    $row['color'] = 'green';
                }

                $id = $idPath($row, $key);
                $parentId = $parentPath($row, $key);
                $parents[$id] =& $row;
                $mapReduce->emitIntermediate($id, $parentId);
            };

            $reducer = function ($values, $key, $mapReduce) use (&$parents, &$isObject) {
                static $foundOutType = false;
                if (!$foundOutType) {
                    $isObject = is_object(current($parents));
                    $foundOutType = true;
                }
                if (empty($key) || !isset($parents[$key])) {
                    foreach ($values as $id) {
                        $parents[$id] = $isObject ? $parents[$id] : new ArrayIterator($parents[$id], 1);
                        $mapReduce->emit($parents[$id]);
                    }

                    return;
                }

                $children = [];
                foreach ($values as $id) {
                    $children[] =& $parents[$id];
                }
                if (!empty($children)) {
                    $parents[$key]['nodes'] = $children;
                }
            };

            return (new Collection(new MapReduce($results->unwrap(), $mapper, $reducer)))
                ->map(function ($value) use (&$isObject) {
                    return $isObject ? $value : $value->getArrayCopy();
                });
        });
    }
}
