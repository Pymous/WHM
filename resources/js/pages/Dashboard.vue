<script setup>
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const discordID = usePage().props.auth.user.discord_id;

const sortedEveCharacters = computed(() => {
    const user = usePage().props.auth?.user;
    const characters = user?.eve_characters || [];

    return [...characters].sort((a, b) => {
        // Sort by is_primary (true values first)
        return (b.is_primary || false) - (a.is_primary || false);
    });
});
</script>

<template>
    <Head title="Welcome"> </Head>
    <AppLayout title="Dashboard">
        <div class="max-md:order-2 lg:w-2/12">
            <h2>NEW MEMBERS</h2>
            <alert class="max-w-xs"> Work In Progress </alert>
        </div>
        <div class="max-md:order-1 lg:w-5/12">
            <alert v-if="!discordID" class="mb-4" type="warning">
                <b>Your Discord is not linked to WHM</b>
                <br />
                Please link your Discord account by clicking the Discord icon in the sidebar.
            </alert>
            <!-- QUICK LINKS -->
            <div class="grid grid-cols-2 gap-4">
                <a href="https://help.fo2re.space/" target="_blank" class="btn text-lg uppercase">
                    <i class="fas fa-book mr-4 text-xl"></i> Guides
                </a>
                <a href="https://pf.fo2re.space/" target="_blank" class="btn text-lg uppercase">
                    <i class="fas fa-rocket mr-4 text-xl"></i> Pathfinder
                </a>
                <a :href="route('discord.auth.login')" class="btn text-lg uppercase"> <i class="fab fa-discord mr-4 text-xl"></i> Discord </a>
                <a href="https://janice.e-351.com" target="_blank" class="btn text-lg uppercase">
                    <i class="fas fa-chart-line mr-4 text-xl"></i> Janice
                </a>
            </div>
            <!-- TOONS MANAGEMENT -->
            <h2 class="mt-8">MY TOONS</h2>
            <div class="grid grid-cols-2 gap-4">
                <toon-display
                    v-if="sortedEveCharacters?.length > 0"
                    v-for="character in sortedEveCharacters"
                    :key="character.character_id"
                    :character="character"
                />
                <!-- ADD NEW CHARACTER -->
                <a :href="route('esi.auth.login')" class="btn flex items-center gap-4 px-4 py-2">
                    <i class="fas fa-square-plus text-3xl text-white"></i>
                </a>
            </div>
        </div>
        <div class="max-md:order-3 lg:w-5/12">
            <h2>CORP NOTIFICATIONS FEED</h2>
            <alert class="max-w-xs"> Work In Progress </alert>
        </div>
    </AppLayout>
</template>
