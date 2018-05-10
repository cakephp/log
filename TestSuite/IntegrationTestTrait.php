<?php
/**
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @since         3.7.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\TestSuite;

use Cake\Core\Configure;
use Cake\Database\Exception as DatabaseException;
use Cake\Http\ServerRequest;
use Cake\Http\Session;
use Cake\Routing\Router;
use Cake\TestSuite\Constraint\Response\ContentType;
use Cake\TestSuite\Constraint\Response\CookieEncryptedEquals;
use Cake\TestSuite\Constraint\Response\CookieNotSet;
use Cake\TestSuite\Constraint\Response\CookieSet;
use Cake\TestSuite\Constraint\Response\CookieEquals;
use Cake\TestSuite\Constraint\Response\Header;
use Cake\TestSuite\Constraint\Response\HeaderContains;
use Cake\TestSuite\Constraint\Response\StatusCode;
use Cake\TestSuite\Constraint\Response\StatusError;
use Cake\TestSuite\Constraint\Response\StatusFailure;
use Cake\TestSuite\Constraint\Response\StatusOk;
use Cake\TestSuite\Constraint\Response\StatusSuccess;
use Cake\TestSuite\Constraint\View\TemplateFileEquals;
use Cake\TestSuite\Constraint\View\LayoutFileEquals;
use Cake\TestSuite\Stub\TestExceptionRenderer;
use Cake\Utility\CookieCryptTrait;
use Cake\Utility\Hash;
use Cake\Utility\Security;
use Cake\Utility\Text;
use Cake\View\Helper\SecureFieldTokenTrait;
use Exception;
use LogicException;
use PHPUnit\Exception as PhpunitException;
use PHPUnit\Framework\Constraint\IsEmpty;
use PHPUnit\Framework\Constraint\IsEqual;
use PHPUnit\Framework\Constraint\LogicalNot;
use PHPUnit\Framework\Constraint\RegularExpression;
use PHPUnit\Framework\Constraint\StringContains;

/**
 * A trait intended to make integration tests of your controllers easier.
 *
 * This test class provides a number of helper methods and features
 * that make dispatching requests and checking their responses simpler.
 * It favours full integration tests over mock objects as you can test
 * more of your code easily and avoid some of the maintenance pitfalls
 * that mock objects create.
 */
trait IntegrationTestTrait
{
    use CookieCryptTrait;
    use SecureFieldTokenTrait;

    /**
     * Track whether or not tests are run against
     * the PSR7 HTTP stack.
     *
     * @var bool
     */
    protected $_useHttpServer = false;

    /**
     * The customized application class name.
     *
     * @var string|null
     */
    protected $_appClass;

    /**
     * The customized application constructor arguments.
     *
     * @var array|null
     */
    protected $_appArgs;

    /**
     * The data used to build the next request.
     *
     * @var array
     */
    protected $_request = [];

    /**
     * The response for the most recent request.
     *
     * @var \Cake\Http\Response|null
     */
    protected $_response;

    /**
     * The exception being thrown if the case.
     *
     * @var \Exception|null
     */
    protected $_exception;

    /**
     * Session data to use in the next request.
     *
     * @var array
     */
    protected $_session = [];

    /**
     * Cookie data to use in the next request.
     *
     * @var array
     */
    protected $_cookie = [];

    /**
     * The controller used in the last request.
     *
     * @var \Cake\Controller\Controller|null
     */
    protected $_controller;

    /**
     * The last rendered view
     *
     * @var string|null
     */
    protected $_viewName;

    /**
     * The last rendered layout
     *
     * @var string|null
     */
    protected $_layoutName;

    /**
     * The session instance from the last request
     *
     * @var \Cake\Http\Session|null
     */
    protected $_requestSession;

    /**
     * Boolean flag for whether or not the request should have
     * a SecurityComponent token added.
     *
     * @var bool
     */
    protected $_securityToken = false;

    /**
     * Boolean flag for whether or not the request should have
     * a CSRF token added.
     *
     * @var bool
     */
    protected $_csrfToken = false;

    /**
     * Boolean flag for whether or not the request should re-store
     * flash messages
     *
     * @var bool
     */
    protected $_retainFlashMessages = false;

