<?php

use App\Jobs\CheckAndStartSentinelJob;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('keeps a running up-to-date Sentinel on servers that require sudo for Docker', function (string $sshUser) {
    DB::table('instance_settings')->insert(['id' => 0, 'fqdn' => 'https://coolify.example.test']);
    $user = User::factory()->create();
    $key = PrivateKey::create([
        'name' => 'Sentinel test key',
        'private_key' => browserTestPrivateKeyPem(),
        'team_id' => $user->teams()->first()->id,
    ]);
    Storage::fake('ssh-keys');
    $server = Server::factory()->create([
        'team_id' => $key->team_id,
        'private_key_id' => $key->id,
        'user' => $sshUser,
    ]);
    $heartbeat = now()->subMinute();
    $server->forceFill(['sentinel_updated_at' => $heartbeat])->save();
    $storedHeartbeat = $server->fresh()->sentinel_updated_at;
    config(['constants.ssh.mux_enabled' => false]);
    Http::preventStrayRequests();
    Http::fake([
        config('constants.coolify.versions_url') => Http::response(['coolify' => ['sentinel' => ['version' => '1.0.1']]]),
    ]);
    Process::fake(function ($process) use ($sshUser) {
        if (str_contains($process->command, 'docker inspect coolify-sentinel')) {
            if ($sshUser !== 'root' && ! str_contains($process->command, "\nsudo docker inspect coolify-sentinel\n")) {
                return Process::result(errorOutput: 'permission denied while trying to connect to the Docker API', exitCode: 1);
            }

            return Process::result(output: '[{"State":{"Status":"running"}}]');
        }
        if (str_contains($process->command, 'api/version')) {
            return Process::result(output: '1.0.1');
        }

        return Process::result();
    });

    (new CheckAndStartSentinelJob($server))->handle();

    Process::assertNotRan(fn ($process) => str_contains($process->command, 'docker rm') || str_contains($process->command, 'docker run'));
    expect($server->fresh()->sentinel_updated_at)->toBe($storedHeartbeat);
    Process::assertRan(fn ($process) => str_contains($process->command, 'docker inspect coolify-sentinel'));
})->with(['non-root user' => 'coolify', 'root user' => 'root']);

it('treats Sentinel as enabled for regular servers even when the legacy flag and metrics are disabled', function () {
    DB::table('instance_settings')->insert(['id' => 0]);
    $user = User::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $user->teams()->first()->id,
    ]);
    $server->settings->update([
        'is_metrics_enabled' => false,
        'is_sentinel_enabled' => false,
    ]);

    expect($server->fresh()->isSentinelEnabled())->toBeTrue();
});

it('does not enable Sentinel for excluded server types', function (array $settings, array $metadata = []) {
    DB::table('instance_settings')->insert(['id' => 0]);
    $user = User::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $user->teams()->first()->id,
        'server_metadata' => $metadata,
    ]);
    $server->settings->update(array_merge([
        'is_metrics_enabled' => false,
        'is_sentinel_enabled' => true,
    ], $settings));

    expect($server->fresh()->isSentinelEnabled())->toBeFalse();
})->with([
    'build server' => [['server_role' => 'build', 'is_build_server' => true]],
    'swarm manager' => [['is_swarm_manager' => true]],
    'swarm worker' => [['is_swarm_worker' => true]],
    'transferred server' => [[], ['transfer' => ['status' => 'transferred']]],
    'force-disabled server' => [['force_disabled' => true]],
]);

it('keeps metrics optional while Sentinel remains enabled', function () {
    DB::table('instance_settings')->insert(['id' => 0]);
    $user = User::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $user->teams()->first()->id,
    ]);
    $server->settings->update(['is_metrics_enabled' => false]);

    expect($server->fresh()->isSentinelEnabled())->toBeTrue()
        ->and((bool) $server->settings->fresh()->is_metrics_enabled)->toBeFalse();
});
