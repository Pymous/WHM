<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class EveController extends Controller
{
    public function makePrimary(Request $request)
    {
        $character = $request->user()->eveCharacters()->findOrFail($request->character);
        $character->makePrimary();
        return response()->json([
            'success' => true,
            'message' => __(':name is now your primary character.', ['name' => $character->name]),
        ]);
    }

    public function remove(Request $request)
    {
        $character = $request->user()->eveCharacters()->findOrFail($request->character);
        $character->delete();
        return response()->json([
            'success' => true,
            'message' => __(':name has been removed from your characters.', ['name' => $character->name]),
        ]);
    }
}
