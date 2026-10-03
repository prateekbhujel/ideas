<?php

declare(strict_types=1);

require __DIR__.'/core.php';
require __DIR__.'/device.php';

use Hari\{Action,DeviceKind,DeviceMesh,DeviceNode,DeviceRuntime,MemoryDeviceDriver,Risk};

$tests = [];

$tests['one mind can choose a phone body for a call'] = function (): void {
    $mesh = new DeviceMesh();
    $mesh->add(new DeviceNode('phone-main', DeviceKind::Phone, ['FIND_CONTACT', 'VIDEO_CALL'], priority: 2));
    $mesh->add(new DeviceNode('desk', DeviceKind::Computer, ['OPEN_FILE', 'RUN_CODE'], priority: 1));

    $phone = new MemoryDeviceDriver('phone-main');
    $desk = new MemoryDeviceDriver('desk');
    $runtime = new DeviceRuntime($mesh, [$phone, $desk]);

    $plan = [
        new Action('FIND_CONTACT', ['person' => 'Mom'], .95, Risk::Read),
        new Action('VIDEO_CALL', ['person' => 'Mom'], .95, Risk::External),
    ];

    eq('confirm', $runtime->run($plan)['why']);
    eq(0, count($phone->received()));

    $result = $runtime->run($plan, [1]);
    ok($result['ok']);
    eq(2, count($phone->received()));
    eq(0, count($desk->received()));
};

$tests['same action language can move across device kinds'] = function (): void {
    $mesh = new DeviceMesh();
    $mesh->add(new DeviceNode('phone', DeviceKind::Phone, ['SHOW_NOTIFICATION'], priority: 1));
    $mesh->add(new DeviceNode('watch', DeviceKind::Watch, ['SHOW_NOTIFICATION'], priority: 4));
    $mesh->add(new DeviceNode('tv', DeviceKind::Tv, ['PLAY_MEDIA'], priority: 1));

    $phone = new MemoryDeviceDriver('phone');
    $watch = new MemoryDeviceDriver('watch');
    $tv = new MemoryDeviceDriver('tv');
    $runtime = new DeviceRuntime($mesh, [$phone, $watch, $tv]);

    $a = $runtime->run([new Action('SHOW_NOTIFICATION', ['text' => 'tea'], .99, Risk::Reversible)]);
    ok($a['ok']);
    eq('watch', $a['results'][0]->device);

    $b = $runtime->run([new Action('PLAY_MEDIA', ['query' => 'news'], .99, Risk::Reversible)]);
    ok($b['ok']);
    eq('tv', $b['results'][0]->device);
};

$tests['explicit body selection is respected'] = function (): void {
    $mesh = new DeviceMesh();
    $mesh->add(new DeviceNode('phone-a', DeviceKind::Phone, ['OPEN_APP'], priority: 1));
    $mesh->add(new DeviceNode('phone-b', DeviceKind::Phone, ['OPEN_APP'], priority: 10));

    $a = new MemoryDeviceDriver('phone-a');
    $b = new MemoryDeviceDriver('phone-b');
    $runtime = new DeviceRuntime($mesh, [$a, $b]);

    $result = $runtime->run([new Action('OPEN_APP', ['app' => 'camera', '_device' => 'phone-a'], .99, Risk::Reversible)]);
    ok($result['ok']);
    eq('phone-a', $result['results'][0]->device);
};

$tests['preflight prevents partial execution when a later body is missing'] = function (): void {
    $mesh = new DeviceMesh();
    $mesh->add(new DeviceNode('phone', DeviceKind::Phone, ['OPEN_APP']));
    $driver = new MemoryDeviceDriver('phone');
    $runtime = new DeviceRuntime($mesh, [$driver]);

    $result = $runtime->run([
        new Action('OPEN_APP', ['app' => 'camera'], .99, Risk::Reversible),
        new Action('PLAY_MEDIA', ['query' => 'music'], .99, Risk::Reversible),
    ]);

    eq('no-device', $result['why']);
    eq(0, count($driver->received()));
};

$tests['experience can move work away from a failing body'] = function (): void {
    $mesh = new DeviceMesh();
    $mesh->add(new DeviceNode('phone-old', DeviceKind::Phone, ['SHOW_NOTIFICATION']));
    $mesh->add(new DeviceNode('phone-new', DeviceKind::Phone, ['SHOW_NOTIFICATION']));

    for ($i = 0; $i < 5; $i++) {
        $mesh->record('phone-old', 'SHOW_NOTIFICATION', false);
    }

    $node = $mesh->route(new Action('SHOW_NOTIFICATION', ['text' => 'hi'], .99, Risk::Reversible));
    eq('phone-new', $node?->id);
};

$tests['device topology and routing experience survive restart'] = function (): void {
    $mesh = new DeviceMesh();
    $mesh->add(new DeviceNode('phone', DeviceKind::Phone, ['OPEN_APP'], priority: 3));
    $mesh->add(new DeviceNode('pc', DeviceKind::Computer, ['OPEN_APP'], priority: 1));
    $mesh->record('phone', 'OPEN_APP', false);
    $mesh->record('phone', 'OPEN_APP', false);
    $mesh->record('pc', 'OPEN_APP', true);

    $copy = DeviceMesh::import($mesh->export());
    $node = $copy->route(new Action('OPEN_APP', ['app' => 'browser'], .99, Risk::Reversible));
    eq('pc', $node?->id);

    for ($i = 0; $i < 8; $i++) {
        $copy->record('phone', 'OPEN_APP', true);
        $copy->record('pc', 'OPEN_APP', false);
    }
    eq('phone', $copy->route(new Action('OPEN_APP', ['app' => 'browser'], .99, Risk::Reversible))?->id);
};

$n = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        ++$n;
        echo "PASS  {$name}\n";
    } catch (Throwable $e) {
        fwrite(STDERR, "FAIL  {$name}\n{$e->getMessage()}\n");
        exit(1);
    }
}

echo "\n{$n}/".count($tests)." passed\n";

function eq(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('expected '.var_export($expected, true).' got '.var_export($actual, true));
    }
}

function ok(bool $value): void
{
    if (!$value) {
        throw new RuntimeException('assertion failed');
    }
}
