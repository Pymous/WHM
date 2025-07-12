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
const skillPlan = ref('');
const isChecked = ref({});

const startCheck = async () => {
    isLoading.value = true;
    isChecked.value = {};
    let convertedPlan = null;
    try {
        let result = await window.axios.post(route('admin.fittings.convert'), { skill_plan: skillPlan.value });
        convertedPlan = result.data;
    } catch (error) {
        console.error('Error:', error);
        isLoading.value = false;
    }

    // Loop over each props.users primary character and call admin.fittings.check with the character ID and the converted skill plan
    if (convertedPlan) {
        for (const user of props.users) {
            for (const character of user.eve_characters) {
                try {
                    let result = await window.axios.post(route('admin.fittings.check', character.id), {
                        skill_plan: convertedPlan,
                    });
                    isChecked.value[character.id] = result.data;
                } catch (error) {
                    console.error(`Error checking fittings for user ${character.id}:`, error);
                }
            }
        }
    }
    isLoading.value = false;
};
</script>

<template>
    <Head title="Fittings Check"> </Head>
    <AppLayout title="FITTINGS CHECK">
        <div class="mb-8 text-right">
            <textarea v-model="skillPlan" class="h-64 w-full rounded border p-2" placeholder="Paste your skill plan here..."></textarea>

            <a href="#" class="btn mt-3" @click.prevent="startCheck()" :class="{ 'pointer-events-none opacity-75': isLoading }" :disabled="isLoading">
                <i class="fas fa-fw" :class="isLoading ? 'fa-spinner fa-spin' : 'fa-rocket'"></i>
                Start Skill Plan Check
            </a>
        </div>
        <div class="flex w-full flex-col items-center justify-center gap-4">
            <fittings-user-display v-for="user in users" :key="user.id" :user="user" :checked="isChecked" />
        </div>
    </AppLayout>
</template>
