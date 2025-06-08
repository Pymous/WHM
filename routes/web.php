<?php

use App\Models\User;
use Inertia\Inertia;
use Illuminate\Http\Request;
use App\Services\EveOnlineProvider;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\EsiController;
use Illuminate\Support\Facades\Session;
use Laravel\Socialite\Facades\Socialite;
use App\Http\Controllers\DiscordController;
use App\Http\Controllers\EveController;
use App\Models\EveCharacter;

Route::get('/', function () {
    return Inertia::render('Landing');
})->name('landing');

Route::get('dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth'])->name('dashboard');

// Discord Authentication Routes
Route::get('/discord/auth/login', [DiscordController::class, 'authLogin'])->name('discord.auth.login');
Route::get('/discord/auth/callback', [DiscordController::class, 'authCallback'])->name('discord.auth.callback');

// ESI Authentication Routes
Route::get('/esi/auth/login', [EsiController::class, 'authLogin'])->name('esi.auth.login');
Route::get('/esi/auth/logout', [EsiController::class, 'authLogout'])->name('esi.auth.logout');
Route::get('/esi/auth/callback', [EsiController::class, 'authCallback'])->name('esi.auth.callback');
Route::get('/esi/corp', [EsiController::class, 'corp'])->name('esi.corp');

// EVE Routes
Route::group(['prefix' => 'eve', 'middleware' => ['auth']], function () {
    Route::post('/characters/{character}/make-primary', [EveController::class, 'makePrimary'])->name('eve.characters.make-primary');
    Route::post('/characters/{character}/remove', [EveController::class, 'remove'])->name('eve.characters.remove');
});

Route::get('/verify', function (EveOnlineProvider $provider) {
    // Call EVE ESI /verify with the current user primary EveCharacter
    $user = Auth::user();
    if (!$user || !$user->eveCharacters()->where('is_primary', true)->exists()) {
        return redirect()->route('dashboard')->with('error', 'You need to have a primary EVE character set up.');
    }
    $primaryCharacter = $user->eveCharacters()->where('is_primary', true)->first();
    $verification = $provider->request($primaryCharacter, 'GET', '/verify');
    if ($verification) {
        return redirect()->route('dashboard')->with('success', 'EVE Online verification successful.');
    } else {
        dd($verification);
        return redirect()->route('dashboard')->with('error', 'EVE Online verification failed.');
    }
});

