<?php

use App\Services\Bandeja\ImapSyncService;
use Carbon\CarbonInterface;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Webklex\PHPIMAP\Attribute;

uses(RefreshDatabase::class)->in(__DIR__);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('has ImapSyncService class available', function () {
    expect(class_exists(ImapSyncService::class))->toBeTrue();
});

it('converts webklex date attributes into carbon instances', function () {
    $service = new class extends ImapSyncService
    {
        public function parseDate(mixed $date): CarbonInterface
        {
            return $this->resolveReceivedAt($date);
        }
    };

    $date = $service->parseDate(new Attribute('date', '2026-05-18 10:15:00'));

    expect($date)->toBeInstanceOf(CarbonInterface::class)
        ->and($date->format('Y-m-d H:i:s'))->toBe('2026-05-18 10:15:00');
});
