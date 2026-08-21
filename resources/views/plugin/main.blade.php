@extends('layouts.librenmsv1')

@section('title', __('Dashboard Widget Bundle'))

@section('content')
    <div class="container-fluid">
        <div class="panel panel-default">
            <div class="panel-heading">
                <h3 class="panel-title">
                    {{ __('Dashboard Widget Bundle') }}
                    <small>v{{ $nmsdashwidgets_version ?? '' }}</small>
                </h3>
            </div>
            <div class="panel-body">
                <p>
                    {{ __('This plugin adds eleven widgets to the dashboard widget picker. Add them from a dashboard using "Add Widget".') }}
                </p>

                <table class="table table-striped table-condensed">
                    <thead>
                        <tr>
                            <th>{{ __('Widget') }}</th>
                            <th>{{ __('Key') }}</th>
                            <th>{{ __('Description') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong>{{ __('Device Group Down Count') }}</strong></td>
                            <td><code>device-group-down-count</code></td>
                            <td>{{ __('Down device counts per selected device group, with a combined total.') }}</td>
                        </tr>
                        <tr>
                            <td><strong>{{ __('Top Bandwidth Usage by Device Group') }}</strong></td>
                            <td><code>top-bandwidth-device-group</code></td>
                            <td>{{ __('Busiest ports by combined in + out throughput.') }}</td>
                        </tr>
                        <tr>
                            <td><strong>{{ __('Uplink Utilization Overview') }}</strong></td>
                            <td><code>uplink-utilization-overview</code></td>
                            <td>{{ __('Regex-matched uplinks ranked by peak utilisation, with summary statistics across all matches.') }}</td>
                        </tr>
                        <tr>
                            <td><strong>{{ __('Top Device Temperatures') }}</strong></td>
                            <td><code>top-device-temperatures</code></td>
                            <td>{{ __('Hottest devices by temperature sensor. One row per device.') }}</td>
                        </tr>
                        <tr>
                            <td><strong>{{ __('Flapping Devices / Unstable Links') }}</strong></td>
                            <td><code>flapping-devices</code></td>
                            <td>{{ __('Devices and ports that changed state repeatedly within a lookback window.') }}</td>
                        </tr>
                        <tr>
                            <td><strong>{{ __('Recently Added Devices') }}</strong></td>
                            <td><code>recently-added-devices</code></td>
                            <td>{{ __('Most recently added devices, newest first.') }}</td>
                        </tr>
                        <tr>
                            <td><strong>{{ __('Optical Light Levels') }}</strong></td>
                            <td><code>optical-light-levels</code></td>
                            <td>{{ __('Transceiver RX/TX levels ranked by margin above the low threshold. Requires optics that report DDM.') }}</td>
                        </tr>
                        <tr>
                            <td><strong>{{ __('BGP Session Health') }}</strong></td>
                            <td><code>bgp-session-health</code></td>
                            <td>{{ __('Sessions that are administratively up but not established, recently re-established, or losing prefixes.') }}</td>
                        </tr>
                        <tr>
                            <td><strong>{{ __('Site Power and Battery') }}</strong></td>
                            <td><code>site-power-status</code></td>
                            <td>{{ __('Battery runtime, charge and DC voltage per device or per site.') }}</td>
                        </tr>
                        <tr>
                            <td><strong>{{ __('Customer Ports Down') }}</strong></td>
                            <td><code>customer-port-status</code></td>
                            <td>{{ __('Customer-facing ports that are administratively up but operationally down.') }}</td>
                        </tr>
                        <tr>
                            <td><strong>{{ __('Poller Health') }}</strong></td>
                            <td><code>poller-health</code></td>
                            <td>{{ __('Devices whose data has gone stale, and poller nodes that stopped reporting.') }}</td>
                        </tr>
                    </tbody>
                </table>

                <div class="alert alert-info">
                    <strong>{{ __('Widgets not appearing?') }}</strong>
                    {{ __('LibreNMS caches routes in production. Run "php artisan route:clear" after installing or upgrading this plugin.') }}
                </div>
            </div>
        </div>
    </div>
@endsection
