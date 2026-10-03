<?php

declare(strict_types=1);

namespace Hari;

enum DeviceKind: string
{
    case Phone = 'phone';
    case Watch = 'watch';
    case Computer = 'computer';
    case Tv = 'tv';
    case Other = 'other';
}

final class DeviceNode
{
    /** @param list<string> $operations */
    public function __construct(
        public readonly string $id,
        public readonly DeviceKind $kind,
        public readonly array $operations,
        public bool $online = true,
        public int $priority = 0,
    ) {
        if ($id === '') {
            throw new \InvalidArgumentException('device id required');
        }
    }

    public function supports(string $operation): bool
    {
        return in_array($operation, $this->operations, true);
    }

    /** @return array<string,mixed> */
    public function export(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'operations' => $this->operations,
            'online' => $this->online,
            'priority' => $this->priority,
        ];
    }
}

final class DeviceMesh
{
    /** @var array<string,DeviceNode> */
    private array $nodes = [];

    /** @var array<string,array<string,array{ok:int,bad:int}>> */
    private array $history = [];

    public function add(DeviceNode $node): void
    {
        $this->nodes[$node->id] = $node;
        $this->history[$node->id] ??= [];
    }

    public function setOnline(string $id, bool $online): void
    {
        $this->node($id)->online = $online;
    }

    public function record(string $id, string $operation, bool $success): void
    {
        $this->node($id);
        $this->history[$id][$operation] ??= ['ok' => 0, 'bad' => 0];
        ++$this->history[$id][$operation][$success ? 'ok' : 'bad'];
    }

    public function route(Action $action, ?string $preferredDevice = null): ?DeviceNode
    {
        $explicit = $action->args['_device'] ?? null;
        if (is_string($explicit) && $explicit !== '') {
            $node = $this->nodes[$explicit] ?? null;
            return $node !== null && $node->online && $node->supports($action->op) ? $node : null;
        }

        if ($preferredDevice !== null) {
            $node = $this->nodes[$preferredDevice] ?? null;
            if ($node !== null && $node->online && $node->supports($action->op)) {
                return $node;
            }
        }

        $best = null;
        $bestScore = -INF;
        foreach ($this->nodes as $node) {
            if (!$node->online || !$node->supports($action->op)) {
                continue;
            }

            $h = $this->history[$node->id][$action->op] ?? ['ok' => 0, 'bad' => 0];
            $total = $h['ok'] + $h['bad'];
            $reliability = ($h['ok'] + 1) / ($total + 2);
            $score = $node->priority + ($reliability * 10);

            if ($score > $bestScore) {
                $best = $node;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /** @return array<string,mixed> */
    public function export(): array
    {
        return [
            'nodes' => array_map(static fn(DeviceNode $node): array => $node->export(), array_values($this->nodes)),
            'history' => $this->history,
        ];
    }

    /** @param array<string,mixed> $data */
    public static function import(array $data): self
    {
        $mesh = new self();
        foreach ($data['nodes'] ?? [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $mesh->add(new DeviceNode(
                (string)($row['id'] ?? ''),
                DeviceKind::from((string)($row['kind'] ?? DeviceKind::Other->value)),
                array_values(array_map('strval', is_array($row['operations'] ?? null) ? $row['operations'] : [])),
                (bool)($row['online'] ?? true),
                (int)($row['priority'] ?? 0),
            ));
        }

        foreach ($data['history'] ?? [] as $id => $ops) {
            if (!isset($mesh->nodes[$id]) || !is_array($ops)) {
                continue;
            }
            foreach ($ops as $op => $h) {
                if (!is_array($h)) {
                    continue;
                }
                $mesh->history[$id][(string)$op] = [
                    'ok' => (int)($h['ok'] ?? 0),
                    'bad' => (int)($h['bad'] ?? 0),
                ];
            }
        }

        return $mesh;
    }

    private function node(string $id): DeviceNode
    {
        return $this->nodes[$id] ?? throw new \OutOfBoundsException("unknown device: {$id}");
    }
}

final readonly class DeviceResult
{
    /** @param array<string,scalar|null> $data */
    public function __construct(
        public bool $ok,
        public string $device,
        public string $operation,
        public array $data = [],
        public ?string $error = null,
    ) {}
}

interface DeviceDriver
{
    public function deviceId(): string;
    public function execute(Action $action): DeviceResult;
}

final class MemoryDeviceDriver implements DeviceDriver
{
    /** @var list<Action> */
    private array $received = [];

    /** @param list<string> $failOperations */
    public function __construct(
        private readonly string $id,
        private array $failOperations = [],
    ) {}

    public function deviceId(): string
    {
        return $this->id;
    }

    public function execute(Action $action): DeviceResult
    {
        $this->received[] = $action;
        if (in_array($action->op, $this->failOperations, true)) {
            return new DeviceResult(false, $this->id, $action->op, error: 'driver failure');
        }

        return new DeviceResult(true, $this->id, $action->op, ['accepted' => true]);
    }

    /** @return list<Action> */
    public function received(): array
    {
        return $this->received;
    }
}

final class DeviceRuntime
{
    /** @var array<string,DeviceDriver> */
    private array $drivers = [];

    /** @param iterable<DeviceDriver> $drivers */
    public function __construct(
        private readonly DeviceMesh $mesh,
        iterable $drivers,
    ) {
        foreach ($drivers as $driver) {
            $this->drivers[$driver->deviceId()] = $driver;
        }
    }

    /**
     * @param list<Action> $plan
     * @param list<int> $confirmed
     * @return array{ok:bool,at:?int,why:?string,results:list<DeviceResult>}
     */
    public function run(array $plan, array $confirmed = [], ?string $preferredDevice = null): array
    {
        /** @var list<array{action:Action,node:DeviceNode,driver:DeviceDriver}> $prepared */
        $prepared = [];

        foreach ($plan as $i => $action) {
            if ($action->confidence < .70) {
                return ['ok' => false, 'at' => $i, 'why' => 'uncertain', 'results' => []];
            }
            if ($action->risk === Risk::External && !in_array($i, $confirmed, true)) {
                return ['ok' => false, 'at' => $i, 'why' => 'confirm', 'results' => []];
            }

            $node = $this->mesh->route($action, $preferredDevice);
            if ($node === null) {
                return ['ok' => false, 'at' => $i, 'why' => 'no-device', 'results' => []];
            }

            $driver = $this->drivers[$node->id] ?? null;
            if ($driver === null) {
                return ['ok' => false, 'at' => $i, 'why' => 'no-driver', 'results' => []];
            }

            $prepared[] = ['action' => $action, 'node' => $node, 'driver' => $driver];
        }

        $results = [];
        foreach ($prepared as $i => $step) {
            $result = $step['driver']->execute($step['action']);
            $results[] = $result;
            $this->mesh->record($step['node']->id, $step['action']->op, $result->ok);

            if (!$result->ok) {
                return ['ok' => false, 'at' => $i, 'why' => 'driver', 'results' => $results];
            }
        }

        return ['ok' => true, 'at' => null, 'why' => null, 'results' => $results];
    }
}
