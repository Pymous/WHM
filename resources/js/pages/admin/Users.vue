<script setup>
import { Head } from '@inertiajs/vue3';

const props = defineProps({
    users: {
        type: Array,
        required: true,
    },
});

// a computed that is a string of all the user.eve_characters.name
const userCharacters = (user) => {
    return user.eve_characters.map((character) => character.name).join(', ');
};
</script>

<template>
    <Head title="Welcome"> </Head>
    <AppLayout title="MEMBERS LIST">
        <div
            v-for="user in users"
            class="bg-blur relative flex w-full items-center justify-between gap-2 overflow-hidden border border-white/25 px-4 py-2"
        >
            <div>
                <div class="font-bold">
                    <!-- <i v-if="character.is_primary" class="fas fa-crown mr-2 text-yellow-500"></i> -->
                    {{ user.name }}
                </div>
                <div class="text-xs">
                    {{ userCharacters(user) }}
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
            <!-- <div class="absolute -top-1/12 -right-1/12 -z-10 w-1/3 rotate-12 mix-blend-screen" v-if="character.corporation">
            <img :src="'https://images.evetech.net/corporations/' + character.corporation.corporation_id + '/logo'" class="w-full opacity-25" />
        </div> -->
        </div>
    </AppLayout>
</template>
