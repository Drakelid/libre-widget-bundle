{{--
    Plugin settings page: choose which widgets this bundle provides.

    Rendered by core's PluginSettingsController via resources/views/plugins/settings.blade.php,
    which does @include($content_view, $settings) -- so the keys returned by the Settings
    hook arrive here as variables.

    The form posts to core's plugin.update route, which persists anything under
    settings[] onto the plugins row. Everything must therefore be named settings[...].
--}}
<div style="margin: 15px;">
    <div class="panel panel-default">
        <div class="panel-heading">
            <h3 class="panel-title">
                {{ __('Dashboard Widget Bundle') }}
                <small>{{ $version ?? ($nmsdashwidgets_version ?? '') }}</small>
            </h3>
        </div>

        <div class="panel-body">
            <p>
                {{ __('Choose which widgets are offered in the dashboard "Add Widget" list. Unticking one removes it from the list without uninstalling the plugin.') }}
            </p>

            <div class="alert alert-warning">
                <strong>{{ __('Before you disable a widget:') }}</strong>
                {{ __('any dashboard already using it will show an error panel in its place. Remove those widgets from their dashboards first, or re-enable it to bring them back. Nothing is deleted either way.') }}
            </div>

            <form method="post" action="{{ route('plugin.update', $plugin_name) }}">
                @csrf

                {{-- Absent from the post means "none selected", which is a valid choice.
                     Without this an all-unticked form would look like "never configured"
                     and every widget would come back on. --}}
                <input type="hidden" name="settings[{{ $setting_key }}][]" value="">

                <table class="table table-condensed table-hover">
                    <thead>
                        <tr>
                            <th style="width: 90px;">{{ __('Enabled') }}</th>
                            <th>{{ __('Widget') }}</th>
                            <th class="hidden-xs">{{ __('Key') }}</th>
                            <th class="hidden-xs">{{ __('Description') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($widgets as $slug => $widget)
                            <tr>
                                <td>
                                    <input type="checkbox"
                                           id="widget-{{ $slug }}"
                                           name="settings[{{ $setting_key }}][]"
                                           value="{{ $slug }}"
                                           @checked(in_array($slug, $enabled, true))>
                                </td>
                                <td>
                                    <label for="widget-{{ $slug }}" style="font-weight: 700; margin: 0;">
                                        {{ __($widget[1]) }}
                                    </label>
                                </td>
                                <td class="hidden-xs"><code>{{ $slug }}</code></td>
                                <td class="hidden-xs text-muted">{{ __($widget[2]) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                <div style="margin-top: 15px;">
                    <button type="submit" class="btn btn-primary">{{ __('Save') }}</button>
                    <button type="button" class="btn btn-default"
                            onclick="nmsdwToggleAll(true)">{{ __('Select all') }}</button>
                    <button type="button" class="btn btn-default"
                            onclick="nmsdwToggleAll(false)">{{ __('Select none') }}</button>
                </div>
            </form>
        </div>
    </div>

    <div class="panel panel-default">
        <div class="panel-heading">
            <h3 class="panel-title">{{ __('If a change does not appear') }}</h3>
        </div>
        <div class="panel-body">
            <p>
                {{ __('LibreNMS builds the widget list by scanning its route table, and caches that table in production. Saving here rebuilds the cache automatically.') }}
                @if($routes_cached)
                    {{ __('Routes are currently cached on this installation.') }}
                @endif
            </p>
            <p>{{ __('If the list still looks wrong, run this on the LibreNMS server:') }}</p>
            <pre>cd /opt/librenms &amp;&amp; sudo -u librenms php artisan route:clear</pre>

            <p class="text-muted" style="margin-top: 10px;">
                {{ __('Package') }}: <code>drakelid/librenms-dashboard-widgets</code>
                {{ $version ?? ($nmsdashwidgets_version ?? '') }}
            </p>
        </div>
    </div>
</div>

<script>
    function nmsdwToggleAll(state) {
        document.querySelectorAll('input[type=checkbox][name^="settings["]').forEach(function (box) {
            box.checked = state;
        });
    }
</script>
