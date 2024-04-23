<?php

namespace PragmaRX\Tracker\Data\Repositories;

use PragmaRX\Support\Config;

class Route extends Repository
{
    protected array $isRouteTrackableCache = [];

    protected array $isPathTrackableCache = [];

    public function __construct($model, public Config $config)
    {
        parent::__construct($model);

        $this->config = $config;
    }

    public function isTrackable($route)
    {
        if (!$routeName = $route->currentRouteName()) {
            return true;
        }

        if (!isset($this->isRouteTrackableCache[$routeName])) {
            $forbidden = $this->config->get('do_not_track_routes');

            $this->isRouteTrackableCache[$routeName] =
                !$forbidden ||
                !in_array_wildcard($routeName, $forbidden);
        }

        return $this->isRouteTrackableCache[$routeName];
    }

    public function pathIsTrackable($path)
    {
        if (empty($path)) {
            return true;
        }

        if (!isset($this->isPathTrackableCache[$path])) {
            $forbidden = $this->config->get('do_not_track_paths');

            $this->isPathTrackableCache[$path] =
                !$forbidden ||
                !in_array_wildcard($path, $forbidden);
        }

        return $this->isPathTrackableCache[$path];
    }
}
