<?php

namespace App\Console\Commands;

use App\Models\EveCharacter;
use Illuminate\Console\Command;
use App\Services\EveOnlineProvider;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class EveDiscordSyncRoles extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'eve:discord:sync-roles';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Loop through all EVE Characters and sync their Discord roles with their Corporation roles, if possible';

    /**
     * Execute the console command.
     */
    public function handle(EveOnlineProvider $provider)
    {
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
            Log::info('No CEO access found in the database, aborting the command.');
            return Command::FAILURE;
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
            Log::info('No members found in the corporation, aborting the command.');
            return Command::FAILURE;
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

                // TODO: RE-ENABLE TO AVOID UNNECESSARY UPDATES
                // if (!$requireUpdate) {
                //     continue; // No update needed, skip to the next user
                // }

                // Find the primary character for the member
                $primaryCharacter = $member['characters']->firstWhere('is_primary', true);
                $nick = null;
                if ($primaryCharacter) {
                    $nick = "[FO2RE] " . $primaryCharacter->name;
                }

                // And update his Discord user with the new roles
                $updateUrl = "{$baseUrl}/guilds/{$guildId}/members/{$discordUser['user']['id']}";
                $updateResponse = Http::withHeaders([
                    'Authorization' => "Bot {$botToken}",
                ])->patch($updateUrl, [
                    'roles' => $rolesForMember,
                    'nick' => $nick,
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

                // If the Discord user has the same roles left after filtering, we can skip the update
                if (count($discordUser['roles']) === count($rolesForMember)) {
                    continue;
                }

                // Otherwise, we can update the Discord user with the remaining roles
                $updateUrl = "{$baseUrl}/guilds/{$guildId}/members/{$discordUser['user']['id']}";
                $updateResponse = Http::withHeaders([
                    'Authorization' => "Bot {$botToken}",
                ])->patch($updateUrl, [
                    'roles' => $rolesForMember,
                    'nick' => null
                ]);
            }
        }
        return Command::SUCCESS;
    }
}
