<?php

namespace PragmaRX\Tracker\Data;

use Illuminate\Routing\Router as IlluminateRouter;
use Illuminate\Session\Store as IlluminateSession;
use PragmaRX\Support\Config;
use PragmaRX\Support\GeoIp\GeoIp;
use PragmaRX\Tracker\Data\Repositories\Agent;
use PragmaRX\Tracker\Data\Repositories\Connection;
use PragmaRX\Tracker\Data\Repositories\Cookie;
use PragmaRX\Tracker\Data\Repositories\Device;
use PragmaRX\Tracker\Data\Repositories\Domain;
use PragmaRX\Tracker\Data\Repositories\Error;
use PragmaRX\Tracker\Data\Repositories\Event;
use PragmaRX\Tracker\Data\Repositories\EventLog;
use PragmaRX\Tracker\Data\Repositories\GeoIp as GeoIpRepository;
use PragmaRX\Tracker\Data\Repositories\Language;
use PragmaRX\Tracker\Data\Repositories\Log;
use PragmaRX\Tracker\Data\Repositories\Path;
use PragmaRX\Tracker\Data\Repositories\Query;
use PragmaRX\Tracker\Data\Repositories\QueryArgument;
use PragmaRX\Tracker\Data\Repositories\Referer;
use PragmaRX\Tracker\Data\Repositories\Route;
use PragmaRX\Tracker\Data\Repositories\RoutePath;
use PragmaRX\Tracker\Data\Repositories\RoutePathParameter;
use PragmaRX\Tracker\Data\Repositories\Session;
use PragmaRX\Tracker\Data\Repositories\SqlQuery;
use PragmaRX\Tracker\Data\Repositories\SqlQueryBinding;
use PragmaRX\Tracker\Data\Repositories\SqlQueryBindingParameter;
use PragmaRX\Tracker\Data\Repositories\SqlQueryLog;
use PragmaRX\Tracker\Data\Repositories\SystemClass;
use PragmaRX\Tracker\Services\Authentication;
use PragmaRX\Tracker\Support\CrawlerDetector;
use PragmaRX\Tracker\Support\LanguageDetect;
use PragmaRX\Tracker\Support\MobileDetect;

class RepositoryManager implements RepositoryManagerInterface
{
    /**
     * @param \PragmaRX\Tracker\Support\UserAgentParser|null $userAgentParser
     */
    public function __construct(
        public GeoIP $geoIp,
        public MobileDetect $mobileDetect,
        public $userAgentParser,
        public Authentication $authentication,
        public IlluminateSession $session,
        public Config $config,
        public Session $sessionRepository,
        public Log $logRepository,
        public Path $pathRepository,
        public Query $queryRepository,
        public QueryArgument $queryArgumentRepository,
        public Agent $agentRepository,
        public Device $deviceRepository,
        public Cookie $cookieRepository,
        public Domain $domainRepository,
        public Referer $refererRepository,
        public Route $routeRepository,
        public RoutePath $routePathRepository,
        public RoutePathParameter $routePathParameterRepository,
        public Error $errorRepository,
        public GeoIpRepository $geoIpRepository,
        public SqlQuery $sqlQueryRepository,
        public SqlQueryBinding $sqlQueryBindingRepository,
        public SqlQueryBindingParameter $sqlQueryBindingParameterRepository,
        public SqlQueryLog $sqlQueryLogRepository,
        public Connection $connectionRepository,
        public Event $eventRepository,
        public EventLog $eventLogRepository,
        public SystemClass $systemClassRepository,
        public CrawlerDetector $crawlerDetector,
        public Language $languageRepository,
        public LanguageDetect $languageDetect
    ) {

    }

    public function checkSessionData($newData, $currentData)
    {
        if ($newData && $currentData && $newData !== $currentData) {
            $newData = $this->updateSessionData($newData);
        }

        return $newData;
    }

    public function createLog($data)
    {
        $this->logRepository->createLog($data);

        $this->sqlQueryRepository->fire();
    }

    private function createRoutePathParameter($route_path_id, $parameter, $value)
    {
        return $this->routePathParameterRepository->create(
            [
                'route_path_id' => $route_path_id,
                'parameter'     => $parameter,
                'value'         => $value,
            ]
        );
    }

    public function errors($minutes, $results)
    {
        return $this->logRepository->getErrors($minutes, $results);
    }

    public function events($minutes, $results)
    {
        return $this->eventRepository->getAll($minutes, $results);
    }

    public function findOrCreateAgent($data)
    {
        return $this->agentRepository->findOrCreate($data, ['name_hash']);
    }

    public function findOrCreateDevice($data)
    {
        return $this->deviceRepository->findOrCreate($data, ['kind', 'model', 'platform', 'platform_version']);
    }

