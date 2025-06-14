<?php

namespace App\Http\Controllers;

use App\Models\EveCharacter;
use Illuminate\Http\Request;
use App\Services\EveOnlineProvider;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

class EsiController extends Controller
{
    public function authLogin(EveOnlineProvider $provider)
    {
        return $provider->redirect();
    }

    public function authLogout()
    {
        Auth::logout();
        Session::flush();
        return redirect()->route('landing');
    }

    public function authCallback(Request $request, EveOnlineProvider $provider)
    {
        if (!$request->has('code') || !$request->has('state')) {
            return redirect()->route('landing')
                ->with('error', 'Authentication failed. Please try again.');
        }

        $character = $provider->handleCallback(
            $request->input('code'),
            $request->input('state')
        );

        if (!$character) {
            return redirect()->route('landing')
                ->with('error', 'Authentication failed. Please try again.');
        }



        // Update the character in the database, using provider::getCharacterData()
        $provider = app(EveOnlineProvider::class);
        $characterData = $provider->getCharacterData($character->character_id);
        if ($characterData) {
            // Update his corporation data too
            if (isset($characterData['corporation_id'])) {
                $provider->getCorporationData($characterData['corporation_id']);
            }
        }

        return redirect()->route('dashboard')
            ->with('success', "Character {$character->name} has been added successfully.");
    }

    public function corp(EveOnlineProvider $provider)
    {
        // Get the .env EVE_CORPORATION_ID
        $corpId = env('EVE_CORPORATION_ID');
        if (!$corpId) {
            return 'Corporation ID is not set in the environment variables.';
        }
        // Get the corporation details using the getCorporation method of the provider
        $corporation = $provider->getCorporation($corpId);
        if (!$corporation) {
            return 'Failed to retrieve corporation details.';
        }

        // Check if we have a EveCharacter in the database associated with the CEO $corporation['ceo_id'], and get his user
        $ceoCharacter = EveCharacter::where('character_id', $corporation['ceo_id'])->first();
        dd($ceoCharacter->user);

        // Return the corporation details to the view
        return $corporation;
    }
}
