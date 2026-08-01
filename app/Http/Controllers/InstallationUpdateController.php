<?php

namespace App\Http\Controllers;

use App\Domain\Security\InstallationUpdateService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class InstallationUpdateController extends Controller
{
    public function status(InstallationUpdateService $updates): JsonResponse
    {
        return response()->json($updates->publicStatus());
    }

    public function check(InstallationUpdateService $updates): RedirectResponse
    {
        try {
            $updates->check();

            return back()->with('success', 'Official release check completed.');
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }
    }

    public function request(InstallationUpdateService $updates): RedirectResponse
    {
        try {
            $updates->request();

            return back()->with('success', 'Update accepted. IpamFerry will briefly restart while it is installed.');
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }
    }
}
