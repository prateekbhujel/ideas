<?php

declare(strict_types=1);

namespace Hari\Speech;

/**
 * HARI Speech Client in PHP.
 * Communicates with the disposable/replaceable acoustic worker via JSON over stdio pipes.
 * Enforces worker isolation: PHP HARI remains the master process.
 */
class SpeechClient
{
    /** @var resource|null */
    private $process = null;
    /** @var array<int, resource> */
    private array $pipes = [];
    private string $pythonBin;
    private string $workerScript;
    private bool $isAlive = false;

    public function __construct(
        string $pythonBin = '/Volumes/DEV-T7/Projects/hari-scratch/venv/bin/python',
        string $workerScript = __DIR__ . '/../workers/speech_worker.py'
    ) {
        $this->pythonBin = $pythonBin;
        $this->workerScript = $workerScript;
        $this->startWorker();
    }

    public function startWorker(): bool
    {
        if ($this->isAlive && is_resource($this->process)) {
            return true;
        }

        $cmd = escapeshellarg($this->pythonBin) . ' ' . escapeshellarg($this->workerScript);
        $desc = [
            0 => ['pipe', 'r'], // stdin
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr
        ];

        $this->process = proc_open($cmd, $desc, $this->pipes);
        if (!is_resource($this->process)) {
            $this->isAlive = false;
            return false;
        }

        stream_set_blocking($this->pipes[1], true);
        $this->isAlive = true;
        return true;
    }

    public function call(array $payload, float $timeout = 10.0): array
    {
        if (!$this->startWorker()) {
            return ['status' => 'error', 'message' => 'Speech worker process unavailable'];
        }

        $jsonStr = json_encode($payload) . "\n";
        $written = @fwrite($this->pipes[0], $jsonStr);
        if ($written === false) {
            $this->isAlive = false;
            return ['status' => 'error', 'message' => 'Failed to write to speech worker'];
        }
        @fflush($this->pipes[0]);

        $line = @fgets($this->pipes[1]);
        if ($line === false) {
            $this->isAlive = false;
            return ['status' => 'error', 'message' => 'Speech worker closed stream unexpectedly'];
        }

        $decoded = json_decode(trim($line), true);
        return is_array($decoded) ? $decoded : ['status' => 'error', 'message' => 'Invalid JSON from worker'];
    }

    public function ping(): bool
    {
        $res = $this->call(['cmd' => 'ping']);
        return ($res['status'] ?? '') === 'pong';
    }

    public function synthesize(string $text, string $voice = 'am_adam', float $speed = 1.05): ?string
    {
        $res = $this->call([
            'cmd' => 'synthesize',
            'text' => $text,
            'voice' => $voice,
            'speed' => $speed,
        ]);
        return $res['audio_path'] ?? null;
    }

    public function play(string $audioPath): bool
    {
        $res = $this->call(['cmd' => 'play', 'path' => $audioPath]);
        return ($res['status'] ?? '') === 'ok';
    }

    public function stop(): bool
    {
        $res = $this->call(['cmd' => 'stop']);
        return ($res['status'] ?? '') === 'ok';
    }

    public function checkQuality(string $audioPath): array
    {
        return $this->call(['cmd' => 'check_quality', 'path' => $audioPath]);
    }

    public function close(): void
    {
        if (is_resource($this->process)) {
            @fwrite($this->pipes[0], json_encode(['cmd' => 'quit']) . "\n");
            @fclose($this->pipes[0]);
            @fclose($this->pipes[1]);
            @fclose($this->pipes[2]);
            proc_close($this->process);
        }
        $this->process = null;
        $this->isAlive = false;
    }

    public function __destruct()
    {
        $this->close();
    }
}
