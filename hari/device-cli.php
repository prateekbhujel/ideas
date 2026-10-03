<?php

declare(strict_types=1);

require __DIR__.'/core.php';
require __DIR__.'/device.php';

use Hari\{Action,DeviceKind,DeviceMesh,DeviceNode,Risk};

$state = $argv[1] ?? null;
if ($state === null) {
    fwrite(STDERR, "usage: php hari/device-cli.php <mesh-state> [json-command]\n");
    exit(64);
}

$raw = $argv[2] ?? stream_get_contents(STDIN);
if (trim((string)$raw) === '') {
    fwrite(STDERR, "missing JSON command\n");
    exit(64);
}

try {
    $command = json_decode((string)$raw, true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($command)) {
        throw new InvalidArgumentException('command must be a JSON object');
    }

    $mesh = loadMesh($state);
    $op = (string)($command['op'] ?? '');

    $result = match ($op) {
        'device_add' => addDevice($mesh, $command),
        'device_online' => setOnline($mesh, $command),
        'device_record' => recordOutcome($mesh, $command),
        'device_route' => routeAction($mesh, $command),
        'device_status' => $mesh->export(),
        default => throw new InvalidArgumentException("unknown op: {$op}"),
    };

    saveMesh($state, $mesh);
    echo json_encode(['ok' => true, 'result' => $result], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    exit(1);
}

/** @param array<string,mixed> $c */
function addDevice(DeviceMesh $mesh, array $c): array
{
    $id = requiredString($c, 'id');
    $kind = DeviceKind::from(requiredString($c, 'kind'));
    $operations = stringList($c['operations'] ?? []);
    if ($operations === []) {
        throw new InvalidArgumentException('operations required');
    }

    $mesh->add(new DeviceNode(
        $id,
        $kind,
        $operations,
        (bool)($c['online'] ?? true),
        (int)($c['priority'] ?? 0),
    ));

    return ['id' => $id, 'kind' => $kind->value];
}

/** @param array<string,mixed> $c */
function setOnline(DeviceMesh $mesh, array $c): array
{
    $id = requiredString($c, 'id');
    $online = (bool)($c['online'] ?? true);
    $mesh->setOnline($id, $online);
    return ['id' => $id, 'online' => $online];
}

/** @param array<string,mixed> $c */
function recordOutcome(DeviceMesh $mesh, array $c): array
{
    $id = requiredString($c, 'id');
    $operation = requiredString($c, 'operation');
    $success = (bool)($c['success'] ?? false);
    $mesh->record($id, $operation, $success);
    return ['id' => $id, 'operation' => $operation, 'success' => $success];
}

/** @param array<string,mixed> $c */
function routeAction(DeviceMesh $mesh, array $c): ?array
{
    $row = $c['action'] ?? null;
    if (!is_array($row)) {
        throw new InvalidArgumentException('action required');
    }

    $args = is_array($row['args'] ?? null) ? $row['args'] : [];
    $action = new Action(
        requiredString($row, 'op'),
        $args,
        (float)($row['confidence'] ?? 1),
        Risk::from((int)($row['risk'] ?? 0)),
    );

    $node = $mesh->route($action, isset($c['preferred']) ? (string)$c['preferred'] : null);
    return $node === null ? null : $node->export();
}

function loadMesh(string $path): DeviceMesh
{
    if (!is_file($path)) {
        return new DeviceMesh();
    }

    $data = json_decode((string)file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    return DeviceMesh::import(is_array($data) ? $data : []);
}

function saveMesh(string $path, DeviceMesh $mesh): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('mesh directory');
    }

    $payload = json_encode($mesh->export(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $tmp = $path.'.tmp.'.bin2hex(random_bytes(4));
    if (file_put_contents($tmp, $payload, LOCK_EX) === false) {
        throw new RuntimeException('mesh write');
    }
    @chmod($tmp, 0600);
    if (!rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('mesh commit');
    }
}

/** @param array<string,mixed> $row */
function requiredString(array $row, string $key): string
{
    $value = trim((string)($row[$key] ?? ''));
    if ($value === '') {
        throw new InvalidArgumentException("{$key} required");
    }
    return $value;
}

/** @return list<string> */
function stringList(mixed $value): array
{
    if (!is_array($value)) {
        throw new InvalidArgumentException('expected string list');
    }
    return array_values(array_map('strval', $value));
}
