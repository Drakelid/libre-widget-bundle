{{--
    Device map filtered by any number of device groups.

    Built on core's Leaflet stack and its maps.getdevices endpoint, so markers, clustering
    and popups behave exactly as the built-in World Map. The difference is the group
    filter: core's endpoint takes one group id, so this issues one request per selected
    group and merges the responses. They are keyed by device_id, which makes a device
    belonging to two selected groups appear once.

    data-reload="false" keeps the dashboard from replacing this markup on refresh -- it
    sends a `refresh` event instead, which repopulates the markers without rebuilding the
    map and losing the user's pan and zoom.
--}}
<div id="nmsdw_map-{{ $widget_id }}" class="worldmap_widget" data-reload="false"
     style="width: 100%; height: 100%; min-height: 220px;"></div>

<script type="application/javascript">
    (function () {
        const map_id = 'nmsdw_map-{{ $widget_id }}';
        const statuses = {{ Js::from($statuses) }};
        const group_ids = {{ Js::from($group_ids) }};
        const disabled_alerts = {{ Js::from($disabled_alerts) }};
        const map_config = {{ Js::from($map_config) }};
        const group_radius = {{ (int) $radius }};
        const fit_to_markers = {{ $fit_to_markers ? 'true' : 'false' }};
        const endpoint = '{{ route('maps.getdevices') }}';

        function fetchGroup(groupId) {
            return $.ajax({
                type: 'POST',
                url: endpoint,
                dataType: 'json',
                data: {
                    location_valid: 1,
                    disabled: 0,
                    disabled_alerts: disabled_alerts,
                    statuses: statuses,
                    group: groupId
                }
            });
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
            marker.bindPopup('<a href="' + device.url + '">' + device.sname + '</a>');

            return marker;
        }

        function render(devices) {
            var markers = Object.values(devices).map(buildMarker);
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
            // No groups selected means every accessible device; core uses 0 for that.
            var wanted = group_ids.length ? group_ids : [0];
            var merged = {};
            var outstanding = wanted.length;
            var failed = false;

            wanted.forEach(function (groupId) {
                fetchGroup(groupId)
                    .done(function (data) {
                        // Keyed by device_id, so overlapping groups collapse naturally.
                        Object.assign(merged, data || {});
                    })
                    .fail(function (error) {
                        // Report once, and still draw whatever the other groups returned.
                        if (! failed) {
                            failed = true;

                            if (typeof toastr !== 'undefined') {
                                toastr.error(error.statusText || 'Map data request failed');
                            }
                        }
                    })
                    .always(function () {
                        if (--outstanding === 0) {
                            render(merged);
                        }
                    });
            });
        }

        loadjs('js/leaflet.js', function () {
            loadjs('js/leaflet.markercluster.js', function () {
                loadjs('js/leaflet.awesome-markers.min.js', function () {
                    loadjs('js/L.Control.Locate.min.js', function () {
                        init_map(map_id, map_config).scrollWheelZoom.disable();
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
                                destroy_map(map_id);
                            });
                    });
                });
            });
        });
    })();
</script>
