<script setup>
import { usePage } from '@inertiajs/vue3';
import { route } from 'ziggy-js';

const props = defineProps({
    title: {
        type: String,
        default: null,
    },
});
</script>

<template>
    <!-- BACKGROUND -->
    <div class="pointer-events-none absolute top-0 left-0 -z-10 h-screen w-full overflow-hidden">
        <div class="absolute inset-0 bg-gradient-to-b from-slate-800 to-slate-900 opacity-75"></div>
        <img src="/img/bg-station.png" class="object-cover max-md:h-full max-md:w-auto" />
    </div>
    <!-- LAYOUT -->
    <div class="flex h-screen w-screen overflow-hidden max-md:flex-col">
        <div class="sidebar">
            <a class="logo flex items-center justify-center max-md:pl-4 lg:pt-4" :href="route('dashboard')">
                <img src="/img/logo.png" class="w-10" />
            </a>
            <div class="flex items-center justify-center gap-8 text-xl lg:flex-col">
                <a
                    :href="route('dashboard')"
                    class="text-white opacity-50 transition-all duration-300 hover:opacity-100"
                    :class="{
                        'opacity-100': route().current('dashboard'),
                    }"
                >
                    <i class="fas fa-user fa-fw"></i>
                </a>
                <a
                    :href="route('discord.auth.login')"
                    class="opacity-50 transition-all duration-300 hover:opacity-100"
                    :class="{
                        'text-green-500': usePage().props.auth.user?.discord_id,
                        'text-red-500': !usePage().props.auth.user?.discord_id,
                    }"
                    :title="usePage().props.auth.user.discord_id ? 'Your Discord is linked to WHM' : 'Your Discord is not linked to WHM'"
                >
                    <i class="fab fa-discord fa-fw"></i>
                </a>
                <a
                    v-if="usePage().props.auth.user?.is_admin"
                    :href="route('admin.users')"
                    class="text-white opacity-50 transition-all duration-300 hover:opacity-100"
                    :class="{
                        'opacity-100': route().current('admin.users'),
                    }"
                >
                    <i class="fas fa-users fa-fw"></i>
                </a>
            </div>
            <div></div>
        </div>
        <div class="h-full w-full overflow-y-auto">
            <h1 v-if="props.title" class="mt-5 px-5 pt-5 max-md:text-center">{{ props.title }}</h1>
            <div class="main flex w-full flex-grow flex-col overflow-y-auto p-5">
                <slot />
            </div>
        </div>
    </div>
    <alert-manager />
</template>
