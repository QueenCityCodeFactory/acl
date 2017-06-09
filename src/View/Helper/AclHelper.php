<?php
/**
 * QueenCityCodeFactory(tm) : Web application developers (http://queencitycodefactory.com)
 * Copyright (c) Queen City Code Factory, Inc. (http://queencitycodefactory.com)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Queen City Code Factory, Inc. (http://queencitycodefactory.com)
 * @link          http://queencitycodefactory.com
 * @license       http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace Acl\View\Helper;

use Acl\AclInterface;
use Cake\Core\App;
use Cake\Core\Configure;
use Cake\Core\Exception\Exception;
use Cake\ORM\Exception\MissingTableClassException;
use Cake\Routing\Router;
use Cake\Utility\Hash;
use Cake\Utility\Inflector;
use Cake\View\Helper;
use Cake\View\StringTemplateTrait;
use Cake\View\View;

/**
 * Acl Helper Class
 */
class AclHelper extends Helper
{

    use StringTemplateTrait;

    /**
     * Instance of an ACL class
     *
     * @var AclInterface
     */
    protected $Acl = null;

    /**
     * Aro object
     *
     * @var string
     */
    public $Aro;

    /**
     * Aco object
     *
     * @var string
     */
    public $Aco;

    /**
     * User model
     *
     * @var string
     */
    public $userModel = 'Users';

    /**
     * Primary Key for user model
     *
     * @var string
     */
    public $primaryKey = 'id';

    /**
     * The session key name where the record of the current user is stored. Default
     * key is "Auth.User".
     *
     * @var string
     */
    public $sessionKey = 'Auth.User';

    /**
     * Other helpers used by AclHelper
     *
     * @var array
     */
    public $helpers = ['Url', 'Html', 'Form'];

    /**
     * Default config for the helper.
     *
     * @var array
     */
    protected $_defaultConfig = [
        'templates' => [
            'tag' => '<{{tag}}{{attrs}}>{{content}}</{{tag}}>',
        ],
    ];

    /**
     * Construct the widgets and binds the default context providers
     * Will return an instance of the correct ACL class as defined in `Configure::read('Acl.classname')`
     *
     * @param \Cake\View\View $View The View this helper is being attached to.
     * @param array $config Configuration settings for the helper.
     * @throws \Cake\Core\Exception\Exception when Acl.classname could not be loaded.
     */
    public function __construct(View $View, array $config = [])
    {
        parent::__construct($View, $config);
        $className = $name = Configure::read('Acl.classname');
        if (!class_exists($className)) {
            $className = App::className('Acl.' . $name, 'Adapter');
            if (!$className) {
                throw new Exception(sprintf('Could not find {0}.', [$name]));
            }
        }
        $this->adapter($className);
    }

    /**
     * Sets or gets the Adapter object currently in the AclHelper.
     *
     * `$this->Acl->adapter();` will get the current adapter class while
     * `$this->Acl->adapter($obj);` will set the adapter class
     *
     * Will call the initialize method on the adapter if setting a new one.
     *
     * @param AclInterface|string $adapter Instance of AclInterface or a string name of the class to use. (optional)
     * @return AclInterface|void either null, or the adapter implementation.
     * @throws \Cake\Core\Exception\Exception when the given class is not an instance of AclInterface
     */
    public function adapter($adapter = null)
    {
        if ($adapter) {
            if (is_string($adapter)) {
                $adapter = new $adapter();
            }
            if (!$adapter instanceof AclInterface) {
                throw new Exception('AclHelper adapters must implement AclInterface');
            }
            $this->Acl = $adapter;
            $this->Acl->initialize($this);

            return;
        }

        return $this->Acl;
    }

    /**
     * Pass-thru function for ACL check instance. Check methods
     * are used to check whether or not an ARO can access an ACO
     *
     * @param array|string|Model $aro ARO The requesting object identifier. See `AclNode::node()` for possible formats
     * @param array|string|Model $aco ACO The controlled object identifier. See `AclNode::node()` for possible formats
     * @param string $action Action (defaults to *)
     * @return bool Success
     */
    public function check($aro, $aco, $action = "*")
    {
        return $this->Acl->check($aro, $aco, $action);
    }

    /**
     * Check Field - Checks ACL to see if ARO can Access ACO
     * @param string $path The ACO Path
     * @param string $action Action (defaults to *)
     * @return bool Success
     */
    public function checkField($path, $action = "*")
    {
        return (bool)$this->Acl->check([$this->userModel => $this->user()], $path, $action);
    }

    /**
     * Creates an HTML link.
     *
     * If $url starts with "http://" this is treated as an external link. Else,
     * it is treated as a path to controller/action and parsed with the
     * UrlHelper::url() method.
     *
     * If the $url is empty, $title is used instead.
     *
     * ### Options
     *
     * - `escape` Set to false to disable escaping of title and attributes.
     * - `escapeTitle` Set to false to disable escaping of title. Takes precedence
     *   over value of `escape`)
     * - `confirm` JavaScript confirmation message.
     * - `default` What to output if acl check is false
     *
     * @param string $title The content to be wrapped by <a> tags.
     * @param string|array|null $url Cake-relative URL or array of URL parameters, or
     *   external URL (starts with http://)
     * @param array $options Array of options and HTML attributes.
     * @return bool|string An `<a />` element.
     * @link http://book.cakephp.org/3.0/en/views/helpers/html.html#creating-links
     */
    public function link($title, $url = null, array $options = [])
    {
        $default = false;
        if (isset($options['default']) && $options['default'] === true) {
            $default = $title;
        } elseif (isset($options['default']) && $options['default'] !== false) {
            $default = $options['default'];
        }
        unset($options['default']);

        if ($this->check([$this->userModel => $this->user()], $this->buildAcoPath($url))) {
            return $this->Html->link($title, $url, $options);
        }

        return $default;
    }

