<?php

namespace App\Http\Controllers;

use App\Models\MigrationProject;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        return inertia('Dashboard', [
            'projects' => MigrationProject::query()
                ->select(['id', 'name', 'status', 'created_at'])
                ->withCount('plans')
                ->latest()
                ->get(),
        ]);
    }
}