    /**
     * Stored flash messages before render
     *
     * @var null|array
     */
    protected $_flashMessages;

    /**
     *
     * @var null|string
     */
    protected $_cookieEncryptionKey;

    /**
     * Auto-detect if the HTTP middleware stack should be used.
     *
     * @return void
     */
    public function setUp()
    {
        parent::setUp();
        $namespace = Configure::read('App.namespace');
        $this->_useHttpServer = class_exists($namespace . '\Application');
    }

    /**
     * Clears the state used for requests.
     *
     * @return void
     */
    public function tearDown()
    {
        parent::tearDown();
        $this->_request = [];
        $this->_session = [];
        $this->_cookie = [];
        $this->_response = null;
        $this->_exception = null;
        $this->_controller = null;
        $this->_viewName = null;
        $this->_layoutName = null;
        $this->_requestSession = null;
        $this->_appClass = null;
        $this->_appArgs = null;
        $this->_securityToken = false;
        $this->_csrfToken = false;
        $this->_retainFlashMessages = false;
        $this->_useHttpServer = false;
    }

    /**
     * Toggle whether or not you want to use the HTTP Server stack.
     *
     * @param bool $enable Enable/disable the usage of the HTTP Stack.
     * @return void
     */
    public function useHttpServer($enable)
    {
        $this->_useHttpServer = (bool)$enable;
    }

    /**
     * Configure the application class to use in integration tests.
     *
     * Combined with `useHttpServer()` to customize the class name and constructor arguments
     * of your application class.
     *
     * @param string $class The application class name.
     * @param array|null $constructorArgs The constructor arguments for your application class.
     * @return void
     */
    public function configApplication($class, $constructorArgs)
    {
        $this->_appClass = $class;
        $this->_appArgs = $constructorArgs;
    }

    /**
     * Calling this method will enable a SecurityComponent
     * compatible token to be added to request data. This
     * lets you easily test actions protected by SecurityComponent.
     *
     * @return void
     */
    public function enableSecurityToken()
    {
        $this->_securityToken = true;
    }

    /**
     * Calling this method will add a CSRF token to the request.
     *
     * Both the POST data and cookie will be populated when this option
     * is enabled. The default parameter names will be used.
     *
     * @return void
     */
    public function enableCsrfToken()
    {
        $this->_csrfToken = true;
    }

    /**
     * Calling this method will re-store flash messages into the test session
     * after being removed by the FlashHelper
     *
     * @return void
     */
    public function enableRetainFlashMessages()
    {
        $this->_retainFlashMessages = true;
    }

    /**
     * Configures the data for the *next* request.
     *
     * This data is cleared in the tearDown() method.
     *
     * You can call this method multiple times to append into
     * the current state.
     *
     * @param array $data The request data to use.
     * @return void
     */
    public function configRequest(array $data)
    {
        $this->_request = $data + $this->_request;
    }

    /**
     * Sets session data.
     *
     * This method lets you configure the session data
     * you want to be used for requests that follow. The session
     * state is reset in each tearDown().
     *
     * You can call this method multiple times to append into
     * the current state.
     *
     * @param array $data The session data to use.
     * @return void
     */
    public function session(array $data)
    {
        $this->_session = $data + $this->_session;
    }

    /**
     * Sets a request cookie for future requests.
     *
     * This method lets you configure the session data
     * you want to be used for requests that follow. The session
     * state is reset in each tearDown().
     *
     * You can call this method multiple times to append into
     * the current state.
     *
     * @param string $name The cookie name to use.
     * @param mixed $value The value of the cookie.
     * @return void
     */
    public function cookie($name, $value)
    {
        $this->_cookie[$name] = $value;
    }

    /**
     * Returns the encryption key to be used.
     *
     * @return string
     */
    protected function _getCookieEncryptionKey()
    {
        if (isset($this->_cookieEncryptionKey)) {
            return $this->_cookieEncryptionKey;
        }

        return Security::getSalt();
    }

