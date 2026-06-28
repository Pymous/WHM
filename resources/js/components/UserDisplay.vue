<script setup>
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    user: {
        type: Object,
        required: true,
    },
});

const changingPrimaryId = ref(null);
const primaryError = ref('');

// isCorpMember check if at least one EveCharacter is a member of the corp
const isCorpMember = computed(() => {
    return props.user.eve_characters.some((character) => character.corporation_id == usePage().props.config.main_corp_id);
});

const makePrimary = async (character) => {
    if (character.is_primary || changingPrimaryId.value !== null) {
        return;
    }

    changingPrimaryId.value = character.id;
    primaryError.value = '';

    try {
        await window.axios.patch(
            route('admin.users.characters.make-primary', {
                user: props.user.id,
                eveCharacter: character.id,
            }),
        );
        router.reload({ only: ['users'], preserveScroll: true });
    } catch (error) {
        primaryError.value = error.response?.data?.message || 'Unable to change the primary character.';
        console.error('Error making character primary:', error);
    } finally {
        changingPrimaryId.value = null;
    }
};
</script>

<template>
    <div class="bg-blur relative flex w-full items-center justify-between gap-2 overflow-hidden border border-white/25 px-4 py-2">
        <div>
            <div class="flex items-center gap-1 font-bold">
                {{ user.name }}
                <i class="fab fa-discord text-indigo-600" v-if="user.discord_id" :title="'Discord linked (' + user.discord_id + ')'"></i>
                <i class="fas fa-rocket text-emerald-600" v-if="isCorpMember" title="At least one toon in Main Corp"></i>
            </div>
            <div class="flex flex-wrap items-center gap-x-1 text-xs">
                <span v-for="(character, index) in user.eve_characters" :key="character.id">
                    <i v-if="character.is_primary" class="fas fa-crown mr-0.5 text-yellow-500" title="Primary character"></i>
                    {{ character.name }}<span v-if="index < user.eve_characters.length - 1">,</span>
                </span>
            </div>
            <div v-if="primaryError" class="mt-1 text-xs text-red-400">{{ primaryError }}</div>
        </div>
        <div class="flex items-center gap-2">
            <button
                v-for="character in user.eve_characters"
                :key="character.id"
                type="button"
                class="group relative rounded-full border-3 p-1 transition"
                :class="{
                    'border-green-500/50': character.is_valid,
                    'border-red-500/50': !character.is_valid,
                    'cursor-default ring-2 ring-yellow-500/70': character.is_primary,
                    'hover:border-yellow-500 hover:ring-2 hover:ring-yellow-500/40': !character.is_primary,
                    'pointer-events-none opacity-60': changingPrimaryId !== null,
                }"
                :disabled="character.is_primary || changingPrimaryId !== null"
                :title="character.is_primary ? `${character.name} (Primary)` : `Make ${character.name} primary`"
                :aria-label="character.is_primary ? `${character.name} is the primary character` : `Make ${character.name} the primary character`"
                @click="makePrimary(character)"
            >
                <img :src="'https://images.evetech.net/characters/' + character.character_id + '/portrait?size=128'" class="w-8 rounded-full" />
                <span
                    v-if="character.is_primary"
                    class="absolute -top-1 -right-1 flex h-4 w-4 items-center justify-center rounded-full bg-yellow-500 text-[9px] text-slate-950 shadow"
                >
                    <i class="fas fa-crown"></i>
                </span>
                <span
                    v-else
                    class="absolute inset-1 flex items-center justify-center rounded-full bg-slate-950/75 text-xs text-yellow-400 opacity-0 transition-opacity group-hover:opacity-100 group-focus-visible:opacity-100"
                >
                    <i v-if="changingPrimaryId === character.id" class="fas fa-spinner fa-spin"></i>
                    <i v-else class="fas fa-crown"></i>
                </span>
            </button>
        </div>
    </div>
</template>
