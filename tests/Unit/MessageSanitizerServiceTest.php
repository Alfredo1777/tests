<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Modules\GPS\Services\MessageSanitizerService;

class MessageSanitizerServiceTest extends TestCase
{
    public function test_sanitizes_out_of_bounds_coordinates_to_null()
    {
        $sanitizer = new MessageSanitizerService();
        $payload = ['latitude' => 95.0, 'longitude' => -190.0, 'speed' => 50];

        $result = $sanitizer->sanitize('uuid-123', $payload);

        $this->assertNull($result['latitude']);
        $this->assertNull($result['longitude']);
        $this->assertEquals(50, $result['speed']);

    }
    public function test_fixes_negative_speed_and_battery_limits()
    {
        $sanitizer = new MessageSanitizerService();
        $payload = ['speed' => -25.5, 'battery' => 150];

        $result = $sanitizer->sanitize('uuid-123', $payload);
        $this->assertEquals(25.5, $result['speed']);
        $this->assertEquals(100, $result['battery']);

    }
}
