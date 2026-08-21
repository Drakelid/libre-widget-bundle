<?php

use Drakelid\NmsDashWidgets\Providers\WidgetServiceProvider;
use Drakelid\NmsDashWidgets\Support\WidgetCatalog;
use Illuminate\Support\Facades\Route;

Route::group(['middleware' => ['web', 'auth']], function (): void {
    Route::namespace('Drakelid\NmsDashWidgets\Http\Controllers')->group(function (): void {
        Route::name('plugin.nmsdashwidgets.')->group(function (): void {

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
             * These slugs are load bearing -- existing dashboard placements reference
             * them. Never rename one.
             *
             * Only widgets enabled on the plugin settings page are registered, which is
             * what removes a disabled widget from the picker. Because the route table is
             * cached in production, the provider rebuilds that cache whenever the setting
             * changes.
             */
            Route::prefix('ajax')->group(function (): void {
                Route::prefix('dash')->namespace('Widgets')->group(function (): void {
                    foreach (WidgetServiceProvider::enabledWidgets() as $slug) {
                        $controller = WidgetCatalog::controller($slug);

                        if ($controller !== null) {
                            Route::post($slug, $controller);
                        }
                    }
                });
            });

            /*
             * No select2 endpoints of our own: the settings forms use core's existing
             * ajax/select/device-group and ajax/select/port-field sources, which already
             * apply authorisation, search and pagination.
             *
             * No plugin admin page of our own either. Core owns
             * `plugin/settings/{plugin_name}` and renders our Settings hook there, so
             * registering that URI here would collide with it.
             */
        });
    });
});