    /**
     * Sets a encrypted request cookie for future requests.
     *
     * The difference from cookie() is this encrypts the cookie
     * value like the CookieComponent.
     *
     * @param string $name The cookie name to use.
     * @param mixed $value The value of the cookie.
     * @param string|bool $encrypt Encryption mode to use.
     * @param string|null $key Encryption key used. Defaults
     *   to Security.salt.
     * @return void
     * @see \Cake\Utility\CookieCryptTrait::_encrypt()
     */
    public function cookieEncrypted($name, $value, $encrypt = 'aes', $key = null)
    {
        $this->_cookieEncryptionKey = $key;
        $this->_cookie[$name] = $this->_encrypt($value, $encrypt);
    }

    /**
     * Performs a GET request using the current request data.
     *
     * The response of the dispatched request will be stored as
     * a property. You can use various assert methods to check the
     * response.
     *
     * @param string|array $url The URL to request.
     * @return void
     */
    public function get($url)
    {
        $this->_sendRequest($url, 'GET');
    }

    /**
     * Performs a POST request using the current request data.
     *
     * The response of the dispatched request will be stored as
     * a property. You can use various assert methods to check the
     * response.
     *
     * @param string|array $url The URL to request.
     * @param array $data The data for the request.
     * @return void
     */
    public function post($url, $data = [])
    {
        $this->_sendRequest($url, 'POST', $data);
    }

    /**
     * Performs a PATCH request using the current request data.
     *
     * The response of the dispatched request will be stored as
     * a property. You can use various assert methods to check the
     * response.
     *
     * @param string|array $url The URL to request.
     * @param array $data The data for the request.
     * @return void
     */
    public function patch($url, $data = [])
    {
        $this->_sendRequest($url, 'PATCH', $data);
    }

    /**
     * Performs a PUT request using the current request data.
     *
     * The response of the dispatched request will be stored as
     * a property. You can use various assert methods to check the
     * response.
     *
     * @param string|array $url The URL to request.
     * @param array $data The data for the request.
     * @return void
     */
    public function put($url, $data = [])
    {
        $this->_sendRequest($url, 'PUT', $data);
    }

    /**
     * Performs a DELETE request using the current request data.
     *
     * The response of the dispatched request will be stored as
     * a property. You can use various assert methods to check the
     * response.
     *
     * @param string|array $url The URL to request.
     * @return void
     */
    public function delete($url)
    {
        $this->_sendRequest($url, 'DELETE');
    }

    /**
     * Performs a HEAD request using the current request data.
     *
     * The response of the dispatched request will be stored as
     * a property. You can use various assert methods to check the
     * response.
     *
     * @param string|array $url The URL to request.
     * @return void
     */
    public function head($url)
    {
        $this->_sendRequest($url, 'HEAD');
    }

    /**
     * Performs an OPTIONS request using the current request data.
     *
     * The response of the dispatched request will be stored as
     * a property. You can use various assert methods to check the
     * response.
     *
     * @param string|array $url The URL to request.
     * @return void
     */
    public function options($url)
    {
        $this->_sendRequest($url, 'OPTIONS');
    }

    /**
     * Creates and send the request into a Dispatcher instance.
     *
     * Receives and stores the response for future inspection.
     *
     * @param string|array $url The URL
     * @param string $method The HTTP method
     * @param array|null $data The request data.
     * @return void
     * @throws \Exception
     */
    protected function _sendRequest($url, $method, $data = [])
    {
        $dispatcher = $this->_makeDispatcher();
        try {
            $request = $this->_buildRequest($url, $method, $data);
            $response = $dispatcher->execute($request);
            $this->_requestSession = $request['session'];
            if ($this->_retainFlashMessages && $this->_flashMessages) {
                $this->_requestSession->write('Flash', $this->_flashMessages);
            }
            $this->_response = $response;
        } catch (PhpUnitException $e) {
            throw $e;
        } catch (DatabaseException $e) {
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        } catch (Exception $e) {
            $this->_exception = $e;
            $this->_handleError($e);
        }
    }

    /**
     * Get the correct dispatcher instance.
     *
     * @return \Cake\TestSuite\MiddlewareDispatcher|\Cake\TestSuite\LegacyRequestDispatcher A dispatcher instance
     */
    protected function _makeDispatcher()
    {
        if ($this->_useHttpServer) {
            return new MiddlewareDispatcher($this, $this->_appClass, $this->_appArgs);
        }

        return new LegacyRequestDispatcher($this);
    }

