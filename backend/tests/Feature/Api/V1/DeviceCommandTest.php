<?php

namespace Tests\Feature\Api\V1;

use App\Events\DeviceCommandUpdated;
use App\Models\Computer;
use App\Models\DeviceCommand;
use App\Models\LoginSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsClassroomFixtures;
use Tests\TestCase;

class DeviceCommandTest extends TestCase
{
    use BuildsClassroomFixtures, RefreshDatabase;

    private function makeCommand(Computer $device, ?LoginSession $session, array $overrides = []): DeviceCommand
    {
        return DeviceCommand::create([
            'uuid' => (string) Str::uuid(),
            'school_id' => $device->school_id,
            'classroom_id' => $device->classroom_id,
            'computer_id' => $device->id,
            'login_session_id' => $session?->id,
            'issued_by' => \App\Models\User::factory()->create()->id,
            'type' => 'browser.open_url',
            'payload' => ['url' => 'https://example.com'],
            'status' => 'pending',
            'expires_at' => now()->addMinute(),
            ...$overrides,
        ]);
    }

    public function test_a_pending_command_is_delivered_once_in_the_contract_envelope(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $device = $this->makeDevice($school, $classroom);
        $session = $this->signInStudent($device);
        $command = $this->makeCommand($device, $session);
        $headers = $this->deviceHeaders($device);

        $first = $this->withHeaders($headers)->getJson('/api/v1/device/commands');
        $first->assertOk()->assertJsonCount(1, 'data.commands');
        $first->assertJsonPath('data.commands.0.id', $command->uuid);
        $first->assertJsonPath('data.commands.0.version', 1);
        $first->assertJsonPath('data.commands.0.student_session_id', $session->uuid);
        $first->assertJsonPath('data.commands.0.payload.url', 'https://example.com');

        $this->withHeaders($headers)->getJson('/api/v1/device/commands')->assertJsonCount(0, 'data.commands');
        $this->assertSame('delivered', $command->fresh()->status);
    }

    public function test_a_command_for_a_previous_student_session_is_expired_instead_of_delivered(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $device = $this->makeDevice($school, $classroom);
        $old = $this->signInStudent($device, '1001');
        $command = $this->makeCommand($device, $old);
        $old->update(['status' => 'ended', 'logout_time' => now()]);
        $this->signInStudent($device, '1002');

        $this->withHeaders($this->deviceHeaders($device))->getJson('/api/v1/device/commands')->assertJsonCount(0, 'data.commands');

        $command->refresh();
        $this->assertSame('expired', $command->status);
        $this->assertSame('SESSION_CHANGED', $command->result['error']);
    }

    public function test_an_overdue_command_is_expired_and_never_delivered(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $device = $this->makeDevice($school, $classroom);
        $session = $this->signInStudent($device);
        $command = $this->makeCommand($device, $session, ['expires_at' => now()->subSecond()]);

        $this->withHeaders($this->deviceHeaders($device))->getJson('/api/v1/device/commands')->assertJsonCount(0, 'data.commands');

        $this->assertSame('expired', $command->fresh()->status);
    }

    public function test_a_device_never_receives_another_devices_commands(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $device = $this->makeDevice($school, $classroom);
        $other = $this->makeDevice($school, $classroom);
        $this->makeCommand($other, $this->signInStudent($other));

        $this->withHeaders($this->deviceHeaders($device))->getJson('/api/v1/device/commands')->assertJsonCount(0, 'data.commands');
    }

    public function test_the_device_reports_a_result_and_the_classroom_is_notified(): void
    {
        Event::fake();
        [$school, $classroom] = $this->makeClassroom();
        $device = $this->makeDevice($school, $classroom);
        $command = $this->makeCommand($device, $this->signInStudent($device), ['status' => 'delivered']);

        $this->withHeaders($this->deviceHeaders($device))->postJson("/api/v1/device/commands/{$command->uuid}/result", [
            'status' => 'completed', 'result' => ['tab_id' => 12],
        ])->assertOk()->assertJsonPath('data.status', 'completed');

        $this->assertSame(12, $command->fresh()->result['tab_id']);
        Event::assertDispatched(DeviceCommandUpdated::class);
    }

    public function test_a_finished_command_cannot_be_overwritten(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $device = $this->makeDevice($school, $classroom);
        $command = $this->makeCommand($device, $this->signInStudent($device), ['status' => 'expired']);

        $this->withHeaders($this->deviceHeaders($device))->postJson("/api/v1/device/commands/{$command->uuid}/result", ['status' => 'completed'])
            ->assertOk()->assertJsonPath('data.status', 'expired');
    }

    public function test_a_device_cannot_report_results_for_another_devices_command(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $device = $this->makeDevice($school, $classroom);
        $other = $this->makeDevice($school, $classroom);
        $command = $this->makeCommand($other, $this->signInStudent($other));

        $this->withHeaders($this->deviceHeaders($device))->postJson("/api/v1/device/commands/{$command->uuid}/result", ['status' => 'completed'])->assertNotFound();
    }

    public function test_the_scheduled_job_expires_overdue_commands(): void
    {
        [$school, $classroom] = $this->makeClassroom();
        $device = $this->makeDevice($school, $classroom);
        $session = $this->signInStudent($device);
        $overdue = $this->makeCommand($device, $session, ['expires_at' => now()->subMinute(), 'status' => 'delivered']);
        $live = $this->makeCommand($device, $session);

        $this->artisan('device-commands:expire')->assertSuccessful();

        $this->assertSame('expired', $overdue->fresh()->status);
        $this->assertSame('pending', $live->fresh()->status);
    }
}
