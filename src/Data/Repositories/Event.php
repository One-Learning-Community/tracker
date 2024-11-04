<?php

namespace PragmaRX\Tracker\Data\Repositories;

use PragmaRX\Support\Config;
use PragmaRX\Tracker\Eventing\EventStorage;

class Event extends Repository
{
    /**
     * @var EventLog
     */
    private $eventLogRepository;

    /**
     * @var SystemClass
     */
    private $systemClassRepository;

    /**
     * @var Log
     */
    private $logRepository;

    /**
     * @var \PragmaRX\Support\Config
     */
    private $config;

    /**
     * @var \PragmaRX\Tracker\Eventing\EventStorage
     */
    private $eventStorage;

    public function __construct(
        $model,
        EventStorage $eventStorage,
        EventLog $eventLogRepository,
        SystemClass $systemClassRepository,
        Log $logRepository,
        Config $config
    )
    {
        parent::__construct($model);

        $this->eventStorage = $eventStorage;

        $this->eventLogRepository = $eventLogRepository;

        $this->systemClassRepository = $systemClassRepository;

        $this->logRepository = $logRepository;

        $this->config = $config;
    }

    public function logEvents()
    {
        if (!$this->logRepository->getCurrentLogId()) {
            return;
        }

        foreach ($this->eventStorage->popAll() as $event) {
            if ($this->isLoggableEvent($event)) {
                $this->logEvent($event);
            }
        }
    }

    private function isLoggableEvent($event)
    {
        $forbidden = $this->config->get('do_not_log_events');

        // Illuminate Query may cause infinite recursion
        $forbidden[] = 'illuminate.query';

        return
            $event['event'] != $this->getObject($event['object'])

            &&

            !in_array_wildcard($event['event'], $forbidden)

            &&

            !$this->config->get('log_only_events')
            || in_array($event['event'], $this->config->get('log_only_events'));
    }

    public function logEvent($event)
    {
        $event = $this->makeEventArray($event);

        $eventId = $this->getEventId($event);

        $logId = $event['log_id'] ?? $this->logRepository->getCurrentLogId();

        if ($eventId && $logId) {
            $objectName = $this->getObjectName($event);

            $classId = $this->getClassId($objectName);

            $classRefId = $this->getClassRefId($event);

            $this->eventLogRepository->create(
                [
                    'log_id' => $logId,
                    'event_id' => $eventId,
                    'class_id' => $classId,
                    'class_ref_id' => $classRefId,
                    'extra_data' => $event['extra_data'] ?? null,
                    ...(
                    ($createdAt = $event['created_at'] ?? null)
                        ? ['created_at' => $createdAt]
                        : []
                    )
                ]
            );
        }
    }

    private function getObject($object)
    {
        if (is_object($object)) {
            $object = get_class($object);
        } elseif (is_array($object)) {
            $object = serialize($object);
        }

        return $object;
    }

    public function getAll($minutes, $results)
    {
        return $this->getModel()->allInThePeriod($minutes, $results);
    }

    /**
     * Get the object name from an event.
     *
     * @param $event
     *
     * @return null|string
     */
    private function getObjectName($event)
    {
        return isset($event['object'])
            ? $this->getObject($event['object'])
            : null;
    }

    /**
     * Get the system class id by object name.
     *
     * @param null|string $objectName
     *
     * @return null
     */
    private function getClassId($objectName)
    {
        return $objectName
            ? $this->systemClassRepository->findOrCreate(
                ['name' => $objectName],
                ['name']
            )
            : null;
    }

    /**
     * Get the object name from an event.
     *
     * @param $event
     *
     * @return null|string
     */
    private function getClassRefId($event)
    {
        if (!isset($event['object']) || !is_object($event['object']) || !method_exists($event['object'], 'getKey')) {
            return null;
        }

        return $event['object']->getKey();
    }

    /**
     * Get the event id.
     *
     * @param $event
     *
     * @return null
     */
    private function getEventId($event)
    {
        return $event['event']
            ? $this->findOrCreate(
                ['name' => $event['event']],
                ['name']
            )
            : null;
    }

    private function makeEventArray($event)
    {
        if (is_string($event)) {
            $event = [
                'event' => $event,
                'object' => null,
            ];
        }

        return $event;
    }

    public function getCacheProvider()
    {
        return app('tracker.cache.local');
    }
}
