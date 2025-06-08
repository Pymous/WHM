<script setup>
import { Head, usePage } from '@inertiajs/vue3';
</script>

<template>
    <Head title="Welcome"> </Head>
    <div class="pointer-events-none absolute top-0 left-0 -z-10 h-screen w-full overflow-hidden">
        <!-- <div class="absolute inset-0 bg-gradient-to-b from-slate-900 to-slate-800 opacity-50"></div> -->
        <div class="absolute inset-0 bg-gradient-to-b to-slate-900"></div>
        <img src="/img/bg-wh.png" class="object-cover max-md:h-full max-md:w-auto" />
    </div>
    <div class="flex h-screen w-screen flex-col items-center justify-center gap-12 px-12">
        <img src="/img/logo.png" class="w-24" />

        <div class="flex flex-col text-center lg:max-w-1/4">
            <h1>Welcome to WHM !</h1>
            <p>
                Connect Discord, stay updated with notifications, and utilize tools designed just for
                <b class="whitespace-nowrap">you and Forgot To Repair</b>
            </p>
        </div>

        <div class="flex items-center justify-center gap-4 max-md:flex-col">
            <a :href="route('dashboard')" v-if="usePage().props.auth.user" class="btn font-bold">
                <i class="fas fa-home"></i>
            </a>
            <a :href="route('esi.auth.login')" class="btn font-bold">
                <img src="/img/logo-eve.png" class="mr-2 inline-block w-16" />
                LOG IN with EVE Online
            </a>
        </div>
    </div>
</template>
