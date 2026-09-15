<?php

namespace Lumina\Core\Support;

use Jenssegers\Agent\Agent;

class BotDetector
{
    /**
     * Determine if the given user agent string or request belongs to a bot/crawler.
     */
    public static function isBot(?string $userAgent): bool
    {
        if ($userAgent === null || trim($userAgent) === '') {
            return false;
        }

        $agent = new Agent;
        $agent->setUserAgent($userAgent);

        return $agent->isRobot();
    }
}
