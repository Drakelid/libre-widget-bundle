<?php

use Illuminate\Support\Facades\Route;

Route::group(['middleware' => ['web', 'auth']], function (): void {
    Route::namespace('Drakelid\NmsDashWidgets\Http\Controllers')->group(function (): void {
        Route::name('plugin.nmsdashwidgets.')->group(function (): void {

            // Plugin admin landing page.
            Route::prefix('plugin/settings/nmsdashwidgets')->group(function (): void {
                Route::get('/', 'PluginAdminController@index')->name('index');
            });

            /*
             * Dashboard widgets.
             *
             * LibreNMS builds the "Add Widget" picker by scanning the route table for
             * routes whose prefix is EXACTLY 'ajax/dash' (DashboardController::listWidgets).
             * Nested prefix() calls concatenate, so 'ajax' + 'dash' produces the required
             * value. Do not collapse these into a single prefix('ajax/dash') without
             * re-checking, and do not nest them any deeper.
             *
             * The last URI segment becomes the widget key stored in users_widgets.widget.
             * These slugs are load bearing: 19 existing dashboard placements reference
             * them. Never rename one.
             */
            Route::prefix('ajax')->group(function (): void {
                Route::prefix('dash')->namespace('Widgets')->group(function (): void {
                    Route::post('device-group-down-count', 'DeviceGroupDownCountController');
                    Route::post('top-bandwidth-device-group', 'TopBandwidthDeviceGroupController');
                    Route::post('uplink-utilization-overview', 'UplinkUtilizationOverviewController');
                    Route::post('top-device-temperatures', 'TopDeviceTemperaturesController');
                    Route::post('flapping-devices', 'FlappingDevicesController');
                    Route::post('recently-added-devices', 'RecentlyAddedDevicesController');

                    // Added in 1.2.0 -- see docs/future-spec.md for the gap analysis
                    // these came from. Slugs are permanent once released.
                    Route::post('optical-light-levels', 'OpticalLightLevelsController');
                    Route::post('bgp-session-health', 'BgpSessionHealthController');
                    Route::post('site-power-status', 'SitePowerStatusController');
                    Route::post('customer-port-status', 'CustomerPortStatusController');
                    Route::post('poller-health', 'PollerHealthController');
                });
            });

            // No select2 endpoints of our own: the settings forms use core's existing
            // ajax/select/device-group and ajax/select/port-field sources, which already
            // apply authorisation, search and pagination.
        });
    });
});
