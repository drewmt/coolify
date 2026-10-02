<?php

use App\Livewire\SettingsBackup;
use App\Models\ScheduledDatabaseBackup;
use App\Models\Server;
use App\Models\StandalonePostgresql;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function instanceBackupDatabase(): StandalonePostgresql
{
    $stack = Server::unguarded(fn () => seedBrowserResourceStack());
    test()->actingAs($stack['user']);
    session(['currentTeam' => $stack['user']->teams()->findOrFail(0)]);

    return StandalonePostgresql::forceCreate([
        'id' => 0,
        'name' => 'coolify-db',
        'description' => 'Existing instance database',
        'postgres_user' => 'coolify',
        'postgres_password' => 'existing-password',
        'postgres_db' => 'coolify',
        'status' => 'running',
        'destination_id' => $stack['destination']->id,
        'destination_type' => $stack['destination']->getMorphClass(),
    ]);
}

it('renders the unconfigured backup state for an existing instance database without a schedule', function () {
    $database = instanceBackupDatabase();

    Livewire::test(SettingsBackup::class)
        ->assertSee('Backup is not configured')
        ->assertSet('executions', []);

    expect($database->scheduledBackups()->count())->toBe(0);
});

it('configures a missing schedule without replacing the existing instance database', function () {
    $database = instanceBackupDatabase();
    $uuid = $database->uuid;
    Process::fake();

    Livewire::test(SettingsBackup::class)
        ->call('addCoolifyDatabase')
        ->assertNotDispatched('error')
        ->assertDontSee('Backup is not configured');

    expect(StandalonePostgresql::count())->toBe(1);
    expect($database->fresh()->uuid)->toBe($uuid);
    expect($database->fresh()->postgres_password)->toBe('existing-password');
    expect($database->scheduledBackups()->count())->toBe(1);
    Process::assertNothingRan();
});

it('keeps an existing schedule when configuration is requested again', function () {
    $database = instanceBackupDatabase();
    $backup = $database->scheduledBackups()->create([
        'team_id' => 0,
        'enabled' => false,
        'save_s3' => false,
        'frequency' => '0 6 * * *',
    ]);
    Process::fake();

    Livewire::test(SettingsBackup::class)
        ->call('addCoolifyDatabase')
        ->assertNotDispatched('error');

    expect(ScheduledDatabaseBackup::count())->toBe(1);
    expect($backup->fresh()->frequency)->toBe('0 6 * * *');
    expect((bool) $backup->fresh()->enabled)->toBeFalse();
    Process::assertNothingRan();
});

it('configures the instance database and schedule on first setup', function () {
    $stack = Server::unguarded(fn () => seedBrowserResourceStack());
    $this->actingAs($stack['user']);
    session(['currentTeam' => $stack['user']->teams()->findOrFail(0)]);
    Process::fake([
        '*docker inspect coolify-db*' => Process::result(output: '[{"Config":{"Env":["POSTGRES_USER=coolify","POSTGRES_PASSWORD=setup-password","POSTGRES_DB=coolify"]}}]'),
    ]);

    Livewire::test(SettingsBackup::class)
        ->call('addCoolifyDatabase')
        ->assertNotDispatched('error')
        ->assertDontSee('Backup is not configured');

    $database = StandalonePostgresql::findOrFail(0);
    expect($database->postgres_password)->toBe('setup-password');
    expect($database->scheduledBackups()->count())->toBe(1);
    expect($database->scheduledBackups()->first()->id)->toBeGreaterThan(0);
});

it('redirects non-instance administrators without creating a backup schedule', function () {
    $database = instanceBackupDatabase();
    $this->actingAs(User::factory()->create());

    Livewire::test(SettingsBackup::class)->assertRedirect(route('dashboard'));

    expect($database->scheduledBackups()->count())->toBe(0);
});
