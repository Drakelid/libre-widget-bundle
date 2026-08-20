<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Repoint old `group-world-map` widget placements at core's built-in World Map.
 *
 * NOT LOADED BY DEFAULT. The service provider deliberately does not call
 * loadMigrationsFrom(), because rewriting rows in core's users_widgets table silently
 * on plugin install is a surprising side effect. See docs/RETIRE-GROUP-WORLD-MAP.md
 * for the recommended manual procedure, and for how to opt in to this file instead.
 *
 * The target slug is 'worldmap' (core's route URI segment), NOT 'world-map' (the
 * controller's $name property). users_widgets.widget must match the route segment.
 */
return new class extends Migration
{
    private const OLD_SLUG = 'group-world-map';

    private const NEW_SLUG = 'worldmap';

    public function up(): void
    {
        // Settings are key-for-key compatible with core's world-map widget, so the
        // stored JSON blob is left untouched; only the widget key changes.
        DB::table('users_widgets')
            ->where('widget', self::OLD_SLUG)
            ->update(['widget' => self::NEW_SLUG]);
    }

    public function down(): void
    {
        /*
         * Intentionally a no-op.
         *
         * Reversing this would recreate rows pointing at a widget that no longer
         * exists in this bundle, i.e. dashboard panels that render an error. It would
         * also be ambiguous: after up(), migrated rows are indistinguishable from
         * placements the user created against core's World Map directly.
         */
    }
};
