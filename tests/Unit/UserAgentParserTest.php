<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Unit;

use Happones\Kinetix\Sessions\UserAgentParser;
use Happones\Kinetix\Tests\TestCase;

class UserAgentParserTest extends TestCase
{
    public function test_parses_desktop_chrome_on_windows(): void
    {
        $parsed = UserAgentParser::parse('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0 Safari/537.36');

        $this->assertSame('Chrome', $parsed['browser']);
        $this->assertSame('Windows', $parsed['platform']);
        $this->assertSame('desktop', $parsed['device']);
    }

    public function test_parses_mobile_safari_on_ios(): void
    {
        $parsed = UserAgentParser::parse('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1 Mobile/15E148 Safari/604.1');

        $this->assertSame('Safari', $parsed['browser']);
        $this->assertSame('iOS', $parsed['platform']);
        $this->assertSame('mobile', $parsed['device']);
    }

    public function test_parses_edge_and_tablet(): void
    {
        $edge = UserAgentParser::parse('Mozilla/5.0 (Windows NT 10.0) Chrome/120 Edg/120');
        $this->assertSame('Edge', $edge['browser']);

        $tablet = UserAgentParser::parse('Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) Safari/604.1');
        $this->assertSame('tablet', $tablet['device']);
        $this->assertSame('iOS', $tablet['platform']);
    }

    public function test_handles_null_and_unknown(): void
    {
        $parsed = UserAgentParser::parse(null);

        $this->assertSame('Unknown', $parsed['browser']);
        $this->assertSame('Unknown', $parsed['platform']);
        $this->assertSame('desktop', $parsed['device']);
    }
}