    /**
     * Adds additional event spies to the controller/view event manager.
     *
     * @param \Cake\Event\Event $event A dispatcher event.
     * @param \Cake\Controller\Controller|null $controller Controller instance.
     * @return void
     */
    public function controllerSpy($event, $controller = null)
    {
        if (!$controller) {
            /** @var \Cake\Controller\Controller $controller */
            $controller = $event->getSubject();
        }
        $this->_controller = $controller;
        $events = $controller->getEventManager();
        $events->on('View.beforeRender', function ($event, $viewFile) use ($controller) {
            if (!$this->_viewName) {
                $this->_viewName = $viewFile;
            }
            if ($this->_retainFlashMessages) {
                $this->_flashMessages = $controller->request->getSession()->read('Flash');
            }
        });
        $events->on('View.beforeLayout', function ($event, $viewFile) {
            $this->_layoutName = $viewFile;
        });
    }

    /**
     * Attempts to render an error response for a given exception.
     *
     * This method will attempt to use the configured exception renderer.
     * If that class does not exist, the built-in renderer will be used.
     *
     * @param \Exception $exception Exception to handle.
     * @return void
     * @throws \Exception
     */
    protected function _handleError($exception)
    {
        $class = Configure::read('Error.exceptionRenderer');
        if (empty($class) || !class_exists($class)) {
            $class = 'Cake\Error\ExceptionRenderer';
        }
        /** @var \Cake\Error\ExceptionRenderer $instance */
        $instance = new $class($exception);
        $this->_response = $instance->render();
    }

    /**
     * Creates a request object with the configured options and parameters.
     *
     * @param string|array $url The URL
     * @param string $method The HTTP method
     * @param array|null $data The request data.
     * @return array The request context
     */
    protected function _buildRequest($url, $method, $data)
    {
        $sessionConfig = (array)Configure::read('Session') + [
            'defaults' => 'php',
        ];
        $session = Session::create($sessionConfig);
        $session->write($this->_session);
        list ($url, $query) = $this->_url($url);
        $tokenUrl = $url;

        if ($query) {
            $tokenUrl .= '?' . $query;
        }

        parse_str($query, $queryData);
        $props = [
            'url' => $url,
            'session' => $session,
            'query' => $queryData
        ];
        if (is_string($data)) {
            $props['input'] = $data;
        }
        if (!isset($props['input'])) {
            $props['post'] = $this->_addTokens($tokenUrl, $data);
        }
        $props['cookies'] = $this->_cookie;

        $env = [
            'REQUEST_METHOD' => $method,
            'QUERY_STRING' => $query,
            'REQUEST_URI' => $url,
        ];
        if (isset($this->_request['headers'])) {
            foreach ($this->_request['headers'] as $k => $v) {
                $name = strtoupper(str_replace('-', '_', $k));
                if (!in_array($name, ['CONTENT_LENGTH', 'CONTENT_TYPE'])) {
                    $name = 'HTTP_' . $name;
                }
                $env[$name] = $v;
            }
            unset($this->_request['headers']);
        }
        $props['environment'] = $env;
        $props = Hash::merge($props, $this->_request);

        return $props;
    }

    /**
     * Add the CSRF and Security Component tokens if necessary.
     *
     * @param string $url The URL the form is being submitted on.
     * @param array $data The request body data.
     * @return array The request body with tokens added.
     */
    protected function _addTokens($url, $data)
    {
        if ($this->_securityToken === true) {
            $keys = array_map(function ($field) {
                return preg_replace('/(\.\d+)+$/', '', $field);
            }, array_keys(Hash::flatten($data)));
            $tokenData = $this->_buildFieldToken($url, array_unique($keys));
            $data['_Token'] = $tokenData;
            $data['_Token']['debug'] = 'SecurityComponent debug data would be added here';
        }

        if ($this->_csrfToken === true) {
            if (!isset($this->_cookie['csrfToken'])) {
                $this->_cookie['csrfToken'] = Text::uuid();
            }
            if (!isset($data['_csrfToken'])) {
                $data['_csrfToken'] = $this->_cookie['csrfToken'];
            }
        }

        return $data;
    }