    /**
     * Btn Link to auto escape a link used as a button
     *
     * @param string $title The content to be wrapped by <a> tags.
     * @param string|array|null $url Cake-relative URL or array of URL parameters, or
     *   external URL (starts with http://)
     * @param array $options Array of options and HTML attributes.
     * @return bool|string An `<a />` element.
     */
    public function btnLink($title, $url, array $options = [])
    {
        $options['default'] = false;

        return $this->link($title, $url, $options);
    }

    /**
     * Creates an HTML link, but access the URL using the method you specify
     * (defaults to POST). Requires javascript to be enabled in browser.
     *
     * This method creates a `<form>` element. So do not use this method inside an
     * existing form. Instead you should add a submit button using FormHelper::submit()
     *
     * ### Options:
     *
     * - `data` - Array with key/value to pass in input hidden
     * - `method` - Request method to use. Set to 'delete' to simulate
     *   HTTP/1.1 DELETE request. Defaults to 'post'.
     * - `confirm` - Confirm message to show.
     * - `block` - Set to true to append form to view block "postLink" or provide
     *   custom block name.
     * - Other options are the same of HtmlHelper::link() method.
     * - The option `onclick` will be replaced.
     *
     * @param string $title The content to be wrapped by <a> tags.
     * @param string|array $url Cake-relative URL or array of URL parameters, or
     *   external URL (starts with http://)
     * @param array $options Array of HTML attributes.
     * @return string An `<a />` element.
     * @link http://book.cakephp.org/3.0/en/views/helpers/form.html#creating-standalone-buttons-and-post-links
     */
    public function postLink($title, $url = null, array $options = [])
    {
        $default = false;
        if (isset($options['default'])) {
            $default = $options['default'];
        }

        unset($options['default']);
        if ($this->check([$this->userModel => $this->user()], $this->buildAcoPath($url))) {
            return $this->Form->postLink($title, $url, $options);
        }

        return $default;
    }

    /**
     * Creates an HTML link wrapped in a tag
     *
     * If $url starts with "http://" this is treated as an external link. Else,
     * it is treated as a path to controller/action and parsed with the
     * UrlHelper::url() method.
     *
     * If the $url is empty, $title is used instead.
     *
     * ### Options
     *
     * - `escape` Set to false to disable escaping of title and attributes.
     * - `escapeTitle` Set to false to disable escaping of title. Takes precedence
     *   over value of `escape`)
     * - `confirm` JavaScript confirmation message.
     *
     * @param string $title The content to be wrapped by <a> tags.
     * @param string|array|null $url Cake-relative URL or array of URL parameters, or
     *   external URL (starts with http://)
     * @param array $options Array of options and HTML attributes.
     * @return string An `<a />` element.
     * @link http://book.cakephp.org/3.0/en/views/helpers/html.html#creating-links
     */
    public function tagLink($title, $url = null, array $options = [])
    {
        $default = false;
        if (!isset($options['escape'])) {
            $options['escape'] = false;
        }
        if (isset($options['default'])) {
            $default = $options['default'];
        }
        unset($options['default']);

        if ($this->check([$this->userModel => $this->user()], $this->buildAcoPath($url))) {
            return $this->Html->tagLink($title, $url, $options);
        }

        return $default;
    }

    /**
     * Get the current user.
     *
     * Will prefer the user cache over sessions. The user cache is primarily used for
     * stateless authentication. For stateful authentication,
     * cookies + sessions will be used.
     *
     * @param string $key field to retrieve. Leave null to get entire User record
     * @return array|null Either User record or null if no user is logged in.
     * @link http://book.cakephp.org/3.0/en/controllers/components/authentication.html#accessing-the-logged-in-user
     */
    protected function user($key = null)
    {
        if ($this->sessionKey && $this->request->session()->check($this->sessionKey)) {
            $user = $this->request->session()->read($this->sessionKey);
        } else {
            return null;
        }
        if ($key === null) {
            return $user;
        }

        return Hash::get($user, $key);
    }

    /**
     * Takes url and converts into Aco Path for acl check
     *
     * @param string|array $url standard Router::url() style params
     * @return string string of aco path
     */
    protected function buildAcoPath($url)
    {
        return $this->action(Router::parse($this->Url->build($url)));
    }

    /**
     * Get the action path for a given request. Primarily used by authorize objects
     * that need to get information about the plugin, controller, and action being invoked.
     *
     * @param array $request The request a path is needed for.
     * @param string $path Path
     * @return string The action path for the given request.
     */
    protected function action($request, $path = '/:plugin/:prefix/:controller/:action')
    {
        $search = [
            ':plugin/',
            ':prefix/',
            ':controller',
            ':action'
        ];
        $replace = [
            empty($request['plugin']) ? null : preg_replace('/\//', '\\', Inflector::camelize($request['plugin'])) . '/',
            empty($request['prefix']) ? null : $request['prefix'] . '/',
            Inflector::camelize($request['controller']),
            $request['action']
        ];

        return trim(str_replace('//', '/', str_replace($search, $replace, Configure::read('Acl.actionPath') . $path)), '/');
    }
}
