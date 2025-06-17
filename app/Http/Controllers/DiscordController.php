<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Artisan;
use Laravel\Socialite\Facades\Socialite;

class DiscordController extends Controller
{
    public function authLogin()
    {

        return Socialite::driver('discord')
            ->setScopes(['identify'])
            ->redirect();
    }

    public function authCallback(Request $request)
    {
        $user = Socialite::driver('discord')->user();

        Auth::user()->update([
            'discord_id' => $user->id,
            'discord_data' => json_encode($user),
        ]);

        // TODO: Update roles on Discord after a successful login

        return redirect()->route('dashboard')->with('success', 'Discord account linked successfully!');
    }
}
