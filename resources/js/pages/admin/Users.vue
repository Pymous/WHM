<script setup>
import { Head, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    users: {
        type: Array,
        required: true,
    },
});

const isLoading = ref(false);
const search = ref('');
const discordFilter = ref('all');
const primaryCorpFilter = ref('all');
const mainCorpId = usePage().props.config.main_corp_id;

const hasPrimaryInCorp = (user) => {
    return (
        mainCorpId != null && user.eve_characters.some((character) => character.is_primary && String(character.corporation_id) === String(mainCorpId))
    );
};

const filteredUsers = computed(() => {
    const query = search.value.trim().toLocaleLowerCase();

    return props.users.filter((user) => {
        const hasDiscord = Boolean(user.discord_id);
        const primaryIsInCorp = hasPrimaryInCorp(user);
        const names = [user.name, ...user.eve_characters.map((character) => character.name)];

        const matchesSearch = !query || names.some((name) => name?.toLocaleLowerCase().includes(query));
        const matchesDiscord =
            discordFilter.value === 'all' ||
            (discordFilter.value === 'linked' && hasDiscord) ||
            (discordFilter.value === 'not-linked' && !hasDiscord);
        const matchesPrimaryCorp =
            primaryCorpFilter.value === 'all' ||
            (primaryCorpFilter.value === 'in-corp' && primaryIsInCorp) ||
            (primaryCorpFilter.value === 'not-in-corp' && !primaryIsInCorp);

        return matchesSearch && matchesDiscord && matchesPrimaryCorp;
    });
});

const hasActiveFilters = computed(() => {
    return search.value !== '' || discordFilter.value !== 'all' || primaryCorpFilter.value !== 'all';
});

const clearFilters = () => {
    search.value = '';
    discordFilter.value = 'all';
    primaryCorpFilter.value = 'all';
};

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
        <div class="mb-4 w-full text-right">
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
        <div class="bg-blur mb-8 grid w-full gap-4 border border-white/25 p-4 md:grid-cols-2 xl:grid-cols-[minmax(16rem,1fr)_14rem_14rem_auto]">
            <label class="flex flex-col gap-1 text-sm">
                <span class="font-semibold">Search by name</span>
                <span class="relative">
                    <i class="fas fa-search pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-white/50"></i>
                    <input
                        v-model="search"
                        type="search"
                        class="min-h-11 w-full border border-white/25 bg-slate-950/60 py-2 pr-3 pl-10 transition outline-none focus:border-sky-400"
                        placeholder="User or character name"
                    />
                </span>
            </label>

            <label class="flex flex-col gap-1 text-sm">
                <span class="font-semibold">Discord</span>
                <select
                    v-model="discordFilter"
                    class="min-h-11 border border-white/25 bg-slate-950/60 px-3 py-2 transition outline-none focus:border-sky-400"
                >
                    <option value="all">All users</option>
                    <option value="linked">Linked</option>
                    <option value="not-linked">Not linked</option>
                </select>
            </label>

            <label class="flex flex-col gap-1 text-sm">
                <span class="font-semibold">Primary character</span>
                <select
                    v-model="primaryCorpFilter"
                    class="min-h-11 border border-white/25 bg-slate-950/60 px-3 py-2 transition outline-none focus:border-sky-400"
                >
                    <option value="all">All users</option>
                    <option value="in-corp">In main corp</option>
                    <option value="not-in-corp">Not in main corp</option>
                </select>
            </label>

            <button
                type="button"
                class="min-h-11 self-end px-3 py-2 text-sm text-sky-300 transition hover:text-sky-100 disabled:pointer-events-none disabled:opacity-40"
                :disabled="!hasActiveFilters"
                @click="clearFilters"
            >
                <i class="fas fa-xmark mr-1"></i>
                Clear
            </button>
        </div>

        <div class="mb-3 w-full text-sm text-white/60">Showing {{ filteredUsers.length }} of {{ users.length }} users</div>
        <div class="flex w-full flex-col items-center justify-center gap-4">
            <user-display v-for="user in filteredUsers" :key="user.id" :user="user" />
            <div v-if="filteredUsers.length === 0" class="bg-blur w-full border border-white/25 px-4 py-8 text-center text-white/60">
                No users match these filters.
            </div>
        </div>
    </AppLayout>
</template>
