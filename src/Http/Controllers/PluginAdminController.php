<?php

namespace Drakelid\NmsDashWidgets\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

class PluginAdminController extends Controller
{
    public function index()
    {
        Gate::authorize('admin');

        return view('nmsdashwidgets::plugin.main');
    }
}
