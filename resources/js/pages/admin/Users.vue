<script setup>
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    users: {
        type: Array,
        required: true,
    },
});

const isLoading = ref(false);

const forceDiscordSync = async () => {
    isLoading.value = true;
    try {
        await window.axios.get(route('admin.users.force-discord-sync'));
    } catch (error) {
        console.error('Error :', error);
    } finally {
        isLoading.value = false;
    }
};
</script>

<template>
    <Head title="Members List"> </Head>
    <AppLayout title="MEMBERS LIST">
        <div class="mb-8 w-full text-right">
            <a
                href="#"
                class="btn"
                @click.prevent="forceDiscordSync()"
                :class="{ 'pointer-events-none opacity-75': isLoading }"
                :disabled="isLoading"
                title="Force a Discord sync"
            >
                <i class="fas fa-fw" :class="isLoading ? 'fa-spinner fa-spin' : 'fa-arrows-rotate'"></i>
                Discord Sync
            </a>
        </div>
        <div class="flex w-full flex-col items-center justify-center gap-4">
            <user-display v-for="user in users" :key="user.id" :user="user" />
        </div>
    </AppLayout>
</template>
