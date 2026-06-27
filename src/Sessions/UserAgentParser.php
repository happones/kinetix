<?php

declare(strict_types=1);

namespace Happones\Kinetix\Sessions;

/**
 * A tiny, dependency-free user-agent parser — just enough to label a browser
 * session with its browser, platform and device type. Not a full UA database;
 * it covers the mainstream browsers/OSes shown in the sessions list.
 *
 * @phpstan-type ParsedAgent array{browser: string, platform: string, device: string}
 */
class UserAgentParser
{
    /**
     * @return ParsedAgent
     */
    public static function parse(?string $userAgent): array
    {
        $ua = $userAgent ?? '';

        return [
            'browser'  => static::browser($ua),
            'platform' => static::platform($ua),
            'device'   => static::device($ua),
        ];
    }

    protected static function browser(string $ua): string
    {
        return match (true) {
            str_contains($ua, 'Edg')                               => 'Edge',
            str_contains($ua, 'OPR') || str_contains($ua, 'Opera') => 'Opera',
            str_contains($ua, 'Firefox')                           => 'Firefox',
            str_contains($ua, 'Chrome')                            => 'Chrome',
            str_contains($ua, 'Safari')                            => 'Safari',
            default                                                => 'Unknown',
        };
    }

    protected static function platform(string $ua): string
    {
        return match (true) {
            str_contains($ua, 'Windows')                                    => 'Windows',
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPad')        => 'iOS',
            str_contains($ua, 'Android')                                    => 'Android',
            str_contains($ua, 'Mac OS X') || str_contains($ua, 'Macintosh') => 'macOS',
            str_contains($ua, 'Linux')                                      => 'Linux',
            default                                                         => 'Unknown',
        };
    }

    protected static function device(string $ua): string
    {
        return match (true) {
            str_contains($ua, 'iPad')   || str_contains($ua, 'Tablet')                                 => 'tablet',
            str_contains($ua, 'Mobile') || str_contains($ua, 'iPhone') || str_contains($ua, 'Android') => 'mobile',
            default                                                                                    => 'desktop',
        };
    }
}
