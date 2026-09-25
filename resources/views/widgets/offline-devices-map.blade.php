@include('widgets.partials.nmsdw-style')
{{--
    Device map filtered by any number of device groups.

    Core's Leaflet stack renders a complete accessible-device snapshot, including
    devices without coordinates in the synchronized outage list.

    data-reload="false" keeps the dashboard from replacing this markup on refresh -- it
    sends a `refresh` event instead, which repopulates the markers without rebuilding the
    map and losing the user's pan and zoom.
--}}
{{--
    Chrome is hidden with CSS rather than by removing the Leaflet controls. Several
    engines add their layers from a loadjs callback, so a control removed at init time
    can be repopulated moments later; a class on the container cannot race with that.
--}}
<div id="nmsdw_map-{{ $widget_id }}"
     class="worldmap_widget {{ $hide_attribution ? 'nmsdw-map-noattr' : '' }} {{ $hide_zoom ? 'nmsdw-map-nozoom' : '' }}"
     data-reload="false"
     style="width: 100%; height: 100%; min-height: 220px;"></div>

<script type="application/javascript">
    (function () {
        const map_id = 'nmsdw_map-{{ $widget_id }}';
        const widget_id = {{ Js::from($widget_id) }};
        const map_config = {{ Js::from($map_config) }};
        const group_radius = {{ (int) $radius }};
        const fit_to_markers = {{ $fit_to_markers ? 'true' : 'false' }};
        const endpoint = '{{ route('plugin.nmsdashwidgets.map-data') }}';
        let refreshGeneration = 0;
        let hasCompleteData = false;
        let requestWarning;
        let outageList;
        let fitButton;
        let outageCoordinates = [];

        function fetchSnapshot() {
            return $.ajax({
                type: 'POST',
                url: endpoint,
                dataType: 'json',
                timeout: 30000,
                data: { id: widget_id }
            });
        }

        function deviceLink(device) {
            var link = document.createElement('a');
            link.textContent = device.sname;
            try {
                var url = new URL(device.url, window.location.href);
                if (url.protocol === 'http:' || url.protocol === 'https:') link.href = url.href;
            } catch (error) { /* Invalid URLs remain plain labels. */ }
            return link;
        }

        function detailText(device) {
            var age = device.last_polled ? Math.max(0, Math.floor((Date.now() - Date.parse(device.last_polled)) / 60000)) : null;
            var poll = age === null || ! Number.isFinite(age) ? 'Last poll unknown' : 'Last poll ' + age + ' min ago' + (age > 15 ? ' (stale)' : '');
            var outage = device.status ? 'Online' : (device.outage_seconds == null ? 'Outage duration unknown' : 'Estimated outage ' + Math.floor(device.outage_seconds / 60) + ' min (since last successful poll)');
            return (device.site || 'Unknown location') + ' · ' + outage + ' · ' + poll + (device.maintenance ? ' · Maintenance' : '');
        }

        function buildMarker(device) {
            var icon = L.AwesomeMarkers.icon({
                icon: device.typeIcon,
                markerColor: device.status ? 'green' : (device.maintenance == 1 ? 'blue' : 'red'),
                prefix: 'fa',
                iconColor: 'white'
            });

            var options = { title: device.sname, icon: icon };

            if (device.status) {                      // up
                options.zIndexOffset = 0;
            } else if (device.maintenance == 1) {     // down, but in maintenance
                options.zIndexOffset = 10000;
            } else {                                  // down
                options.zIndexOffset = 5000;
            }

            var marker = L.marker(new L.LatLng(device.lat, device.lng), options);
            var popup = document.createElement('div');
            popup.appendChild(deviceLink(device));
            var detail = document.createElement('div');
            detail.textContent = detailText(device);
            popup.appendChild(detail);
            marker.bindPopup(popup);

            return marker;
        }

        function render(devices, observedAt) {
            var all = Object.values(devices);
            var hasCoordinates = device => Number.isFinite(device.lat) && Number.isFinite(device.lng);
            var plotted = all.filter(hasCoordinates);
            var markers = plotted.map(buildMarker);
            var outages = all.filter(device => ! device.status).sort((a, b) => (b.outage_seconds || 0) - (a.outage_seconds || 0));
            outageCoordinates = outages.filter(hasCoordinates).map(device => [device.lat, device.lng]);
            fitButton.disabled = outageCoordinates.length === 0;
            var list = document.createElement('div');
            var count = document.createElement('div');
            count.textContent = plotted.length + ' plotted / ' + all.length + ' matching devices; ' + (all.length - plotted.length) + ' without coordinates. ' + outages.length + ' outages. Snapshot: ' + (observedAt || 'unknown');
            list.appendChild(count);
            if (outages.length === 0) {
                var empty = document.createElement('div');
                empty.textContent = 'No offline devices match the selected filters.';
                list.appendChild(empty);
            }
            outages.slice(0, 100).forEach(function (device) {
                var row = document.createElement('div');
                row.appendChild(deviceLink(device));
                var detail = document.createElement('div');
                detail.textContent = detailText(device) + (hasCoordinates(device) ? '' : ' · No coordinates');
                row.appendChild(detail);
                if (hasCoordinates(device)) {
                    var locate = document.createElement('button');
                    locate.type = 'button';
                    locate.textContent = 'Show on map';
                    locate.className = 'btn btn-default btn-xs';
                    locate.addEventListener('click', function () {
                        var marker = markers[plotted.indexOf(device)];
                        map.markerCluster.zoomToShowLayer(marker, function () { marker.openPopup(); });
                    });
                    row.appendChild(locate);
                }
                list.appendChild(row);
            });
            if (outages.length > 100) {
                var limited = document.createElement('div');
                limited.textContent = 'Showing the 100 longest outages of ' + outages.length + '.';
                list.appendChild(limited);
            }
            outageList.replaceChildren(list);
            var map = get_map(map_id);
            var isFirstLoad = ! map.markerCluster;

            if (! map.markerCluster) {
                map.markerCluster = L.markerClusterGroup({
                    maxClusterRadius: group_radius,
                    iconCreateFunction: function (cluster) {
                        var children = cluster.getAllChildMarkers();
                        var color = 'green';

                        for (var i = 0; i < children.length; i++) {
                            var markerColor = children[i].options.icon.options.markerColor;

                            if (markerColor === 'blue' && color !== 'red') {
                                color = 'blue';
                            }

                            if (markerColor === 'red') {
                                color = 'red';
                            }
                        }

                        return L.divIcon({
                            html: cluster.getChildCount(),
                            className: color + ' Cluster marker-cluster marker-cluster-small leaflet-zoom-animated leaflet-clickable',
                            iconSize: L.point(40, 40)
                        });
                    }
                });

                map.addLayer(map.markerCluster);
            }

            map.markerCluster.clearLayers();
            map.markerCluster.addLayers(markers);

            if (fit_to_markers && isFirstLoad && markers.length > 0) {
                map.fitBounds(map.markerCluster.getBounds(), { padding: [30, 30], maxZoom: 12 });
            }
        }

        function populate() {
            var generation = ++refreshGeneration;
            function failed() {
                if (generation !== refreshGeneration) return;
                requestWarning.textContent = hasCompleteData
                    ? 'Map refresh failed. Showing previous device data; it may be out of date.'
                    : 'Map data unavailable. Device status could not be loaded.';
                requestWarning.hidden = false;
            }
            fetchSnapshot().done(function (data) {
                if (generation !== refreshGeneration) return;
                if (! data || ! data.devices || typeof data.devices !== 'object') {
                    failed();
                    return;
                }
                render(data.devices, data.observed_at);
                hasCompleteData = true;
                requestWarning.hidden = true;
            }).fail(failed);
        }

        loadjs('js/leaflet.js', function () {
            loadjs('js/leaflet.markercluster.js', function () {
                loadjs('js/leaflet.awesome-markers.min.js', function () {
                    loadjs('js/L.Control.Locate.min.js', function () {
                        init_map(map_id, map_config).scrollWheelZoom.disable();
                        var warningControl = L.control({ position: 'bottomleft' });
                        warningControl.onAdd = function () {
                            requestWarning = document.createElement('div');
                            requestWarning.className = 'alert alert-warning';
                            requestWarning.setAttribute('role', 'status');
                            requestWarning.style.maxWidth = '280px';
                            requestWarning.hidden = true;
                            return requestWarning;
                        };
                        warningControl.addTo(get_map(map_id));
                        var outageControl = L.control({ position: 'topright' });
                        outageControl.onAdd = function () {
                            var panel = document.createElement('div');
                            panel.className = 'panel panel-default';
                            panel.style.maxWidth = '300px';
                            panel.style.padding = '6px';
                            fitButton = document.createElement('button');
                            fitButton.type = 'button';
                            fitButton.className = 'btn btn-default btn-xs';
                            fitButton.textContent = 'Fit current outages';
                            fitButton.disabled = true;
                            fitButton.addEventListener('click', function () {
                                if (outageCoordinates.length) get_map(map_id).fitBounds(outageCoordinates, { padding: [30, 30], maxZoom: 12 });
                            });
                            panel.appendChild(fitButton);
                            var details = document.createElement('details');
                            var summary = document.createElement('summary');
                            summary.textContent = 'Outage list and map coverage';
                            details.appendChild(summary);
                            outageList = document.createElement('div');
                            outageList.style.maxHeight = '180px';
                            outageList.style.overflowY = 'auto';
                            outageList.textContent = 'Loading device status…';
                            details.appendChild(outageList);
                            panel.appendChild(details);
                            L.DomEvent.disableClickPropagation(panel);
                            L.DomEvent.disableScrollPropagation(panel);
                            return panel;
                        };
                        outageControl.addTo(get_map(map_id));
                        populate();

                        $('#' + map_id)
                            .on('click', function () {
                                get_map(map_id).scrollWheelZoom.enable();
                            })
                            .on('mouseleave', function () {
                                get_map(map_id).scrollWheelZoom.disable();
                            })
                            .on('resize', function () {
                                get_map(map_id).invalidateSize();
                            })
                            .on('refresh', function () {
                                get_map(map_id).invalidateSize();
                                populate();
                            })
                            .on('destroy', function () {
                                ++refreshGeneration;
                                destroy_map(map_id);
                            });
                    });
                });
            });
        });
    })();
</script>
