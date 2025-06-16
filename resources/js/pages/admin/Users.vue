<script setup>
import { Head } from '@inertiajs/vue3';

const props = defineProps({
    users: {
        type: Array,
        required: true,
    },
});
</script>

<template>
    <Head title="Members List"> </Head>
    <AppLayout title="MEMBERS LIST">
        <div class="flex w-full flex-col items-center justify-center gap-4">
            <user-display v-for="user in users" :key="user.id" :user="user" />
        </div>
    </AppLayout>
</template>
