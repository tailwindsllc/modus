<?php

namespace Modus\Application;

use Aura\Di;
use Aura\Payload\Payload;
use Aura\Web;

use Modus\Application\Exception\NoValidMethod;
use Modus\Response\ResponseManager;
use Modus\Router;
use Modus\ErrorLogging as Log;
use Modus\Common\Route\Exception\NotFoundException;
use Modus\Auth;
use Modus\Config\Config;
use Modus\Response\Exception;

class Bootstrap
{

    /**
     * @var Config
     */
    protected $config;

    /**
     * @var Router\RouteManager
     */
    protected $router;

    /**
     * @var \Monolog\Logger
     */
    protected $errorLog;

    /**
     * @var \Monolog\Logger
     */
    protected $eventLog;

    /**
     * @var Auth\Service
     */
    protected $authService;

    /**
     * @var Di\Container
     */
    protected $serviceLocator;

    /**
     * @var ResponseManager
     */
    protected $responseManager;

    /**
     * @param Config $config
     * @param Router\RouteManager $router
     * @param Auth\Service $authService
     * @param Log\Manager $handler
     * @param ResponseManager $responseManager
     * @throws Log\Exception\LoggerNotRegistered
     */
    public function __construct(
        Config $config,
        Di\Container $di,
        Router\RouteManager $router,
        Auth\Service $authService,
        Log\Manager $handler,
        ResponseManager $responseManager
    ) {
        $this->config = $config;
        $this->serviceLocator = $di;
        $this->router = $router;
        $this->authService = $authService;
        $this->errorLog = $handler->getLogger('error');
        $this->eventLog = $handler->getLogger('event');
        $this->responseManager = $responseManager;

        $this->authService->resume();
    }

    /**
     * @throws Exception\ContentTypeNotValidException
     * @throws NotFoundException
     * @throws \Exception
     */
    public function execute()
    {
        $config = $this->config->getConfig();

        // Figure out the route information.
        try {
            $routepath = $this->evaluateRoute();
            $route = $routepath->params;
            $components = $this->determineRouteComponents($route);
            $params = $route;
            unset($params['action']);
            unset($params['responder']);
            unset($params['method']);
        } catch (NotFoundException $e) {
            if (isset($config['error_page']['404'])) {
                $lastRoute = $this->router->getLastRoute();
                $this->eventLog->info(sprintf("No route was found that matches '%s'", $lastRoute));

                $responder = $this->serviceLocator->newInstance($config['error_page']['404']);
                $this->responseManager->process(new Payload(), $responder);
                return;
            }

            // No 404 page was set, so let's throw the exception.
            throw $e;
        }

        // Load the responder that we identified from routes.
        $responder = $this->serviceLocator->newInstance($components['responderClass']);

        // Load the action we identified from routes, if one exists.
        if (!is_null($components['actionClass'])) {
            $action = $this->serviceLocator->newInstance($components['actionClass']);

            if (!is_callable([$action, $components['actionMethod']])) {
                throw new NoValidMethod(sprintf('The method %s does not exist on action %s', $components['actionMethod'], $components['actionClass']));
            }

            // Call the action.
            $result = call_user_func_array([$action, $components['actionMethod']], $params);
        }

        // Let's not leave the response hanging...
        if (!isset($result) || !$result) {
            $result = new Payload();
        }

        // Call and send the response, if possible.
        $this->responseManager->process($result, $responder);
    }

    /**
     * @return \Aura\Router\Route
     * @throws NotFoundException
     */
    protected function evaluateRoute()
    {
        $router = $this->router;
        $routepath = $router->determineRouting();
        if (!$routepath) {
            throw new NotFoundException('The route "' . $router->getLastRoute() . '" was not found');
        }
        return $routepath;
    }

    protected function determineRouteComponents(array $components = [])
    {
        if (isset($components['responder'])) {
            if (strpos($components['responder'], ':') !== false) {
                list($responder, $responderMethod) = explode(':', $components['responder']);
            } else {
                $responder = $components['responder'];
                $responderMethod = 'process';
            }
        } else {
            $responder = 'Modus\Responder\NoContent204Response';
            $responderMethod = 'process';
        }

        if (isset($components['action'])) {
            if (isset($components['method'])) {
                $action = $components['action'];
                $method = $components['method'];
            } else {
                list($action, $method) = explode(':', $components['action']);
            }
        } else {
            $action = null;
            $method = null;
        }

        return [
            'responderClass' => $responder,
            'responderMethod' => $responderMethod,
            'actionClass' => $action,
            'actionMethod' => $method,
        ];
    }
}
