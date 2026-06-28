<?php

namespace App\Http\Controllers;

use App\Models\User;
use Inertia\Inertia;
use Inertia\Controller;
use App\Models\EveUniverse;
use App\Models\EveCharacter;
use Illuminate\Http\Request;
use App\Services\EveOnlineProvider;
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

    public function usersMakePrimary(User $user, EveCharacter $eveCharacter)
    {
        abort_unless($eveCharacter->user_id === $user->id, 404);

        $eveCharacter->makePrimary();

        return response()->json([
            'success' => true,
            'message' => __(':name is now the primary character for :user.', [
                'name' => $eveCharacter->name,
                'user' => $user->name,
            ]),
        ]);
    }

    public function fittingsIndex()
    {
        $users = User::with('eveCharacters')->get();
        return Inertia::render('admin/Fittings', [
            'users' => $users,
        ]);
    }

    public function fittingsConvertSkillPlan(Request $request)
    {
        // Make sure the skill plan is available
        $skillsPlan = $request->input('skill_plan');
        if (!$skillsPlan) {
            return response()->json(['error' => 'Skill plan is required'], 400);
        }

        $skills = explode("\n", $skillsPlan);
        // Loop a first time on each $skills, and only keep the highest level for each skill
        $skills = array_reduce($skills, function ($carry, $skill) {
            $skill = trim($skill);
            if (empty($skill)) {
                return $carry;
            }
            $parts = explode(' ', $skill);
            $name = implode(' ', array_slice($parts, 0, -1));
            $level = (int) end($parts);
            if (!isset($carry[$name]) || $carry[$name] < $level) {
                $carry[$name] = $level;
            }
            return $carry;
        }, []);

        // Loop over each skill, and get the skill name in $name and the level in $level
        $skillsPlan = [];
        foreach ($skills as $name => $level) {
            // Look for each EveUniverse model that has the name we're looking for, knowing that you need to check EveUniverse->content['nape'] (it's a JSON array)
            $eveSkill = EveUniverse::whereJsonContains('content->name', $name)
                ->first();

            $skillsPlan[$eveSkill->item_id] = [
                'name' => $eveSkill->content['name'],
                'level' => $level,
                'trained' => false,
            ];
        }

        return $skillsPlan;
    }

    public function fittingsCheck(Request $request, EveCharacter $eveCharacter)
    {
        // Validate the request
        $request->validate([
            'skill_plan' => 'required|array',
        ]);

        $skillsPlan = $request->input('skill_plan');

        // Get the EveOnlineProvider instance
        $eveProvider = app(EveOnlineProvider::class);

        // Get the character's skills
        $characterSkills = $eveProvider->getSkills($eveCharacter);
        // If we get no skills
        if (!$characterSkills || !isset($characterSkills['skills'])) {
            return response()->json([
                'skills' => [],
                'fully_trained' => false,
            ]);
        }

        // Check each skill in the plan against the character's skills
        $fullyTrained = true;
        // Loop over each character skill
        foreach ($characterSkills['skills'] as $skill) {
            // If that skill is required in the skill plan
            if (isset($skillsPlan[$skill['skill_id']])) {
                // Check the level
                if ($skill['trained_skill_level'] >= $skillsPlan[$skill['skill_id']]['level']) {
                    // If the skill is trained, mark it as such
                    $skillsPlan[$skill['skill_id']]['trained'] = true;
                    continue;
                } else {
                    $fullyTrained = false;
                }
            }
        }

        return response()->json([
            'skills' => $skillsPlan,
            'fully_trained' => $fullyTrained,
        ]);
    }
}
