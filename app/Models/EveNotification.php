<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EveNotification extends Model
{
    protected $fillable = [
        'notification_id',
        'character_id',
        'type',
        'sender_id',
        'sender_type',
        'timestamp',
        'text',
        'is_read',
    ];
    protected $casts = [
        'notification_id' => 'integer',
        'character_id' => 'integer',
        'sender_id' => 'integer',
        'timestamp' => 'datetime',
        'text' => 'json',
        'is_read' => 'boolean',
    ];
    protected $table = 'eve_notifications';
    protected $primaryKey = 'notification_id';
    public $incrementing = false; // Notification ID is not auto-incrementing
    public $timestamps = true; // Use timestamps for created_at and updated_at
    /**
     * Get the character that owns the notification.
     */
    public function character()
    {
        return $this->belongsTo(EveCharacter::class, 'character_id', 'character_id');
    }

    public function getDiscordBroadcast()
    {
        switch ($this->type) {
            case 'TowerAlertMsg':
                return $this->formatTowerAlertMsg();
            case 'StructureUnderAttack':
                return $this->formatStructureUnderAttack();
            case 'StructureFuelAlert':
                return $this->formatStructureFuelAlert();
            default:
                break;
        }
        return null;
    }

    private function formatTowerAlertMsg()
    {
        // Beautify the agressors
        $agressors = [];
        if (isset($this->text['aggressorAllianceID']) && $this->text['aggressorAllianceID']) {
            $agressors[] = "Alliance : [zKill](https://zkillboard.com/alliance/{$this->text['aggressorAllianceID']}/)";
        }
        $agressors[] = "Corporation : [zKill](https://zkillboard.com/corporation/{$this->text['aggressorCorpID']}/)";
        $agressors[] = "Character : [zKill](https://zkillboard.com/character/{$this->text['aggressorID']}/)";

        // Edit shieldValue, armorValue, hullValue to be more readable
        $status = [];
        $status[] = "Shield: " . bcdiv($this->text['shieldValue'] * 100, '1', 2) . '%';
        $status[] = "Armor: " . bcdiv($this->text['armorValue'] * 100, '1', 2) . '%';
        $status[] = "Hull: " . bcdiv($this->text['hullValue'] * 100, '1', 2) . '%';

        // Associate the proper details with EveUniverse data
        $details = [];
        $item = EveUniverse::ofType('types')->find($this->text['typeID']);
        if ($item) {
            $details[] = "Type: {$item->name}";
        } else {
            $details[] = "Type ID: {$this->text['typeID']}";
        }

        $moon = EveUniverse::ofType('invNames')->find($this->text['moonID']);
        if ($moon) {
            $details[] = "Moon: {$moon->name}";
        } else {
            $details[] = "Moon ID: {$this->text['moonID']}";
        }

        // Get the System name from EveUniverse
        $system  = EveUniverse::ofType('invNames')->find($this->text['solarSystemID']);
        if ($system) {
            $system = "{$system->name}";
        } else {
            $system = "{$this->text['solarSystemID']} (System ID not found)";
        }
        $system = "**{$system}**";

        return [
            'content' => "@here",
            'embeds' => [
                [
                    'title' => 'Tower Under Attack',
                    'description' => "A tower is currently under attack in {$system}.",
                    'fields' => [
                        [
                            'name' => 'Aggressor',
                            'value' => implode("\n", $agressors),
                            'inline' => true,
                        ],
                        [
                            'name' => 'Tower Details',
                            'value' => implode("\n", $details),
                            'inline' => true,
                        ],
                        [
                            'name' => 'Current Status',
                            'value' => implode("\n", $status),
                            'inline' => true,
                        ],
                    ],
                    'color' => 15158332,
                ],
            ],
        ];
    }

    private function formatStructureUnderAttack()
    {
        // Beautify the agressors
        $agressors = [];
        if (isset($this->text['allianceID']) && $this->text['allianceID']) {
            $agressors[] = "Alliance : [zKill](https://zkillboard.com/alliance/{$this->text['allianceID']}/)";
        }
        $agressors[] = "Character : [zKill](https://zkillboard.com/character/{$this->text['charID']}/)";

        // Edit shieldValue, armorValue, hullValue to be more readable
        $status = [];
        $status[] = "Shield: " . bcdiv($this->text['shieldPercentage'], '1', 2) . '%';
        $status[] = "Armor: " . bcdiv($this->text['armorPercentage'], '1', 2) . '%';
        $status[] = "Hull: " . bcdiv($this->text['hullPercentage'], '1', 2) . '%';

        // Associate the proper details with EveUniverse data
        $station = EveUniverse::ofType('types')->find($this->text['structureTypeID']);
        if ($station) {
            $station = $station->name;
        } else {
            $station = "Type ID: {$this->text['structureTypeID']}";
        }

        // Get the System name from EveUniverse
        $system  = EveUniverse::ofType('invNames')->find($this->text['solarsystemID']);
        if ($system) {
            $system = "{$system->name}";
        } else {
            $system = "{$this->text['solarsystemID']} (System ID not found)";
        }
        $system = "**{$system}**";

        return [
            'content' => "@here",
            'embeds' => [
                [
                    'title' => $station . ' Under Attack',
                    'description' => $station . " currently under attack in {$system}.",
                    'fields' => [
                        [
                            'name' => 'Aggressor',
                            'value' => implode("\n", $agressors),
                            'inline' => true,
                        ],
                        [
                            'name' => 'Current Status',
                            'value' => implode("\n", $status),
                            'inline' => true,
                        ],
                    ],
                    'color' => 15158332,
                ],
            ],
        ];
    }

    private function formatStructureFuelAlert()
    {
        // Associate the proper details with EveUniverse data
        $station = EveUniverse::ofType('types')->find($this->text['structureTypeID']);
        if ($station) {
            $station = $station->name;
        } else {
            $station = "Type ID: {$this->text['structureTypeID']}";
        }

        // Get the System name from EveUniverse
        $system  = EveUniverse::ofType('invNames')->find($this->text['solarsystemID']);
        if ($system) {
            $system = "{$system->name}";
        } else {
            $system = "{$this->text['solarsystemID']} (System ID not found)";
        }
        $system = "**{$system}**";

        return [
            'content' => "@here",
            'embeds' => [
                [
                    'title' => $station . ' running VERY low on fuel in ' . $system,
                    'color' => 16753920,
                ],
            ],
        ];
    }
}