Route::get('/test', function (EveOnlineProvider $provider) {
    $baseUrl = 'https://discord.com/api/v10';
    $botToken = env('DISCORD_BOT_TOKEN'); // Get from .env file
    $guildId = env('DISCORD_SERVER_ID');

    // Get the Discord Members
    $url = "{$baseUrl}/guilds/{$guildId}/members";
    $response = Http::withHeaders([
        'Authorization' => "Bot {$botToken}",
    ])->get($url, [
        'limit' => 100, // Optional: specify the number of members to retrieve
    ]);
    $discordUsers = $response->json();

    // Get the Discord Roles
    $url = "{$baseUrl}/guilds/{$guildId}/roles";
    $response = Http::withHeaders([
        'Authorization' => "Bot {$botToken}",
    ])->get($url);
    $discordRoles = collect($response->json());

    $roles = [];
    // Transform the roles into a key-value pair for easier access later
    foreach ($discordRoles as $k => $v) {
        $roles[$v['name']] = $v['id'];
    }

    // Get the CEO from the Corp App
    $ceo = $provider->getMainCeo();
    if (!$ceo) {
        return 'No CEO found in the database.';
    }

    $provider->setCaller($ceo);

    // GET EVE TITLES
    $eveTitles = $provider->getCorporationTitles();
    $eveTitlesAssoc = collect();
    // Loop on each title
    foreach ($eveTitles as $title) {
        $eveTitlesAssoc->put($title['title_id'], $title['name']);
    }

    // GET EVE DIRECTORS (Array of character_id)
    $eveDirectors = $provider->getCorporationDirectors();





    // GET EVE MEMBERS
    $eveMembers = $provider->getCorporationMembersTitles();
    if (!$eveMembers) {
        return 'No members found in the corporation.';
    }

    // Loop over eveMembers and associate the User from $value['character_id'], only get those that have a user.discord_id associated
    $members = [];
    foreach ($eveMembers as $k => $member) {
        // Possible optimization here, don't get the primary EveChar then get all the chars a few lines later (when building $members)
        $character = EveCharacter::where([
            'character_id' => $member['character_id'],
            'is_primary' => true, // Only get primary characters
        ])->first();


        if (!$character) {
            continue; // Skip if no character found
        }
        $user = $character->user; // Get the User associated with the EveCharacter
        if ($user && $user->discord_id) {
            // Manually add the Member title to every corp member, in case it is not present
            if (!in_array('Member', $member['titles'])) {
                $member['titles'][] = 'Member';
            }

            // We add the infos to the $members array, using the discord_id as key for easy lookup later in the loop
            $members[$user->discord_id] = [
                'user' => $user,
                'characters' => $user->eveCharacters,
                'titles' => $member['titles'],
            ];
        }
    }

    // Loop on each $users and get the User (in database) 
    foreach ($discordUsers as $discordUser) {
        // If that Discord user is in the $members array, it means he is a member of the corp
        if (@$members[$discordUser['user']['id']]) {
            $member = $members[$discordUser['user']['id']];

            // Construct the roles array for the member
            $rolesForMember = [];
            foreach ($discordUser['roles'] as $roleId) {
                $roleName = collect($discordRoles)->firstWhere('id', $roleId)['name'] ?? null;
                if ($roleName && !$eveTitlesAssoc->contains($roleName)) {
                    $rolesForMember[] = $roleId; // Keep the role if it's not in the EVE titles
                }
            }

            // Check if $discordRoles has the role "Member" and add it to the member
            if (isset($roles['Member']) && !in_array($roles['Member'], $rolesForMember)) {
                $rolesForMember[] = $roles['Member']; // Add the "Member" role if not already present
            }

            // Loop over each $member['characters'] (EveCharacter) and check if the character is a director
            foreach ($member['characters'] as $character) {
                // If the character is a director, add the "Director" role
                if (in_array($character->character_id, $eveDirectors) && isset($roles['Director']) && !in_array($roles['Director'], $rolesForMember)) {
                    $rolesForMember[] = $roles['Director']; // Add the "Director" role if not already present
                }
            }

            // Loop through the member's titles and add the corresponding Discord roles
            foreach ($member['titles'] as $title) {
                if (isset($roles[$title])) {
                    $rolesForMember[] = $roles[$title]; // Add the role ID to the member's roles
                }
            }

            // Make sure we only have unique roles
            $rolesForMember = array_unique($rolesForMember);

            // Check if $rolesForMember is different from $discurdUser['roles'], if not, we can skip the update
            $requireUpdate = false;
            if (count($rolesForMember) !== count($discordUser['roles'])) {
                $requireUpdate = true; // Different number of roles, update is required
            } else {
                // Check if the roles are the same
                foreach ($rolesForMember as $roleId) {
                    if (!in_array($roleId, $discordUser['roles'])) {
                        $requireUpdate = true; // At least one role is different, update is required
                        break;
                    }
                }
            }

            if (!$requireUpdate) {
                continue; // No update needed, skip to the next user
            }

            // And update his Discord user with the new roles
            $updateUrl = "{$baseUrl}/guilds/{$guildId}/members/{$discordUser['user']['id']}";
            $updateResponse = Http::withHeaders([
                'Authorization' => "Bot {$botToken}",
            ])->patch($updateUrl, [
                'roles' => $rolesForMember,
            ]);
        }
        // This DiscordUser is not in the corporation
        else {
            // Remove every role that this $discordUser as that has the same name as one of the titles in $eveTitlesAssoc
            $rolesForMember = [];
            foreach ($discordUser['roles'] as $roleId) {
                $roleName = collect($discordRoles)->firstWhere('id', $roleId)['name'] ?? null;
                if ($roleName && !$eveTitlesAssoc->contains($roleName)) {
                    $rolesForMember[] = $roleId; // Keep the role if it's not in the EVE titles
                }
            }

            // If the user has no roles left, we can skip them
            if (empty($rolesForMember)) {
                continue;
            }

            // Otherwise, we can update the Discord user with the remaining roles
            $updateUrl = "{$baseUrl}/guilds/{$guildId}/members/{$discordUser['user']['id']}";
            $updateResponse = Http::withHeaders([
                'Authorization' => "Bot {$botToken}",
            ])->patch($updateUrl, [
                'roles' => $rolesForMember,
            ]);
        }
    }
});

Route::get('/testRoles', function () {
    $baseUrl = 'https://discord.com/api/v10';
    $botToken = env('DISCORD_BOT_TOKEN'); // Get from .env file
    $guildId = env('DISCORD_SERVER_ID');

    // Use the endpoint for guild roles
    $url = "{$baseUrl}/guilds/{$guildId}/roles";

    $response = Http::withHeaders([
        'Authorization' => "Bot {$botToken}",
    ])->get($url);

    return $response->successful()
        ? $response->json()
        : ['error' => $response->json(), 'status' => $response->status()];
});

Route::get('/list', function () {
    $baseUrl = 'https://discord.com/api/v10';
    $botToken = env('DISCORD_BOT_TOKEN');

    // Get all guilds the bot is a member of
    $url = "{$baseUrl}/users/@me/guilds";

    $response = Http::withHeaders([
        'Authorization' => "Bot {$botToken}",
    ])->get($url);

    return $response->successful()
        ? [
            'status' => 'success',
            'guilds' => $response->json(),
            'count' => count($response->json())
        ]
        : ['error' => $response->json(), 'status' => $response->status()];
});

Route::get('/bot-invite', function () {
    $clientId = env('DISCORD_CLIENT_ID');
    $permissions = 8; // 8 is Administrator, use a more specific value for production

    $inviteUrl = "https://discord.com/api/oauth2/authorize?client_id={$clientId}&permissions={$permissions}&scope=bot%20applications.commands";

    return [
        'invite_url' => $inviteUrl,
        'message' => 'Use this URL to invite the bot to your server'
    ];
});

require __DIR__ . '/settings.php';
