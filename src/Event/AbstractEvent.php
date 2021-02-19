<?php /** @noinspection PhpUndefinedClassInspection */

namespace MightySyncer\Event;


use Symfony\Contracts\EventDispatcher\Event;

/**
 * Class ImporterEvent events for handling imports
 * @package MightySyncer\EventListener
 */
abstract class AbstractEvent extends Event
{
    /**
     * Get event name
     * @return string
     */
    abstract public function getName(): string;
}