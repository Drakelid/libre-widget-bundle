<a href="{{ route('plugin.settings', 'nmsdashwidgets') }}">
    <i class="fa fa-th-large fa-fw" aria-hidden="true"></i> {{ __('Dashboard Widget Bundle') }}
</a>

{{--
    Group this bundle's widgets under a "Custom Widgets" heading in the dashboard's
    "Add Widget" dropdown.

    LibreNMS builds that list in DashboardController::listWidgets() and renders it as a
    flat <ul> of <li><a class="place_widget">, with the title escaped -- so a plugin
    cannot supply a heading, and cannot inject markup through its widget title.

    This runs from the menu hook, which renders on every page including the dashboard,
    so it does not depend on one of our widgets already being placed. It only moves
    existing entries and inserts a heading; if core's markup ever changes the selectors
    stop matching and nothing happens.
--}}
<script>
    (function () {
        var SLUGS = @json(\Drakelid\NmsDashWidgets\Support\WidgetCatalog::slugs());
        var HEADING = @json(__('Custom Widgets'));

        function group() {
            var links = document.querySelectorAll('a.place_widget[data-widget_type]');

            if (! links.length) {
                return false;   // not the dashboard, or the picker has not rendered yet
            }

            var ours = [];
            var menu = null;

            links.forEach(function (link) {
                if (SLUGS.indexOf(link.getAttribute('data-widget_type')) === -1) {
                    return;
                }

                var item = link.closest('li');

                if (item) {
                    ours.push(item);
                    menu = menu || item.parentNode;
                }
            });

            if (! menu || ! ours.length) {
                return true;    // picker exists but holds none of ours; nothing to do
            }

            if (menu.querySelector('.nmsdw-picker-heading')) {
                return true;    // already grouped
            }

            var divider = document.createElement('li');
            divider.className = 'divider nmsdw-picker-heading';
            divider.setAttribute('role', 'separator');

            var heading = document.createElement('li');
            heading.className = 'dropdown-header nmsdw-picker-heading';
            heading.textContent = HEADING;

            // Append the heading and our entries at the end, keeping core's own list
            // untouched and in its original order.
            menu.appendChild(divider);
            menu.appendChild(heading);
            ours.forEach(function (item) {
                menu.appendChild(item);
            });

            return true;
        }

        if (! group()) {
            // The dashboard renders the picker inline, but be tolerant of it arriving
            // late. Give up quickly rather than polling forever.
            var tries = 0;
            var timer = setInterval(function () {
                if (group() || ++tries > 20) {
                    clearInterval(timer);
                }
            }, 250);
        }
    })();
</script>