    /**
     * Creates a valid request url and parameter array more like Request::_url()
     *
     * @param string|array $url The URL
     * @return array Qualified URL and the query parameters
     */
    protected function _url($url)
    {
        // re-create URL in ServerRequest's context so
        // query strings are encoded as expected
        $request = new ServerRequest(['url' => Router::url($url)]);
        $url = $request->getRequestTarget();

        $query = '';

        $path = parse_url($url, PHP_URL_PATH);
        if (strpos($url, '?') !== false) {
            $query = parse_url($url, PHP_URL_QUERY);
        }

        return [$path, $query];
    }

    /**
     * Get the response body as string
     *
     * @return string The response body.
     */
    protected function _getBodyAsString()
    {
        if (!$this->_response) {
            $this->fail('No response set, cannot assert content.');
        }

        return (string)$this->_response->getBody();
    }

    /**
     * Fetches a view variable by name.
     *
     * If the view variable does not exist, null will be returned.
     *
     * @param string $name The view variable to get.
     * @return mixed The view variable if set.
     */
    public function viewVariable($name)
    {
        if (empty($this->_controller->viewVars)) {
            $this->fail('There are no view variables, perhaps you need to run a request?');
        }
        if (isset($this->_controller->viewVars[$name])) {
            return $this->_controller->viewVars[$name];
        }

        return null;
    }

    /**
     * Asserts that the response status code is in the 2xx range.
     *
     * @param string $message Custom message for failure.
     * @return void
     */
    public function assertResponseOk($message = null)
    {
        $this->assertThat(null, new StatusOk($this->_response), $message);
    }

    /**
     * Asserts that the response status code is in the 2xx/3xx range.
     *
     * @param string $message Custom message for failure.
     * @return void
     */
    public function assertResponseSuccess($message = null)
    {
        $this->assertThat(null, new StatusSuccess($this->_response), $message);
    }

    /**
     * Asserts that the response status code is in the 4xx range.
     *
     * @param string $message Custom message for failure.
     * @return void
     */
    public function assertResponseError($message = null)
    {
        $this->assertThat(null, new StatusError($this->_response), $message);
    }

    /**
     * Asserts that the response status code is in the 5xx range.
     *
     * @param string $message Custom message for failure.
     * @return void
     */
    public function assertResponseFailure($message = null)
    {
        $this->assertThat(null, new StatusFailure($this->_response), $message);
    }

    /**
     * Asserts a specific response status code.
     *
     * @param int $code Status code to assert.
     * @param string $message Custom message for failure.
     * @return void
     */
    public function assertResponseCode($code, $message = null)
    {
        $this->assertThat($code, new StatusCode($this->_response), $message);
    }

    /**
     * Asserts that the Location header is correct.
     *
     * @param string|array|null $url The URL you expected the client to go to. This
     *   can either be a string URL or an array compatible with Router::url(). Use null to
     *   simply check for the existence of this header.
     * @param string $message The failure message that will be appended to the generated message.
     * @return void
     */
    public function assertRedirect($url = null, $message = '')
    {
        if (!$this->_response) {
            $this->fail('No response set, cannot assert location header. ' . $message);
        }
        $result = $this->_response->getHeaderLine('Location');
        if ($url === null) {
            $this->assertNotEmpty($result, $message);

            return;
        }
        if (empty($result)) {
            $this->fail('No location header set. ' . $message);
        }
        $this->assertEquals(Router::url($url, ['_full' => true]), $result, $message);
    }

    /**
     * Asserts that the Location header contains a substring
     *
     * @param string $url The URL you expected the client to go to.
     * @param string $message The failure message that will be appended to the generated message.
     * @return void
     */
    public function assertRedirectContains($url, $message = '')
    {
        if (!$this->_response) {
            $this->fail('No response set, cannot assert location header. ' . $message);
        }
        $result = $this->_response->getHeaderLine('Location');
        if (empty($result)) {
            $this->fail('No location header set. ' . $message);
        }
        $this->assertContains($url, $result, $message);
    }

