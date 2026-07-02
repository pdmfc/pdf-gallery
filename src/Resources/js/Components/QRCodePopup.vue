<template>
  <div v-if="show" class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50">
    <div class="mx-4 w-full max-w-md rounded-lg bg-white p-6">
      <div class="mb-4 flex items-center justify-between">
        <h3 class="text-lg font-semibold text-gray-900">Upload de Mobile</h3>
        <button
          type="button"
          class="text-gray-400 hover:text-gray-500 focus:outline-none"
          @click="$emit('close')"
        >
          <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </div>

      <div class="space-y-2 text-center text-sm text-gray-600">
        <p>Digitalize o QR Code com a câmara do telemóvel.</p>
        <p
          v-if="maxFiles != null && maxFiles > 0"
          class="font-medium text-amber-700"
        >
          O telemóvel só pode enviar {{ maxFiles }} {{ maxFiles === 1 ? documentSingular : documentPlural }} com este QR code.
        </p>
        <p
          v-if="maxUploadMb != null && maxUploadMb > 0"
          class="font-medium text-amber-700"
        >
          Cada {{ documentSingular }} pode ter no máximo {{ maxUploadMb }} MB.
        </p>
        <p>Pode fechar este popup — os documentos aparecem na galeria em tempo real.</p>
      </div>

      <div class="flex items-center justify-center rounded-lg bg-white p-4">
        <img
          v-if="isDataUrl"
          :src="qrCode"
          alt="QR Code"
          class="w-full max-w-xs"
        />
        <div v-else v-html="qrCode" class="w-full max-w-xs" style="padding-left: 10px" />
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  show: {
    type: Boolean,
    required: true,
  },
  qrCode: {
    type: String,
    required: true,
  },
  maxFiles: {
    type: Number,
    default: null,
  },
  maxUploadMb: {
    type: Number,
    default: null,
  },
  documentSingular: {
    type: String,
    default: 'documento',
  },
  documentPlural: {
    type: String,
    default: 'documentos',
  },
})

const isDataUrl = computed(() =>
  typeof props.qrCode === 'string' && props.qrCode.startsWith('data:image/')
)

defineEmits(['close'])
</script>
