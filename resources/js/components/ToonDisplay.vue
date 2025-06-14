<script setup>
import { router } from '@inertiajs/vue3';
const props = defineProps({
    character: {
        type: Object,
        required: true,
    },
});

import { ref } from 'vue';
const isLoading = ref(false);

const makePrimary = async () => {
    isLoading.value = true;
    try {
        await window.axios.post(route('eve.characters.make-primary', { character: props.character.id }));
        // Reload to reflect changes
        router.reload();
    } catch (error) {
        console.error('Error making character primary:', error);
    } finally {
        isLoading.value = false;
    }
};

const remove = async () => {
    isLoading.value = true;
    try {
        await window.axios.post(route('eve.characters.remove', { character: props.character.id }));
        // Reload to reflect changes
        router.reload();
    } catch (error) {
        console.error('Error removing character:', error);
    } finally {
        isLoading.value = false;
    }
};
</script>

<template>
    <div class="bg-blur relative flex items-center gap-4 overflow-hidden border border-white/25 px-4 py-2">
        <div
            class="absolute top-0 left-0 flex h-full w-full items-center justify-center gap-4 bg-slate-700/95 opacity-0 transition-opacity duration-300 hover:opacity-100"
        >
            <!-- MAKE PRIMARY -->
            <a
                href="#"
                class="btn"
                @click.prevent="makePrimary()"
                v-if="!character.is_primary"
                :class="{ 'pointer-events-none opacity-75': isLoading }"
            >
                <i class="fas" :class="isLoading ? 'fa-spinner fa-spin' : 'fa-crown'"></i>
            </a>

            <!-- REFRESH -->
            <a :href="route('esi.auth.login')" class="btn">
                <i class="fas fa-sync"></i>
            </a>

            <!-- DELETE -->
            <a href="#" class="btn btn-danger" @click.prevent="remove()" :class="{ 'pointer-events-none opacity-75': isLoading }">
                <i class="fas" :class="isLoading ? 'fa-spinner fa-spin' : 'fa-trash'"></i>
            </a>
        </div>
        <div
            class="rounded-full border-3 p-1"
            :class="{
                'border-green-500/50': character.is_valid,
                'border-red-500/50': !character.is_valid,
            }"
        >
            <img :src="'https://images.evetech.net/characters/' + character.character_id + '/portrait?size=128'" class="w-16 rounded-full" />
        </div>
        <div>
            <div class="font-bold">
                <i v-if="character.is_primary" class="fas fa-crown mr-2 text-yellow-500"></i>
                {{ character.name }}
            </div>
            <div class="text-xs">
                <span>{{ character.corporation.name }}</span>
                <span v-if="character.corporation.ticker"> [{{ character.corporation.ticker }}]</span>
            </div>
        </div>
        <div class="absolute -top-1/12 -right-1/12 -z-10 w-1/3 rotate-12 mix-blend-screen">
            <img :src="'https://images.evetech.net/corporations/' + character.corporation.corporation_id + '/logo'" class="w-full opacity-25" />
        </div>
    </div>
</template>
