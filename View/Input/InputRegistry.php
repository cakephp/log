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
 * @since         CakePHP(tm) v3.0
 * @license       http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\View\Input;

use Cake\Core\App;
use Cake\View\Input\InputInterface;
use Cake\View\StringTemplate;
use \ReflectionClass;

/**
 * A registry/factory for input widgets.
 *
 * Can be used by helpers/view logic to build form widgets
 * and other HTML widgets.
 *
 * This class handles the mapping between names and concrete classes.
 * It also has a basic name based dependency resolver that allows
 * widgets to depend on each other.
 *
 * Each widget should expect a StringTemplate instance as their first
 * argument. All other dependencies will be included after.
 */
class InputRegistry {

/**
 * Array of widgets + widget configuration.
 *
 * @var array
 */
	protected $_widgets = [
		'button' => ['Cake\View\Input\Button'],
		'checkbox' => ['Cake\View\Input\Checkbox'],
		'file' => ['Cake\View\Input\File'],
		'label' => ['Cake\View\Input\Label'],
		'multicheckbox' => ['Cake\View\Input\MultiCheckbox', 'label'],
		'radio' => ['Cake\View\Input\Radio', 'label'],
		'select' => ['Cake\View\Input\SelectBox'],
		'textarea' => ['Cake\View\Input\Textarea'],
		'_default' => ['Cake\View\Input\Basic'],
	];

/**
 * Templates to use.
 *
 * @var Cake\View\StringTemplate
 */
	protected $_templates;

/**
 * Constructor
 *
 * @param StringTemplate $templates Templates instance to use.
 * @param array $widgets See add() method for more information.
 */
	public function __construct(StringTemplate $templates, array $widgets = []) {
		$this->_templates = $templates;
		if (!empty($widgets)) {
			$this->add($widgets);
		}
	}

/**
 * Adds or replaces existing widget instances/configuration with new ones.
 *
 * Widget arrays can either be descriptions or instances. For example:
 *
 * {{{
 * $registry->add([
 *   'label' => new MyLabel($templates),
 *   'checkbox' => ['Fancy.MyCheckbox', 'label']
 * ]);
 * }}}
 *
 * The above shows how to define widgets as instances or as
 * descriptions including dependencies. Classes can be defined
 * with plugin notation, or fully namespaced class names.
 *
 * @param array $widgets Array of widgets to use.
 * @return void
 */
	public function add(array $widgets) {
		$this->_widgets = $widgets + $this->_widgets;
	}

/**
 * Get a widget.
 *
 * Will either fetch an already created widget, or create a new instance
 * if the widget has been defined. If the widget is undefined an instance of
 * the `_default` widget will be returned. An exception will be thrown if
 * the `_default` widget is undefined.
 *
 * @param string $name The widget name to get.
 * @return mixed InputInterface widget interface class.
 * @throws \RuntimeException when widget is undefined.
 */
	public function get($name) {
		if (!isset($this->_widgets[$name]) && empty($this->_widgets['_default'])) {
			throw new \RuntimeException(sprintf('Unknown widget "%s"', $name));
		}
		if (!isset($this->_widgets[$name])) {
			$name = '_default';
		}
		$this->_widgets[$name] = $this->_resolveWidget($this->_widgets[$name]);
		return $this->_widgets[$name];
	}

/**
 * Clear the registry and reset the widgets.
 *
 * @return void
 */
	public function clear() {
		$this->_widgets = [];
	}

/**
 * Resolves a widget spec into an instance.
 *
 * @param mixed $widget The widget to get
 * @return InputInterface
 * @throws \RuntimeException when class cannot be loaded or does not
 *   implement InputInterface.
 */
	protected function _resolveWidget($widget) {
		if (is_object($widget)) {
			return $widget;
		}
		$class = array_shift($widget);
		$className = App::classname($class, 'View/Input');
		if ($className === false || !class_exists($className)) {
			throw new \RuntimeException(sprintf('Unable to locate widget class "%s"', $class));
		}
		if (count($widget)) {
			$reflection = new ReflectionClass($className);
			$arguments = [$this->_templates];
			foreach ($widget as $requirement) {
				$arguments[] = $this->get($requirement);
			}
			$instance = $reflection->newInstanceArgs($arguments);
		} else {
			$instance = new $className($this->_templates);
		}
		if (!($instance instanceof InputInterface)) {
			throw new \RuntimeException(sprintf('"%s" does not implement the InputInterface', $className));
		}
		return $instance;
	}

}
