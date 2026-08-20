{{--
    Injects the bundle stylesheet into <head> once per page.

    Widgets arrive as HTML fragments inserted with jQuery .html(), which executes any
    <script> they contain. Moving the CSS into <head> under a known id means that no
    matter how many widgets from this bundle are on a dashboard, or how often they
    refresh, the stylesheet is parsed once and survives widget teardown.
--}}
<script>
    (function () {
        var id = @json(\Drakelid\NmsDashWidgets\Support\Assets::styleElementId());

        if (document.getElementById(id)) {
            return;
        }

        var style = document.createElement('style');
        style.id = id;
        style.textContent = @json(\Drakelid\NmsDashWidgets\Support\Assets::css());
        document.head.appendChild(style);
    })();
</script>
