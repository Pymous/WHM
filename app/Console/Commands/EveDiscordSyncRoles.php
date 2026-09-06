<?php

namespace App\Console\Commands;

use App\Models\EveCharacter;
use App\Services\EveCorporationAccessResolver;
use App\Services\EveOnlineProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
    public function handle(EveOnlineProvider $provider, EveCorporationAccessResolver $access)
    {
        $baseUrl = 'https://discord.com/api/v10';
        $botToken = config('services.discord.bot_token');
        $guildId = config('services.discord.server_id');
        $corporationId = config('eve.corporations.main');

        if (! $botToken || ! $guildId || ! $corporationId) {
            Log::error('EveDiscordSyncRoles: Discord or corporation configuration is incomplete.');
            $this->error('Please configure the Discord bot token, server ID, and main EVE corporation.');

            return Command::FAILURE;
        }

        // Get the Discord Members
        $discordUsers = $this->fetchDiscordMembers($baseUrl, (string) $guildId, (string) $botToken);

        if ($discordUsers === null) {
            return Command::FAILURE;
        }

        // Get the Discord Roles
        $url = "{$baseUrl}/guilds/{$guildId}/roles";
        $response = Http::withHeaders([
            'Authorization' => "Bot {$botToken}",
        ])->get($url);

        if (! $response->successful() || ! is_array($response->json()) || ! array_is_list($response->json())) {
            $this->discordError('fetching roles', $response->status(), $response->body());

            return Command::FAILURE;
        }

        $discordRoles = collect($response->json());

        $roles = [];
        // Transform the roles into a key-value pair for easier access later
        foreach ($discordRoles as $k => $v) {
            $roles[$v['name']] = $v['id'];
        }

        // A CEO is preferred, but any linked Director with the scopes required by
        // this command can keep role synchronization operational.
        $actor = $access->director((int) $corporationId, [
            'esi-characters.read_corporation_roles.v1',
            'esi-corporations.read_corporation_membership.v1',
            'esi-corporations.read_titles.v1',
        ]);
        if (! $actor) {
            Log::warning('No CEO or Director with the required ESI scopes is available, aborting Discord role sync.', [
                'corporation_id' => $corporationId,
            ]);

            return Command::FAILURE;
        }

        $provider->setCaller($actor);

        // GET EVE TITLES
        $eveTitles = $provider->getCorporationTitles((int) $corporationId);
        $eveTitlesAssoc = collect();

        if (! $eveTitles) {
            Log::info('No titles found in the corporation, aborting the command.');

            return Command::FAILURE;
        }

        // Loop on each title
        foreach ($eveTitles as $title) {
            $eveTitlesAssoc->put($title['title_id'], $title['name']);
        }

        // GET EVE DIRECTORS (Array of character_id)
        $eveDirectors = $provider->getCorporationDirectors((int) $corporationId);
        if ($eveDirectors === null) {
            Log::warning('Directors could not be read from ESI, aborting Discord role sync.', [
                'corporation_id' => $corporationId,
                'actor_character_id' => $actor->character_id,
            ]);

            return Command::FAILURE;
        }

        // GET EVE MEMBERS
        $eveMembers = $provider->getCorporationMembersTitles((int) $corporationId);
        if (! $eveMembers) {
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

            if (! $character) {
                continue; // Skip if no character found
            }

            $user = $character->user; // Get the User associated with the EveCharacter
            if ($user) {
                // Check if the user is saved as a corp member yet, and if not, update it
                if (! $user->is_member) {
                    $user->is_member = true; // Set the user as a member of the corporation
                    $user->save(); // Save the user to update the is_member field
                    Log::info("User {$user->id} ({$user->name}) is now marked as a member of the corporation.");
                }

                // If the user has a discord_id, we can add him to the $members array for processing
                if ($user->discord_id) {
                    // We add the infos to the $members array, using the discord_id as key for easy lookup later in the loop
                    $members[$user->discord_id] = [
                        'user' => $user,
                        'characters' => $user->eveCharacters,
                        'titles' => $member['titles'],
                    ];
                }
            }
        }

        $failedUpdates = 0;

        // Loop on each $users and get the User (in database)
        foreach ($discordUsers as $discordUser) {
            // If that Discord user is in the $members array, it means he is a member of the corp
            if (@$members[$discordUser['user']['id']]) {

                $member = $members[$discordUser['user']['id']];

                // Construct the roles array for the member
                $rolesForMember = [];
                foreach ($discordUser['roles'] as $roleId) {
                    $roleName = collect($discordRoles)->firstWhere('id', $roleId)['name'] ?? null;
                    if ($roleName && ! $eveTitlesAssoc->contains($roleName)) {
                        $rolesForMember[] = $roleId; // Keep the role if it's not in the EVE titles
                    }
                }

                // Check if $discordRoles has the role "Member" and add it to the member
                if (isset($roles['Member']) && ! in_array($roles['Member'], $rolesForMember)) {
                    $rolesForMember[] = $roles['Member']; // Add the "Member" role if not already present
                }

                // Loop over each $member['characters'] (EveCharacter) and check if the character is a director
                foreach ($member['characters'] as $character) {
                    // If the character is a director, add the "Director" role
                    if (in_array($character->character_id, $eveDirectors) && isset($roles['Director']) && ! in_array($roles['Director'], $rolesForMember)) {
                        $rolesForMember[] = $roles['Director']; // Add the "Director" role if not already present
                    }
                }

                // Loop through the member's titles and add the corresponding Discord roles
                foreach ($member['titles'] as $title) {
                    // Look in the eveTitles and search for a title with the same id as $title, and store the name
                    $titleName = collect($eveTitles)->firstWhere('title_id', $title)['name'] ?? null;
                    // Check if that titleName exists in the Discord $roles array
                    if (isset($roles[$titleName])) {
                        $rolesForMember[] = $roles[$titleName]; // Add the role ID to the member's roles
                    }
                }

                // Make sure we only have unique roles
                $rolesForMember = array_unique($rolesForMember);

                // Check if $rolesForMember is different from $discordUser['roles'], if not, we can skip the update
                $requireUpdate = false;
                if (count($rolesForMember) !== count($discordUser['roles'])) {
                    $requireUpdate = true; // Different number of roles, update is required
                } else {
                    // Check if the roles are the same
                    foreach ($rolesForMember as $roleId) {
                        if (! in_array($roleId, $discordUser['roles'])) {
                            $requireUpdate = true; // At least one role is different, update is required
                            break;
                        }
                    }
                }

                if (! $requireUpdate) {
                    continue; // No update needed, skip to the next user
                }

                // Find the primary character for the member
                $primaryCharacter = $member['characters']->firstWhere('is_primary', true);
                $nick = null;
                if ($primaryCharacter) {
                    $nick = '[FO2RE] '.$primaryCharacter->name;
                }

                // Discord API has a rate limit, so we need to wait a bit before updating the user
                sleep(1);

                // And update his Discord user with the new roles
                $updateUrl = "{$baseUrl}/guilds/{$guildId}/members/{$discordUser['user']['id']}";
                $updateResponse = Http::withHeaders([
                    'Authorization' => "Bot {$botToken}",
                ])->patch($updateUrl, [
                    'roles' => $rolesForMember,
                    'nick' => $nick,
                ]);

                if (! $updateResponse->successful()) {
                    $failedUpdates++;
                    $this->discordError(
                        "updating member {$discordUser['user']['id']}",
                        $updateResponse->status(),
                        $updateResponse->body()
                    );
                }
            }
            // This DiscordUser is not in the corporation
            else {
                // Remove every role that this $discordUser as that has the same name as one of the titles in $eveTitlesAssoc
                $rolesForMember = [];
                foreach ($discordUser['roles'] as $roleId) {
                    $roleName = collect($discordRoles)->firstWhere('id', $roleId)['name'] ?? null;
                    if ($roleName && ! $eveTitlesAssoc->contains($roleName)) {
                        $rolesForMember[] = $roleId; // Keep the role if it's not in the EVE titles
                    }
                }

                // If the Discord user has the same roles left after filtering, we can skip the update
                if (count($discordUser['roles']) === count($rolesForMember)) {
                    continue;
                }

                // Discord API has a rate limit, so we need to wait a bit before updating the user
                sleep(1);

                // Otherwise, we can update the Discord user with the remaining roles
                $updateUrl = "{$baseUrl}/guilds/{$guildId}/members/{$discordUser['user']['id']}";
                $updateResponse = Http::withHeaders([
                    'Authorization' => "Bot {$botToken}",
                ])->patch($updateUrl, [
                    'roles' => $rolesForMember,
                    'nick' => null,
                ]);

                if (! $updateResponse->successful()) {
                    $failedUpdates++;
                    $this->discordError(
                        "updating former member {$discordUser['user']['id']}",
                        $updateResponse->status(),
                        $updateResponse->body()
                    );
                }
            }
        }

        if ($failedUpdates > 0) {
            $this->error("{$failedUpdates} Discord member update(s) failed.");

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * @return array<int, array>|null
     */
    private function fetchDiscordMembers(string $baseUrl, string $guildId, string $botToken): ?array
    {
        $members = [];
        $after = null;

        do {
            $query = ['limit' => 1000];

            if ($after !== null) {
                $query['after'] = $after;
            }

            $response = Http::withHeaders([
                'Authorization' => "Bot {$botToken}",
            ])->get("{$baseUrl}/guilds/{$guildId}/members", $query);

            $page = $response->json();

            if (! $response->successful() || ! is_array($page) || ! array_is_list($page)) {
                $this->discordError('fetching members', $response->status(), $response->body());

                return null;
            }

            $members = [...$members, ...$page];
            $after = count($page) === 1000
                ? data_get(end($page), 'user.id')
                : null;
        } while ($after !== null);

        return $members;
    }

    private function discordError(string $operation, int $status, string $body): void
    {
        Log::error("EveDiscordSyncRoles: Discord API error {$operation}", [
            'status' => $status,
            'body' => $body,
        ]);
        $this->error("Discord API error {$operation}: HTTP {$status}.");
    }
}
