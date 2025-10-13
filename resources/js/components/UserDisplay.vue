<script setup>
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
const props = defineProps({
    user: {
        type: Object,
        required: true,
    },
});

// Return a  string of all the user.eve_characters.name
const userCharacters = computed(() => {
    return props.user.eve_characters.map((character) => character.name).join(', ');
});

// isCorpMember check if at least one EveCharacter is a member of the corp
const isCorpMember = computed(() => {
    return props.user.eve_characters.some((character) => character.corporation_id == usePage().props.config.main_corp_id);
});
</script>

<template>
    <div class="bg-blur relative flex w-full items-center justify-between gap-2 overflow-hidden border border-white/25 px-4 py-2">
        <div>
            <div class="flex items-center gap-1 font-bold">
                {{ user.name }}
                <i class="fab fa-discord text-indigo-600" v-if="user.discord_id" :title="'Discord linked (' + user.discord_id + ')'"></i>
                <i class="fas fa-rocket text-emerald-600" v-if="isCorpMember" title="At least one toon in Main Corp"></i>
            </div>
            <div class="text-xs">
                {{ userCharacters }}
            </div>
        </div>
        <div class="flex items-center gap-2">
            <div
                v-for="character in user.eve_characters"
                class="rounded-full border-3 p-1"
                :class="{
                    'border-green-500/50': character.is_valid,
                    'border-red-500/50': !character.is_valid,
                }"
                :title="character.name"
            >
                <img :src="'https://images.evetech.net/characters/' + character.character_id + '/portrait?size=128'" class="w-8 rounded-full" />
            </div>
        </div>
    </div>
</template>
