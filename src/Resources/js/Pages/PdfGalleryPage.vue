<script setup>
import { Head } from '@inertiajs/vue3'
import PdfGallery from '../Components/PdfGallery.vue'
import { usePdfGalleryUi } from '../composables/usePdfGalleryUi.js'

defineProps({
  userId: {
    type: [String, Number],
    required: true,
  },
})

const ui = usePdfGalleryUi()
</script>

<template>
  <Head :title="ui.title" />
  <div class="min-h-screen bg-slate-100 px-4 py-8">
    <div class="mx-auto max-w-7xl space-y-6">
      <header>
        <h1 class="text-2xl font-semibold text-slate-900">{{ ui.title }}</h1>
        <p class="mt-1 text-sm text-slate-600">
          Utilizador #{{ userId }} — carregar, pré-visualizar, seleccionar, juntar e imprimir.
        </p>
      </header>

      <PdfGallery :user-id="userId" />
    </div>
  </div>
</template>
