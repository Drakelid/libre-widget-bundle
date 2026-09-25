<?php

namespace Drakelid\NmsDashWidgets\Support;

use App\Facades\LibrenmsConfig;
use App\Facades\Rrd;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;

/** Read-only RRD history. Call only after permission checks and display limiting. */
final class History
{
    private static ?float $started = null;
    private static int $reads = 0;

    public static function sensorTrend(object $sensor, int $hours = 24): array
    {
        $hours = max(1, min(168, $hours));
        try {
            $device = $sensor->device;
            if (! $device) {
                return self::unavailable('Device unavailable');
            }
            $useDescription = ($sensor->poller_type ?? '') === 'ipmi'
                || LibrenmsConfig::getOsSetting($device->os, 'sensor_descr');
            $file = Rrd::name($device->hostname, ['sensor', $sensor->sensor_class, $sensor->sensor_type,
                $useDescription ? $sensor->sensor_descr : $sensor->sensor_index]);
            $end = intdiv(time(), 300) * 300;
            $series = self::fetch($file, $end - $hours * 3600, $end);
            if (! $series['available']) {
                return $series;
            }

            return self::trend($series['points'], $end - $hours * 3600, $end);
        } catch (\Throwable) {
            return self::unavailable('History unavailable');
        }
    }

    public static function portCongestion(object $port, float $percent, int $minutes = 15): array
    {
        $minutes = max(5, min(1440, $minutes));
        try {
            if (! $port->device || (float) $port->ifSpeed <= 0) {
                return self::unavailable('Interface speed or device unavailable');
            }
            $file = Rrd::name($port->device->hostname, Rrd::portName($port->port_id));
            $end = intdiv(time(), 300) * 300;
            $start = $end - $minutes * 60;
            $series = self::fetch($file, $start - 600, $end);
            if (! $series['available']) {
                return $series;
            }

            return self::congestion($series['points'], (float) $port->ifSpeed, $percent, $start, $end);
        } catch (\Throwable) {
            return self::unavailable('History unavailable');
        }
    }

    /** Parse RRDtool fetch output, retaining unknown samples as null, not zero. */
    public static function parse(string $output): array
    {
        $columns = [];
        $points = [];
        foreach (preg_split('/\r?\n/', trim($output)) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, 'OK ')) {
                continue;
            }
            if (preg_match('/^(\d+):\s+(.+)$/', $line, $match)) {
                $values = preg_split('/\s+/', trim($match[2]));
                $point = ['time' => (int) $match[1]];
                foreach ($columns as $i => $column) {
                    $value = $values[$i] ?? null;
                    $point[$column] = is_numeric($value) && is_finite((float) $value) ? (float) $value : null;
                }
                $points[] = $point;
            } elseif ($columns === [] && preg_match('/^[A-Za-z_][A-Za-z0-9_\s]*$/', $line)) {
                $columns = preg_split('/\s+/', $line);
            }
        }

        return $points;
    }

    public static function trend(array $points, int $start, int $end): array
    {
        $points = array_values(array_filter($points, fn ($p) => $p['time'] >= $start && $p['time'] <= $end));
        $valid = [];
        foreach ($points as $point) {
            $value = $point['sensor'] ?? null;
            if ($value !== null && is_finite((float) $value)) {
                $valid[] = ['time' => $point['time'], 'value' => (float) $value];
            }
        }
        $tolerance = max(600, (int) (($end - $start) / 100));
        for ($i = 1; $i < count($points); $i++) {
            $gap = $points[$i]['time'] - $points[$i - 1]['time'];
            if ($gap <= 0 || $gap > $tolerance * 2) {
                return self::unavailable('History contains missing intervals');
            }
        }
        if (count($valid) < 2 || $valid[0]['time'] > $start + $tolerance
            || end($valid)['time'] < $end - $tolerance || count($valid) < count($points) * 0.8) {
            return self::unavailable('Insufficient history for this window');
        }

        return ['available' => true, 'delta' => end($valid)['value'] - $valid[0]['value'],
            'points' => $valid, 'reason' => null, 'start' => $valid[0]['time'], 'end' => end($valid)['time']];
    }

    /** All intervals must be known and above threshold; a gap is never healthy. */
    public static function congestion(array $points, float $speed, float $percent, int $start, int $end): array
    {
        $points = array_values(array_filter($points, fn ($p) => $p['time'] > $start && $p['time'] <= $end));
        if ($speed <= 0 || count($points) < 2) {
            return self::unavailable('Insufficient history for this window');
        }
        $step = $points[1]['time'] - $points[0]['time'];
        if ($step <= 0 || $step > ($end - $start) / 2 || $points[0]['time'] > $start + $step || end($points)['time'] < $end) {
            return self::unavailable('History does not cover the complete window');
        }
        $last = $points[0]['time'] - $step;
        $sustained = true;
        $values = [];
        foreach ($points as $point) {
            $in = $point['INOCTETS'] ?? null;
            $out = $point['OUTOCTETS'] ?? null;
            if ($in === null || $out === null || $in < 0 || $out < 0 || $point['time'] - $last !== $step) {
                return self::unavailable('History contains missing readings');
            }
            $utilisation = max($in, $out) * 8 / $speed * 100;
            $sustained = $sustained && $utilisation >= $percent;
            $values[] = ['time' => $point['time'], 'value' => $utilisation];
            $last = $point['time'];
        }

        return ['available' => true, 'sustained' => $sustained, 'minutes' => ($end - $start) / 60,
            'points' => $values, 'reason' => null, 'delta' => null];
    }

    private static function fetch(string $file, int $start, int $end): array
    {
        $key = 'nmsdw-history-v1:' . hash('sha256', $file . ':' . $start . ':' . $end);
        try {
            $cached = Cache::get($key);
            if (is_array($cached)) {
                return $cached;
            }
        } catch (\Throwable) {
            return self::unavailable('History cache unavailable');
        }
        self::$started ??= microtime(true);
        $remaining = 5 - (microtime(true) - self::$started);
        if ($remaining <= 0 || self::$reads >= 20) {
            return self::unavailable('History deferred until a later refresh');
        }
        self::$reads++;
        try {
            $dir = rtrim((string) LibrenmsConfig::get('rrd_dir'), '/\\');
            $daemon = (string) LibrenmsConfig::get('rrdcached', '');
            $filename = $daemon !== '' && str_starts_with($file, $dir . '/') ? substr($file, strlen($dir) + 1) : $file;
            $args = [(string) LibrenmsConfig::get('rrdtool', 'rrdtool'), 'fetch', $filename, 'AVERAGE',
                '--start', (string) $start, '--end', (string) $end, '--resolution', (string) max(300, (int) (($end - $start) / 300))];
            if ($daemon !== '') {
                $args[] = '--daemon';
                $args[] = $daemon;
            }
            $process = new Process($args, is_dir($dir) ? $dir : null, ['LC_ALL' => 'C'], null, min(2, $remaining));
            $process->run();
            $points = $process->isSuccessful() ? self::parse($process->getOutput()) : [];
            $result = $points ? ['available' => true, 'points' => $points] : self::unavailable('No RRD history available');
        } catch (\Throwable) {
            $result = self::unavailable('History read failed or timed out');
        }
        try {
            Cache::put($key, $result, 300);
        } catch (\Throwable) {
            // A cache failure must not break current readings.
        }

        return $result;
    }

    private static function unavailable(string $reason): array
    {
        return ['available' => false, 'delta' => null, 'sustained' => null, 'minutes' => null, 'points' => [], 'reason' => $reason];
    }
}
