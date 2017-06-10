<?php
/**
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
use Cake\Core\Configure;
use Cake\Event\EventManager;

if (!Configure::read('Acl.classname')) {
    Configure::write('Acl.classname', 'CachedDbAcl');
}
if (!Configure::read('Acl.database')) {
    Configure::write('Acl.database', 'default');
}
if (!Configure::read('Acl.usersModel')) {
    Configure::write('Acl.usersModel', 'Users');
}
if (!Configure::read('Acl.groupsModel')) {
    Configure::write('Acl.groupsModel', 'Groups');
}
if (!Configure::read('Acl.userForeignKey')) {
    Configure::write('Acl.userForeignKey', 'group_id');
}
if (!Configure::read('Acl.cacheConfig') && Configure::read('Acl.classname') === 'CachedDbAcl') {
    Configure::write('Acl.cacheConfig', [
        'className' => 'File',
        'prefix' => 'myapp_cake_acl_',
        'path' => CACHE . 'persistent/',
        'serialize' => true,
        'duration' => '+1 years',
        'url' => env('CACHE_CAKEACL_URL', null),
    ]);
}
