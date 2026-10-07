import { ref, watchEffect } from 'vue'
import { useAuthStore } from '@/stores/auth'

// $can('users.create') in templates and v-can="'users.create'" (hides the element).
// Both read the auth store, so they react when permissions are refreshed.
export default {
  install(app) {
    app.config.globalProperties.$can = (permission) => useAuthStore().can(permission)

    app.directive('can', {
      mounted(el, binding) {
        const display = el.style.display
        el._canKey = ref(binding.value)
        el._canStop = watchEffect(() => {
          el.style.display = useAuthStore().can(el._canKey.value) ? display : 'none'
        })
      },
      updated(el, binding) {
        el._canKey.value = binding.value
      },
      unmounted(el) {
        el._canStop()
      },
    })
  },
}
