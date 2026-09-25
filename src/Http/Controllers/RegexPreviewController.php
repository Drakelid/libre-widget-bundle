<?php

namespace Drakelid\NmsDashWidgets\Http\Controllers;

use Drakelid\NmsDashWidgets\Providers\WidgetServiceProvider;
use Drakelid\NmsDashWidgets\Support\WidgetCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RegexPreviewController
{
    public function __invoke(Request $request): JsonResponse
    {
        $input = $request->validate(['widget_slug' => 'required|string|max:80', 'id' => 'nullable|integer|min:1']);
        $slug = $input['widget_slug'];
        abort_unless(in_array($slug, WidgetServiceProvider::enabledWidgets(), true), 404);
        $class = __NAMESPACE__ . '\\Widgets\\' . WidgetCatalog::controller($slug);

        return response()->json((new $class)->regexPreview($request));
    }
}
