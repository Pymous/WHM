<script setup>
import { computed } from 'vue';
const props = defineProps({
    user: {
        type: Object,
        required: true,
    },
    checked: {
        type: Object,
        default: null,
    },
});

// Return a  string of all the user.eve_characters.name
const userCharacters = computed(() => {
    return props.user.eve_characters.map((character) => character.name).join(', ');
});

const getCharacterStatus = computed(() => {
    return (characterId) => {
        return props.checked[characterId] || null;
    };
});

// A computed hasOneCharacterTrained that returns true if at least one character is fully trained
const hasOneCharacterTrained = computed(() => {
    return props.user.eve_characters.some((character) => {
        const status = getCharacterStatus.value(character.id);
        return status && status.fully_trained === true;
    });
});
</script>

<template>
    <div
        class="relative flex w-full items-center justify-between gap-2 overflow-hidden border px-4 py-2"
        :class="{
            'border-green-700/25 bg-green-500/10': hasOneCharacterTrained,
            'bg-blur border-white/25': !hasOneCharacterTrained,
        }"
    >
        <div>
            <div class="flex items-center gap-1 font-bold">
                {{ user.name }}
            </div>
            <div class="text-xs">
                {{ userCharacters }}
            </div>
        </div>
        <div class="flex items-center gap-2">
            <div
                v-for="character in user.eve_characters"
                class="rounded-full border-3 border-green-500/50 p-1 transition-all duration-300"
                :class="{
                    'border-green-500/50': getCharacterStatus(character.id)?.fully_trained == true,
                    'border-red-500/50': getCharacterStatus(character.id)?.fully_trained == false,
                    'border-transparent': getCharacterStatus(character.id) === null,
                }"
                :title="character.name"
            >
                <img :src="'https://images.evetech.net/characters/' + character.character_id + '/portrait?size=128'" class="w-8 rounded-full" />
            </div>
        </div>
    </div>
</template>
