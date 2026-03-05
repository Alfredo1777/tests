<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Modules\GPS\Services\MessageValidatorService;
use App\Modules\GPS\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;

class MessageValidatorServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_detects_missing_required_fields()
    {
        $validator = new MessageValidatorService();
        $device = Device::factory()->create(['uuid' => 'test-uuid']);
        $payload = json_encode(['latitude' => 19.0, 'longitude' => -104.0]);
        $errors = $validator->findErrors('test-uuid', $payload);

        $this->assertNotEmpty($errors);        
        $this->assertEquals('schema_error', $errors[0]['type']);

    }

    public function test_rejects_unregistered_devices()
    {
        $validator = new MessageValidatorService();
        //UUID no existe en la BD
        $errors = $validator->findErrors('fake-uuid', '{"lat": 10}');

        $this->asseertEquals('business_rule', $errors[0]['type']);        
        $this->asseertEquals('critical', $errors[0]['severity']);
    }
}