    /**
     * Asserts that the Location header is not set.
     *
     * @param string $message The failure message that will be appended to the generated message.
     * @return void
     */
    public function assertNoRedirect($message = '')
    {
        if (!$this->_response) {
            $this->fail('No response set, cannot assert location header. ' . $message);
        }
        $result = $this->_response->getHeaderLine('Location');
        if (!$message) {
            $message = 'Redirect header set';
        }
        if (!empty($result)) {
            $message .= ': ' . $result;
        }
        $this->assertEmpty($result, $message);
    }

    /**
     * Asserts response headers
     *
     * @param string $header The header to check
     * @param string $content The content to check for.
     * @param string $message The failure message that will be appended to the generated message.
     * @return void
     */
    public function assertHeader($header, $content, $message = '')
    {
        $this->assertThat($content, new Header($this->_response, $header), $message);
    }

    /**
     * Asserts response header contains a string
     *
     * @param string $header The header to check
     * @param string $content The content to check for.
     * @param string $message The failure message that will be appended to the generated message.
     * @return void
     */
    public function assertHeaderContains($header, $content, $message = '')
    {
        $this->assertThat($content, new HeaderContains($this->_response, $header), $message);
    }

    /**
     * Asserts content type
     *
     * @param string $type The content-type to check for.
     * @param string $message The failure message that will be appended to the generated message.
     * @return void
     */
    public function assertContentType($type, $message = '')
    {
        $this->assertThat($type, new ContentType($this->_response), $message);
    }

    /**
     * Asserts content exists in the response body.
     *
     * @param mixed $content The content to check for.
     * @param string $message The failure message that will be appended to the generated message.
     * @return void
     */
    public function assertResponseEquals($content, $message = '')
    {
        $this->assertThat($this->_getBodyAsString(), new IsEqual($content), $message);
    }

    /**
     * Asserts content exists in the response body.
     *
     * @param string $content The content to check for.
     * @param string $message The failure message that will be appended to the generated message.
     * @param bool $ignoreCase A flag to check whether we should ignore case or not.
     * @return void
     */
    public function assertResponseContains($content, $message = '', $ignoreCase = false)
    {
        $this->assertThat($this->_getBodyAsString(), new StringContains($content, $ignoreCase), $message);
    }

    /**
     * Asserts content does not exist in the response body.
     *
     * @param string $content The content to check for.
     * @param string $message The failure message that will be appended to the generated message.
     * @param bool $ignoreCase A flag to check whether we should ignore case or not.
     * @return void
     */
    public function assertResponseNotContains($content, $message = '', $ignoreCase = false)
    {
        $constraint = new LogicalNot(
            new StringContains($content, $ignoreCase)
        );
        $this->assertThat($this->_getBodyAsString(), $constraint, $message);
    }

    /**
     * Asserts that the response body matches a given regular expression.
     *
     * @param string $pattern The pattern to compare against.
     * @param string $message The failure message that will be appended to the generated message.
     * @return void
     */
    public function assertResponseRegExp($pattern, $message = '')
    {
        $constraint = new RegularExpression($pattern);
        $this->assertThat($this->_getBodyAsString(), $constraint, $message);
    }

    /**
     * Asserts that the response body does not match a given regular expression.
     *
     * @param string $pattern The pattern to compare against.
     * @param string $message The failure message that will be appended to the generated message.
     * @return void
     */
    public function assertResponseNotRegExp($pattern, $message = '')
    {
        $constraint = new LogicalNot(
            new RegularExpression($pattern)
        );
        $this->assertThat($this->_getBodyAsString(), $constraint, $message);
    }

    /**
     * Assert response content is not empty.
     *
     * @param string $message The failure message that will be appended to the generated message.
     * @return void
     */
    public function assertResponseNotEmpty($message = '')
    {
        $constraint = new LogicalNot(
            new IsEmpty()
        );
        $this->assertThat($this->_getBodyAsString(), $constraint, $message);
    }
    /**
     * Assert response content is empty.
     *
     * @param string $message The failure message that will be appended to the generated message.
     * @return void
     */
    public function assertResponseEmpty($message = '')
    {
        $this->assertThat($this->_getBodyAsString(), new IsEmpty(), $message);
    }