    public function findOrCreateLanguage($data)
    {
        return $this->languageRepository->findOrCreate($data, ['preference', 'language-range']);
    }

    public function findOrCreatePath($path)
    {
        return $this->pathRepository->findOrCreate($path, ['path']);
    }

    public function findOrCreateQuery($data)
    {
        $id = $this->queryRepository->findOrCreate($data, ['query'], $created);

        if ($created) {
            foreach ($data['arguments'] as $argument => $value) {
                if (is_array($value)) {
                    $value = multi_implode(',', $value);
                }

                $this->queryArgumentRepository->create(
                    [
                        'query_id' => $id,
                        'argument' => $argument,
                        'value'    => empty($value) ? '' : $value,
                    ]
                );
            }
        }

        return $id;
    }

    public function findOrCreateSession($data)
    {
        return $this->sessionRepository->findOrCreate($data, ['uuid']);
    }

    public function getAgentId()
    {
        return $this->findOrCreateAgent($this->getCurrentAgentArray());
    }

    public function getAllSessions()
    {
        return $this->sessionRepository->all();
    }

    public function getCookieId()
    {
        return $this->cookieRepository->getId();
    }

    public function getCurrentAgentArray()
    {
        return [
            'name' => $name = $this->getCurrentUserAgent() ?: 'Other',

            'browser' => $this->userAgentParser->userAgent->family,

            'browser_version' => $this->userAgentParser->getUserAgentVersion(),

            'name_hash' => hash('sha256', $name),
        ];
    }

    public function getCurrentDeviceProperties()
    {
        if ($properties = $this->getDevice()) {
            $properties['platform'] = $this->getOperatingSystemFamily();

            $properties['platform_version'] = $this->getOperatingSystemVersion();
        }

        return $properties;
    }

    public function getCurrentUserAgent()
    {
        return $this->userAgentParser->originalUserAgent;
    }

    public function getCurrentUserId()
    {
        return $this->authentication->getCurrentUserId();
    }

    /**
     * @return array
     */
    private function getDevice()
    {
        try {
            return $this->mobileDetect->detectDevice();
        } catch (\Exception $e) {
            return;
        }
    }

    private function getLanguage()
    {
        try {
            return $this->languageDetect->detectLanguage();
        } catch (\Exception $e) {
            return;
        }
    }

    public function getCurrentLanguage()
    {
        if ($languages = $this->getLanguage()) {
            $languages['preference'] = $this->languageDetect->getLanguagePreference();

            $languages['language-range'] = $this->languageDetect->getLanguageRange();
        }

        return $languages;
    }

    public function getDomainId($domain)
    {
        return $this->domainRepository->findOrCreate(
            ['name' => $domain],
            ['name']
        );
    }

    public function getGeoIpId($clientIp)
    {
        $id = null;

        if ($geoIpData = $this->geoIp->searchAddr($clientIp)) {
            $id = $this->geoIpRepository->findOrCreate(
                $geoIpData,
                ['latitude', 'longitude']
            );
        }

        return $id;
    }

    public function getLastSessions($minutes, $results)
    {
        return $this->sessionRepository->last($minutes, $results);
    }

    /**
     * @return mixed
     */
    private function getOperatingSystemFamily()
    {
        try {
            return $this->userAgentParser->operatingSystem->family;
        } catch (\Exception $e) {
            return;
        }
    }

    /**
     * @return mixed
     */
    private function getOperatingSystemVersion()
    {
        try {
            return $this->userAgentParser->getOperatingSystemVersion();
        } catch (\Exception $e) {
            return;
        }
    }

    public function getQueryId($query)
    {
        if (!$query) {
            return;
        }

        return $this->findOrCreateQuery($query);
    }

    public function getRefererId($referer)
    {
        if ($referer) {
            $url = parse_url($referer);

            if (!isset($url['host'])) {
                return;
            }

            if (in_array($url['host'], config('tracker.do_not_track_referer_domains', []))) {
                return;
            }

            $parts = explode('.', $url['host']);

            $domain = array_pop($parts);

            if (count($parts) > 0) {
                $domain = array_pop($parts).'.'.$domain;
            }

            $domain_id = $this->getDomainId($domain);

            try {
                return $this->refererRepository->store($referer, $url['host'], $domain_id);
            } catch(\Throwable $t) {
                report($t);
                return;
            }
        }
    }

    /**
     * @param $request
     *
     * @return mixed
     */
    private function getRequestPath($request)
    {
        if (is_string($request)) {
            return $request;
        }

        if (is_array($request)) {
            return $request['path'];
        }

        return $request->path();
    }

    /**
     * @param $route
     *
     * @return mixed
     */
    private function getRouteAction($route)
    {
        if (is_string($route)) {
            return '';
        }

        if (is_array($route)) {
            return $route['action'];
        }

        return $route->currentRouteAction();
    }

