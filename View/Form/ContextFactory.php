<?php
/**
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link          https://cakephp.org CakePHP(tm) Project
 * @since         3.5.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\View\Form;

use Cake\Collection\Collection;
use Cake\Datasource\EntityInterface;
use Cake\Form\Form;
use Cake\Http\ServerRequest;
use RuntimeException;
use Traversable;

/**
 * Factory for getting form context instance based on provided data.
 */
class ContextFactory
{
    /**
     * Context provider methods.
     *
     * @var array
     */
    protected $contextProviders = [];

    /**
     * Constructor.
     *
     * @param array $providers Array of provider callables. Each element should
     *   be of form `['type' => 'a-string', 'callable' => ..]`
     * @param bool $addDefaults Whether default providers should be added.
     */
    public function __construct(array $providers = [], $addDefaults = true)
    {
        if ($addDefaults) {
            $this->addDefaultProviders();
        }

        foreach ($providers as $provider) {
            $this->addProvider($provider['type'], $provider);
        }
    }

    /**
     * Add a new context type.
     *
     * Form context types allow FormHelper to interact with
     * data providers that come from outside CakePHP. For example
     * if you wanted to use an alternative ORM like Doctrine you could
     * create and connect a new context class to allow FormHelper to
     * read metadata from doctrine.
     *
     * @param string $type The type of context. This key
     *   can be used to overwrite existing providers.
     * @param callable $check A callable that returns an object
     *   when the form context is the correct type.
     * @return void
     */
    public function addProvider($type, callable $check)
    {
        $this->contextProviders = [$type => ['type' => $type, 'callable' => $check]]
            + $this->contextProviders;
    }

    /**
     * Find the matching context for the data.
     *
     * If no type can be matched a NullContext will be returned.
     *
     * @param \Cake\Http\ServerRequest $request Request instance.
     * @param array $data The data to get a context provider for.
     * @return \Cake\View\Form\ContextInterface Context provider.
     * @throws \RuntimeException when the context class does not implement the
     *   ContextInterface.
     */
    public function get(ServerRequest $request, array $data = [])
    {
        $data += ['entity' => null];

        foreach ($this->contextProviders as $provider) {
            $check = $provider['callable'];
            $context = $check($request, $data);
            if ($context) {
                break;
            }
        }
        if (!isset($context)) {
            $context = new NullContext($request, $data);
        }
        if (!($context instanceof ContextInterface)) {
            throw new RuntimeException(
                'Context objects must implement Cake\View\Form\ContextInterface'
            );
        }

        return $context;
    }

    /**
     * Add the default suite of context providers.
     *
     * @return void
     */
    protected function addDefaultProviders()
    {
        $this->addProvider('orm', function ($request, $data) {
            if (is_array($data['entity']) || $data['entity'] instanceof Traversable) {
                $pass = (new Collection($data['entity']))->first() !== null;
                if ($pass) {
                    return new EntityContext($request, $data);
                }
            }
            if ($data['entity'] instanceof EntityInterface) {
                return new EntityContext($request, $data);
            }
            if (is_array($data['entity']) && empty($data['entity']['schema'])) {
                return new EntityContext($request, $data);
            }
        });

        $this->addProvider('form', function ($request, $data) {
            if ($data['entity'] instanceof Form) {
                return new FormContext($request, $data);
            }
        });

        $this->addProvider('array', function ($request, $data) {
            if (is_array($data['entity']) && isset($data['entity']['schema'])) {
                return new ArrayContext($request, $data['entity']);
            }
        });
    }
}
