<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import InvoiceEditorForm from '@/components/admin/InvoiceEditorForm.vue'

const route = useRoute()

const invoiceId = computed(() => (route.params.id ? String(route.params.id) : null))
</script>

<template>
  <InvoiceEditorForm :invoice-id="invoiceId" />
</template>