    /**
     * @param string $name
     */
    private function getRouteId($name, $action)
    {
        return $this->routeRepository->findOrCreate(
            ['name' => $name, 'action' => $action],
            ['name', 'action']
        );
    }

    /**
     * @param $route
     *
     * @return string
     */
    private function getRouteName($route)
    {
        if (is_string($route)) {
            return $route;
        }

        if (is_array($route)) {
            return $route['name'];
        }

        if ($name = $route->current()->getName()) {
            return $name;
        }

        $action = $route->current()->getAction();

        if ($name = isset($action['as']) ? $action['as'] : null) {
            return $name;
        }

        return '/'.$route->current()->uri();
    }

    /**
     * @param bool $created
     */
    private function getRoutePath($route_id, $path, &$created = null)
    {
        return $this->routePathRepository->findOrCreate(
            ['route_id' => $route_id, 'path' => $path],
            ['route_id', 'path'],
            $created
        );
    }

    public function getRoutePathId($route, $request)
    {
        $route_id = $this->getRouteId(
            $this->getRouteName($route),
            $this->getRouteAction($route)
                ?: 'closure'
        );

        $created = false;

        $route_path_id = $this->getRoutePath(
            $route_id,
            $this->getRequestPath($request),
            $created
        );

        if ($created && $route instanceof IlluminateRouter && $route->current()) {
            foreach ($route->current()->parameters() as $parameter => $value) {
                // When the parameter value is a whole model, we have
                // two options left:
                //
                //  1) Return model id, if it's available as 'id'
                //  2) Return null (not ideal, but, what could we do?)
                //
                // Should we store the whole model? Not really useful, right?

                if ($value instanceof \Illuminate\Database\Eloquent\Model) {
                    $model_id = null;

                    foreach ($this->config->get('id_columns_names', ['id']) as $column) {
                        if (property_exists($value, $column)) {
                            $model_id = $value->$column;

                            break;
                        }
                    }

                    $value = $model_id;
                }

                if ($route_path_id && $parameter && $value) {
                    $this->createRoutePathParameter($route_path_id, $parameter, $value);
                }
            }
        }

        return $route_path_id;
    }

    public function getSessionId($sessionData, $updateLastActivity)
    {
        return $this->sessionRepository->getCurrentId($sessionData, $updateLastActivity);
    }

    public function getSessionLog($uuid, $results = true)
    {
        $session = $this->sessionRepository->findByUuid($uuid);

        return $this->logRepository->bySession($session->id, $results);
    }

    public function handleThrowable($throwable)
    {
        $error_id = $this->errorRepository->findOrCreate(
            [
                'message' => $this->errorRepository->getMessageFromThrowable($throwable),
                'code'    => $this->errorRepository->getCodeFromThrowable($throwable),
            ],
            ['message', 'code']
        );

        return $this->logRepository->updateError($error_id);
    }

    public function isRobot()
    {
        return $this->crawlerDetector->isRobot();
    }

    public function logByRouteName($name, $minutes = null)
    {
        return $this->logRepository->allByRouteName($name, $minutes);
    }

    public function logEvents()
    {
        $this->eventRepository->logEvents();
    }

    public function logSqlQuery($query, $bindings, $time, $name)
    {
        $this->sqlQueryRepository->push([
            'query'    => $query,
            'bindings' => $bindings,
            'time'     => $time,
            'name'     => $name,
        ]);
    }

    public function pageViews($minutes, $results)
    {
        return $this->logRepository->pageViews($minutes, $results);
    }

    public function pageViewsByCountry($minutes, $results)
    {
        return $this->logRepository->pageViewsByCountry($minutes, $results);
    }

    public function parserIsAvailable()
    {
        return !empty($this->userAgentParser);
    }

    public function routeIsTrackable($route)
    {
        return $this->routeRepository->isTrackable($route);
    }

    public function pathIsTrackable($path)
    {
        return $this->routeRepository->pathIsTrackable($path);
    }

    public function setSessionData($data)
    {
        $this->sessionRepository->setSessionData($data);
    }

    public function trackEvent($event)
    {
        $this->eventRepository->logEvent($event);
    }

    public function trackRoute($route, $request)
    {
        $this->updateRoute(
            $this->getRoutePathId($route, $request)
        );
    }

    public function updateRoute($route_id)
    {
        return $this->logRepository->updateRoute($route_id);
    }

    public function updateSessionData($data)
    {
        return $this->sessionRepository->updateSessionData($data);
    }

    public function userDevices($minutes, $user_id, $results)
    {
        return $this->sessionRepository->userDevices(
            $minutes,
            $user_id ?: $this->authentication->getCurrentUserId(),
            $results
        );
    }

    public function users($minutes, $results)
    {
        return $this->sessionRepository->users($minutes, $results);
    }
}
