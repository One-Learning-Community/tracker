<?php

namespace PragmaRX\Tracker\Support;

use Cache as IlluminateCache;
use Illuminate\Database\Eloquent\Model;
use PragmaRX\Support\Config as Config;

class Cache
{
    private $enabled = false;

    private $cache;

    private $ttl;

    public function __construct(
        Config $config,
        string $cacheStore = '',
        int    $ttl = 0,
    )
    {
        $this->enabled = $config->get('cache_enabled');

        $this->cache = IlluminateCache::store($cacheStore ?: $config->get('cache_store'));

        $this->ttl = $ttl ?: $config->get('cache_ttl', 600);
    }

    public function cachePut($cacheKey, $model)
    {
        if ($this->enabled) {
            $this->cache->put($cacheKey, $model, $this->ttl);
        }
    }

    private function extractAttributes($attributes)
    {
        if (is_array($attributes) || is_string($attributes)) {
            return $attributes;
        }

        if (is_string($attributes) || is_numeric($attributes)) {
            return (array)$attributes;
        }

        if ($attributes instanceof Model) {
            return $attributes->getAttributes();
        }
    }

    /**
     * @param $attributes
     * @param $keys
     *
     * @return array
     */
    private function extractKeys($attributes, $keys)
    {
        if (!$keys) {
            $keys = array_keys($attributes);
        }

        if (!is_array($keys)) {
            $keys = (array)$keys;

            return $keys;
        }

        return $keys;
    }

    /**
     * @param string $key
     *
     * @return array
     */
    public function findCachedWithKey($key)
    {
        if ($this->enabled) {
            return $this->cache->get($key);
        }
    }

    public function makeKeyAndPut($model, $key)
    {
        $key = $this->makeCacheKey($model, $key, get_class($model));

        $this->cachePut($key, $model);
    }

    /**
     * @param string $identifier
     */
    public function findCached($attributes, $keys, $identifier = null)
    {
        if (!$this->enabled) {
            return;
        }

        $key = $this->makeCacheKey($attributes, $keys, $identifier);

        return [
            $this->findCachedWithKey($key),
            $key,
        ];
    }

    public function makeCacheKey($attributes, $keys, $identifier)
    {
        $attributes = $this->extractAttributes($attributes);

        $cacheKey = "className=$identifier;";

        $keys = $this->extractKeys($attributes, $keys, $identifier);

        foreach ($keys as $key) {
            if (isset($attributes[$key])) {
                $cacheKey .= "$key=$attributes[$key];";
            }
        }

        return $identifier . '::' . hash('sha1', $cacheKey);
    }
}
