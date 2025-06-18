<script setup>
const props = defineProps({
    type: {
        type: String,
        default: 'info',
    },
});

// Add emits for close functionality
const emit = defineEmits(['close']);
</script>

<template>
    <div
        class="alert rounded border px-4 py-4 text-white"
        :class="{
            'border-red-600 bg-red-700': type === 'error',
            'border-green-600 bg-green-700': type === 'success',
            'border-yellow-600 bg-yellow-700': type === 'warning',
            'border-blue-600 bg-blue-700': type === 'info',
        }"
    >
        <div class="flex items-center justify-between">
            <div><slot /></div>
            <button @click="emit('close')" class="text-white hover:text-gray-200">
                <i class="fas fa-times"></i>
            </button>
        </div>
    </div>
</template>
