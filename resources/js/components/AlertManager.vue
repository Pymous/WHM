<template>
    <div class="alert-container">
        <alert v-for="(alert, index) in alerts" :key="index" :type="alert.type" @close="removeAlert(index)" class="mb-2">
            {{ alert.message }}
        </alert>
    </div>
</template>

<script setup>
import { onMounted, onUnmounted, ref } from 'vue';

const alerts = ref([]);

const addAlert = (detail) => {
    alerts.value.push({
        type: detail.type,
        message: detail.message,
    });

    // Auto-remove after 5 seconds
    setTimeout(() => {
        if (alerts.value.length > 0) {
            alerts.value.shift();
        }
    }, 5000);
};

const removeAlert = (index) => {
    alerts.value.splice(index, 1);
};

// Event handler for showing alerts
const handleShowAlert = (event) => {
    addAlert(event.detail);
};

onMounted(() => {
    window.addEventListener('show-alert', handleShowAlert);
});

onUnmounted(() => {
    window.removeEventListener('show-alert', handleShowAlert);
});
</script>

<style scoped>
.alert-container {
    position: fixed;
    top: 20px;
    right: 20px;
    z-index: 9999;
    max-width: 400px;
}
</style>