    /**
     * Asserts that the search string was in the template name.
     *
     * @param string $content The content to check for.
     * @param string $message The failure message that will be appended to the generated message.
     * @return void
     */
    public function assertTemplate($content, $message = '')
    {
        $this->assertThat($content, new TemplateFileEquals($this->_viewName), $message);
    }

    /**
     * Asserts that the search string was in the layout name.
     *
     * @param string $content The content to check for.
     * @param string $message The failure message that will be appended to the generated message.
     * @return void
     */
    public function assertLayout($content, $message = '')
    {
        $this->assertThat($content, new LayoutFileEquals($this->_layoutName), $message);
    }

    /**
     * Asserts session contents
     *
     * @param string $expected The expected contents.
     * @param string $path The session data path. Uses Hash::get() compatible notation
     * @param string $message The failure message that will be appended to the generated message.
     * @return void
     */
    public function assertSession($expected, $path, $message = '')
    {
        if (empty($this->_requestSession)) {
            $this->fail('There is no stored session data. Perhaps you need to run a request?');
        }
        $result = $this->_requestSession->read($path);
        $this->assertEquals(
            $expected,
            $result,
            'Session content for "' . $path . '" differs. ' . $message
        );
    }

    /**
     * Asserts cookie values
     *
     * @param string $expected The expected contents.
     * @param string $name The cookie name.
     * @param string $message The failure message that will be appended to the generated message.
     * @return void
     */
    public function assertCookie($expected, $name, $message = '')
    {
        $this->assertThat($name, new CookieSet($this->_response), $message);
        $this->assertThat($expected, new CookieEquals($this->_response, $name), $message);
    }

    /**
     * Asserts a cookie has not been set in the response
     *
     * @param string $cookie The cookie name to check
     * @param string $message The failure message that will be appended to the generated message.
     * @return void
     */
    public function assertCookieNotSet($cookie, $message = '')
    {
        $this->assertThat($cookie, new CookieNotSet($this->_response), $message);
    }

    /**
     * Disable the error handler middleware.
     *
     * By using this function, exceptions are no longer caught by the ErrorHandlerMiddleware
     * and are instead re-thrown by the TestExceptionRenderer. This can be helpful
     * when trying to diagnose/debug unexpected failures in test cases.
     *
     * @return void
     */
    public function disableErrorHandlerMiddleware()
    {
        Configure::write('Error.exceptionRenderer', TestExceptionRenderer::class);
    }

    /**
     * Asserts cookie values which are encrypted by the
     * CookieComponent.
     *
     * The difference from assertCookie() is this decrypts the cookie
     * value like the CookieComponent for this assertion.
     *
     * @param string $expected The expected contents.
     * @param string $name The cookie name.
     * @param string|bool $encrypt Encryption mode to use.
     * @param string|null $key Encryption key used. Defaults
     *   to Security.salt.
     * @param string $message The failure message that will be appended to the generated message.
     * @return void
     * @see \Cake\Utility\CookieCryptTrait::_encrypt()
     */
    public function assertCookieEncrypted($expected, $name, $encrypt = 'aes', $key = null, $message = '')
    {
        $this->assertThat($name, new CookieSet($this->_response), $message);

        $this->_cookieEncryptionKey = $key;
        $this->assertThat($expected, new CookieEncryptedEquals($this->_response, $name, $encrypt, $this->_getCookieEncryptionKey()));
    }

    /**
     * Asserts that a file with the given name was sent in the response
     *
     * @param string $expected The file name that should be sent in the response
     * @param string $message The failure message that will be appended to the generated message.
     * @return void
     */
    public function assertFileResponse($expected, $message = '')
    {
        if ($this->_response === null) {
            $this->fail('No response set, cannot assert file.');
        }
        $actual = isset($this->_response->getFile()->path) ? $this->_response->getFile()->path : null;

        if ($actual === null) {
            $this->fail('No file was sent in this response');
        }
        $this->assertEquals($expected, $actual, $message);
    }
}
