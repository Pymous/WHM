<?php

namespace App\Http\Controllers;

use App\Models\User;
use Inertia\Inertia;
use Inertia\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class AdminController extends Controller
{
    public function usersIndex()
    {
        $users = User::with('eveCharacters')->get();
        return Inertia::render('admin/Users', [
            'users' => $users,
        ]);
    }

    public function usersForceDiscordSync()
    {
        Artisan::call('eve:discord:sync-roles');
        return response()->json([
            'success' => 'Discord roles sync initiated successfully.',
        ]);
    }
}
