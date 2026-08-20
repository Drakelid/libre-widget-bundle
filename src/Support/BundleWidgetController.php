<?php

namespace Drakelid\NmsDashWidgets\Support;

use App\Http\Controllers\Widgets\WidgetController;
use App\Models\DeviceGroup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Shared base for every widget in this bundle.
 *
 * Extends core's WidgetController and adds the pieces all six widgets need:
 * plugin-namespaced titles, responsive layout selection, and a normalisation hook.
 *
 * View resolution is intentionally left to the parent. Because the service provider
 * registers this package's resources/views as an additional root location, the
 * inherited getView()/getSettingsView() resolve "widgets.<slug>" and
 * "widgets.settings.<slug>" to our blades while keeping the un-namespaced view name
 * core's formatResponse() depends on. See WidgetServiceProvider for why that matters.
 */
abstract class BundleWidgetController extends WidgetController
{
    /** Layout modes used by widgets that support a responsive "auto" display. */
    public const LAYOUT_SUMMARY = 'summary';
    public const LAYOUT_LIST = 'list';
    public const LAYOUT_CARDS = 'cards';

    /**
     * Title for the picker and the widget header.
     *
     * A per-placement custom title always wins; otherwise fall back to the plugin's
     * own translation namespace. Matches the original widgets' behaviour.
     */
    public function getTitle(): string
    {
        $custom = $this->getSettings()['title'] ?? null;

        if (is_string($custom) && trim($custom) !== '') {
            return trim($custom);
        }

        return __('nmsdashwidgets::widgets.' . $this->name . '.title');
    }

    /**
     * Settings with defaults applied and every value coerced to a sane type.
     *
     * Stored settings are untrusted (see Cast). Widgets should read through this
     * rather than touching getSettings() directly.
     */
    protected function settings(bool $forSettingsView = false): array
    {
        return $this->normalizeSettings($this->getSettings($forSettingsView));
    }

    /**
     * Extend core's settings loading with a guard on the reserved `device_group` key.
     *
     * Core's __invoke() appends the group name to the widget title with:
     *
     *     DeviceGroup::find($this->settings['device_group'])->name
     *
     * which fatals on a null when the referenced group has since been deleted. It also
     * performs no access check, so a hand-edited settings blob could filter by, and
     * name, a group the user has no rights to.
     *
     * Clearing the key when it does not resolve to an accessible group fixes both:
     * core then skips the title suffix, and the widget falls back to "all devices".
     */
    public function getSettings($settingsView = false): array
    {
        $settings = parent::getSettings($settingsView);

        if (! array_key_exists('device_group', $this->defaults)) {
            return $settings;
        }

        $value = $this->settings['device_group'] ?? null;

        if (empty($value)) {
            return $settings;
        }

        $id = $value instanceof DeviceGroup ? (int) $value->id : (int) $value;

        if (empty(DeviceGroups::accessibleIds(Auth::user(), [$id]))) {
            // Mutate the cached array too; core reads $this->settings directly.
            $this->settings['device_group'] = null;
        }

        return $this->settings;
    }

    /**
     * Per-widget coercion. Override in each widget; the base returns input unchanged.
     */
    protected function normalizeSettings(array $settings): array
    {
        return $settings;
    }

    /**
     * Pick a layout from the widget body size the dashboard posts with every refresh.
     *
     * Core sends {"dimensions": {"x": <px>, "y": <px>}} describing the rendered widget
     * body, which is how "auto" adapts to how the user sized the tile.
     */
    protected function layoutFor(Request $request, string $mode): string
    {
        if ($mode !== 'auto') {
            return $mode;
        }

        $width = (int) ($request->input('dimensions.x') ?? 0);

        if ($width > 0 && $width < 320) {
            return self::LAYOUT_SUMMARY;
        }

        if ($width > 0 && $width <= 520) {
            return self::LAYOUT_LIST;
        }

        if ($width > 520) {
            return self::LAYOUT_CARDS;
        }

        // No usable dimensions (first paint, or a client that did not report a size).
        return self::LAYOUT_LIST;
    }

    /**
     * Data every widget blade expects.
     */
    protected function shared(array $settings): array
    {
        return [
            'widget_id' => $settings['id'] ?? null,
            'slug' => $this->name,
        ];
    }
}
