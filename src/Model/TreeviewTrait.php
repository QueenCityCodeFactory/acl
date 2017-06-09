<?php
namespace App\Model;

use Cake\Collection\Collection;
use Cake\Collection\ExtractTrait;
use Cake\Collection\Iterator\MapReduce;
use Cake\ORM\Query;

/**
 * Treeview Trait
 */
trait TreeviewTrait
{

    use ExtractTrait;

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
     *  'parentField' => 'ancestor_id',
     *  'nestingKey' => 'nodes'
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
            'nestingKey' => 'nodes'
        ];

        $options = $this->_setFieldMatchers($options, ['keyField', 'parentField', 'nestingKey']);

        return $query->formatResults(function ($results) use ($options) {
            $parents = [];
            $idPath = $this->_propertyExtractor($options['keyField']);
            $parentPath = $this->_propertyExtractor($options['parentField']);
            $nestingKey = $options['nestingKey'];
            $isObject = true;

            $mapper = function ($row, $key, $mapReduce) use (&$parents, $idPath, $parentPath, $nestingKey) {
                $row[$nestingKey] = [];
                $id = $idPath($row, $key);
                $parentId = $parentPath($row, $key);
                $parents[$id] =& $row;
                $mapReduce->emitIntermediate($id, $parentId);
            };

            $reducer = function ($values, $key, $mapReduce) use (&$parents, &$isObject, $nestingKey) {
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
                    return null;
                }

                $children = [];
                foreach ($values as $id) {
                    $children[] =& $parents[$id];
                }
                if (!empty($children)) {
                    $parents[$key][$nestingKey] = $children;
                }
            };

            return (new Collection(new MapReduce($results->unwrap(), $mapper, $reducer)))
                ->map(function ($value) use (&$isObject) {
                    return $isObject ? $value : $value->getArrayCopy();
                });
        });
    }
}
