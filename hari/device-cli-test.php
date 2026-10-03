<?php

declare(strict_types=1);

$root = __DIR__;
$state = sys_get_temp_dir().'/hari-mesh-'.bin2hex(random_bytes(4)).'.json';

try {
    run(['op'=>'device_add','id'=>'phone','kind'=>'phone','operations'=>['VIDEO_CALL','SHOW_NOTIFICATION'],'priority'=>3]);
    run(['op'=>'device_add','id'=>'watch','kind'=>'watch','operations'=>['SHOW_NOTIFICATION'],'priority'=>5]);
    run(['op'=>'device_add','id'=>'pc','kind'=>'computer','operations'=>['RUN_CODE'],'priority'=>2]);

    $call = run(['op'=>'device_route','action'=>['op'=>'VIDEO_CALL','args'=>['person'=>'Mom'],'confidence'=>.95,'risk'=>2]]);
    if (($call['result']['id'] ?? null) !== 'phone') fail('phone routing failed');

    $notice = run(['op'=>'device_route','action'=>['op'=>'SHOW_NOTIFICATION','args'=>['text'=>'tea'],'confidence'=>.99,'risk'=>1]]);
    if (($notice['result']['id'] ?? null) !== 'watch') fail('watch routing failed');

    run(['op'=>'device_online','id'=>'watch','online'=>false]);
    $fallback = run(['op'=>'device_route','action'=>['op'=>'SHOW_NOTIFICATION','args'=>['text'=>'tea'],'confidence'=>.99,'risk'=>1]]);
    if (($fallback['result']['id'] ?? null) !== 'phone') fail('offline fallback failed');

    for ($i=0; $i<8; $i++) {
        run(['op'=>'device_record','id'=>'phone','operation'=>'SHOW_NOTIFICATION','success'=>false]);
    }
    run(['op'=>'device_online','id'=>'watch','online'=>true]);
    $learned = run(['op'=>'device_route','action'=>['op'=>'SHOW_NOTIFICATION','args'=>['text'=>'tea'],'confidence'=>.99,'risk'=>1]]);
    if (($learned['result']['id'] ?? null) !== 'watch') fail('experience routing failed');

    $status = run(['op'=>'device_status']);
    if (count($status['result']['nodes'] ?? []) !== 3) fail('mesh did not persist');

    echo "PASS  persistent device protocol\n";
} finally {
    @unlink($state);
}

function run(array $command): array
{
    global $root, $state;
    $json = json_encode($command, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/device-cli.php').' '.escapeshellarg($state).' '.escapeshellarg($json);
    exec($cmd, $lines, $code);
    $raw = implode("\n", $lines);
    $data = json_decode($raw, true);
    if ($code !== 0 || !is_array($data) || ($data['ok'] ?? false) !== true) fail("command failed: {$raw}");
    return $data;
}

function fail(string $message): never
{
    throw new RuntimeException($message);
}
