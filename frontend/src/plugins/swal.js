import Swal from 'sweetalert2'

const base = Swal.mixin({
  buttonsStyling: false,
  reverseButtons: true,
  customClass: {
    popup: 'app-swal-popup',
    title: 'app-swal-title',
    htmlContainer: 'app-swal-html',
    confirmButton: 'app-swal-btn app-swal-btn--confirm',
    cancelButton: 'app-swal-btn app-swal-btn--cancel',
    denyButton: 'app-swal-btn app-swal-btn--deny',
    actions: 'app-swal-actions',
  },
})

const Toast = Swal.mixin({
  toast: true,
  position: 'top-end',
  showConfirmButton: false,
  timer: 3200,
  timerProgressBar: true,
  customClass: {
    popup: 'app-swal-toast',
    title: 'app-swal-toast-title',
  },
  didOpen: (toast) => {
    toast.onmouseenter = Swal.stopTimer
    toast.onmouseleave = Swal.resumeTimer
  },
})

/**
 * Alert genérico (Swal.fire)
 */
export function fire(options = {}) {
  if (typeof options === 'string') {
    return base.fire({ title: options })
  }
  return base.fire(options)
}

/**
 * Confirmação padrão do sistema
 * @returns {Promise<boolean>}
 */
export async function confirm(options = {}) {
  const {
    title = 'Confirmar ação',
    text = 'Deseja continuar?',
    confirmButtonText = 'Confirmar',
    cancelButtonText = 'Cancelar',
    icon = 'warning',
    ...rest
  } = typeof options === 'string' ? { text: options } : options

  const result = await base.fire({
    title,
    text,
    icon,
    showCancelButton: true,
    focusCancel: true,
    confirmButtonText,
    cancelButtonText,
    ...rest,
  })

  return result.isConfirmed === true
}

/**
 * Confirmação padrão de exclusão
 * @returns {Promise<boolean>}
 */
export async function confirmDelete(options = {}) {
  const count = options.count
  const entity = options.entity || 'registro'
  const entityPlural = options.entityPlural || `${entity}s`
  const article = options.feminine ? 'esta' : 'este' // "esta categoria", "esta transação"

  const defaultText =
    count != null
      ? count === 1
        ? `Deseja realmente excluir ${article} ${entity}? Esta ação não pode ser desfeita.`
        : `Deseja realmente excluir ${count} ${entityPlural}? Esta ação não pode ser desfeita.`
      : options.text || `Deseja realmente excluir ${article} ${entity}? Esta ação não pode ser desfeita.`

  return confirm({
    title: options.title || 'Excluir registro',
    text: defaultText,
    icon: 'warning',
    confirmButtonText: options.confirmButtonText || 'Sim, excluir',
    cancelButtonText: options.cancelButtonText || 'Cancelar',
    customClass: {
      popup: 'app-swal-popup',
      title: 'app-swal-title',
      htmlContainer: 'app-swal-html',
      confirmButton: 'app-swal-btn app-swal-btn--danger',
      cancelButton: 'app-swal-btn app-swal-btn--cancel',
      actions: 'app-swal-actions',
    },
    ...options,
    text: defaultText,
  })
}

/**
 * Toasts
 */
export function toast(message, icon = 'info') {
  return Toast.fire({ icon, title: message })
}

export function toastSuccess(message = 'Operação realizada com sucesso!') {
  return toast(message, 'success')
}

export function toastError(message = 'Ocorreu um erro. Tente novamente.') {
  return toast(message, 'error')
}

export function toastWarning(message) {
  return toast(message, 'warning')
}

export function toastInfo(message) {
  return toast(message, 'info')
}

/** API unificada */
export const swal = {
  fire,
  confirm,
  confirmDelete,
  toast,
  toastSuccess,
  toastError,
  toastWarning,
  toastInfo,
  raw: Swal,
}

export default swal
