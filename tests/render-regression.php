<?php

// Render real shared Blade templates, without pretending to provide host models.
require dirname(__DIR__) . '/vendor/autoload.php';

use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Engines\CompilerEngine;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Factory;
use Illuminate\View\FileViewFinder;

if (! function_exists('__')) {
    function __($text, $replace = []) {
        foreach ($replace as $key => $value) {
            $text = str_replace(':' . $key, (string) $value, $text);
        }
        return $text;
    }
}
$cache = sys_get_temp_dir() . '/nmsdw-render-' . getmypid();
@mkdir($cache, 0777, true);
$files = new Filesystem();
$compiler = new BladeCompiler($files, $cache);
$engines = new EngineResolver();
$engines->register('blade', fn () => new CompilerEngine($compiler, $files));
$factory = new Factory($engines, new FileViewFinder($files, [dirname(__DIR__) . '/resources/views']), new Dispatcher(new Container()));
$factory->share('__env', $factory);
try {
    foreach (['compact', 'cards', 'tiles'] as $layout) {
        $html = $factory->make('widgets.partials.nmsdw-records', ['layout' => $layout, 'records' => [[
            'title' => 'router', 'href' => '/device/1', 'value' => 0, 'unit' => 'dBm', 'status' => 'warning',
            'subtitle' => '<unsafe>', 'bar' => 0, 'observed_at' => 1,
            'meta' => [['RX remaining', '200 Mbps']], 'graph' => '<img alt="Traffic" src="/graph">',
            'links' => [['href' => '/port/2', 'label' => 'Interface']],
        ]]])->render();
        foreach (['>0</span>', 'dBm', 'Warning', 'RX remaining', '200 Mbps', 'stale', 'width: 0%', '&lt;unsafe&gt;', 'href="/port/2"', 'alt="Traffic"'] as $expected) {
            if (! str_contains($html, $expected)) {
                throw new RuntimeException("$layout lost $expected");
            }
        }
        if (preg_match('/<a\b[^>]*>(?:(?!<\/a>).)*<a\b/s', $html)) {
            throw new RuntimeException("$layout contains nested links");
        }
    }
    $html = $factory->make('widgets.partials.nmsdw-result-count', ['shown' => 5, 'matched' => 21, 'noun' => 'ports'])->render();
    if (! str_contains($html, '5') || ! str_contains($html, '21')) {
        throw new RuntimeException('Result count lost the display limit or full count');
    }
    echo "PASS: real Blade rendering preserves zero values, units, metadata, age, graphs, safe links and full counts in all record layouts\n";
} finally {
    foreach (glob($cache . '/*') as $file) {
        unlink($file);
    }
    rmdir($cache);
}
