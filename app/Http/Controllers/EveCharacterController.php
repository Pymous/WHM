<?php

namespace App\Http\Controllers;

use App\Models\EveCharacter;
use App\Services\EveOnlineProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EveCharacterController extends Controller
{
    protected EveOnlineProvider $eveOnlineProvider;

    public function __construct(EveOnlineProvider $eveOnlineProvider)
    {
        $this->eveOnlineProvider = $eveOnlineProvider;
        $this->middleware('auth');
    }

    /**
     * Display a listing of the user's EVE characters.
     */
    public function index()
    {
        $characters = Auth::user()->eveCharacters;

        return view('characters.index', compact('characters'));
    }

    /**
     * Redirect to EVE Online SSO to add a new character.
     */
    public function add()
    {
        return $this->eveOnlineProvider->redirect();
    }

    /**
     * Handle the callback from EVE Online SSO.
     */
    public function callback(Request $request)
    {
        if (!$request->has('code') || !$request->has('state')) {
            return redirect()->route('characters.index')
                ->with('error', 'Authentication failed. Please try again.');
        }

        $character = $this->eveOnlineProvider->handleCallback(
            $request->input('code'),
            $request->input('state')
        );

        if (!$character) {
            return redirect()->route('characters.index')
                ->with('error', 'Authentication failed. Please try again.');
        }

        return redirect()->route('characters.index')
            ->with('success', "Character {$character->name} has been added successfully.");
    }

    /**
     * Set a character as primary.
     */
    public function setPrimary(EveCharacter $character)
    {
        // Ensure the character belongs to the authenticated user
        if ($character->user_id !== Auth::id()) {
            return redirect()->route('characters.index')
                ->with('error', 'You do not have permission to modify this character.');
        }

        // Reset all primary flags for this user's characters
        Auth::user()->eveCharacters()->update(['is_primary' => false]);

        // Set the selected character as primary
        $character->update(['is_primary' => true]);

        return redirect()->route('characters.index')
            ->with('success', "{$character->name} has been set as your primary character.");
    }

    /**
     * Remove a character.
     */
    public function destroy(EveCharacter $character)
    {
        // Ensure the character belongs to the authenticated user
        if ($character->user_id !== Auth::id()) {
            return redirect()->route('characters.index')
                ->with('error', 'You do not have permission to modify this character.');
        }

        $characterName = $character->name;
        $isPrimary = $character->is_primary;

        // Delete the character
        $character->delete();

        // If the deleted character was primary, set another character as primary if available
        if ($isPrimary) {
            $newPrimaryCharacter = Auth::user()->eveCharacters()->first();
            if ($newPrimaryCharacter) {
                $newPrimaryCharacter->update(['is_primary' => true]);
            }
        }

        return redirect()->route('characters.index')
            ->with('success', "Character {$characterName} has been removed successfully.");
    }
}
