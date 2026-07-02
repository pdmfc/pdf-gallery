import { ref } from 'vue'

const defaultNotificationState = () => ({
  show: false,
  type: 'success',
  title: '',
  message: '',
  showActions: false,
  context: null,
  action: null,
  confirmLabel: 'Confirmar',
  cancelLabel: 'Cancelar',
  duration: 5000,
})

export function useEditorNotification() {
  const notification = ref(defaultNotificationState())

  const dismissNotification = () => {
    notification.value.show = false
  }

  const showNotification = (
    type,
    title,
    message,
    showActions = false,
    context = null,
    duration = 5000,
    action = null,
    confirmLabel = 'Confirmar',
    cancelLabel = 'Cancelar'
  ) => {
    notification.value.show = false

    setTimeout(() => {
      notification.value = {
        show: true,
        type,
        title,
        message,
        showActions,
        context,
        action,
        confirmLabel,
        cancelLabel,
        duration,
      }
    }, 100)
  }

  return {
    notification,
    showNotification,
    dismissNotification,
  }
}
