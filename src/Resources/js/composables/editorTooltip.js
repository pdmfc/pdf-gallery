import { reactive } from 'vue'

const TOOLTIP_SELECTOR = '[title], [data-ie-tooltip]'

/** Atraso curto — os tooltips nativos do browser demoram ~1s. */
const SHOW_DELAY_MS = 80

export const editorTooltip = reactive({
  visible: false,
  text: '',
  left: 0,
  top: 0,
})

let showTimer = null
let activeEl = null
let boundRoot = null
let scrollListener = null

const getTooltipText = (el) => el.getAttribute('title') || el.dataset.ieTooltip || ''

const stripNativeTitle = (el) => {
  const text = getTooltipText(el)
  if (!text) {
    return
  }
  el.dataset.ieTooltip = text
  el.removeAttribute('title')
}

const positionTooltip = (el) => {
  const rect = el.getBoundingClientRect()
  editorTooltip.left = rect.left + rect.width / 2
  editorTooltip.top = rect.top
}

const hideTooltip = () => {
  clearTimeout(showTimer)
  showTimer = null
  editorTooltip.visible = false
  activeEl = null
}

const showTooltip = (el) => {
  const text = getTooltipText(el)
  if (!text) {
    return
  }
  stripNativeTitle(el)
  activeEl = el
  positionTooltip(el)
  editorTooltip.text = text
  editorTooltip.visible = true
}

const findTooltipTarget = (el, root) => {
  if (!el || !root.contains(el)) {
    return null
  }
  const target = el.closest(TOOLTIP_SELECTOR)
  if (!target || !root.contains(target)) {
    return null
  }
  return target
}

const onPointerOver = (e) => {
  const target = findTooltipTarget(e.target, boundRoot)
  if (!target) {
    return
  }
  if (activeEl === target && editorTooltip.visible) {
    const text = getTooltipText(target)
    if (text) {
      editorTooltip.text = text
    }
    positionTooltip(target)
    return
  }
  clearTimeout(showTimer)
  showTimer = setTimeout(() => showTooltip(target), SHOW_DELAY_MS)
}

const onPointerOut = (e) => {
  clearTimeout(showTimer)
  const related = e.relatedTarget
  if (related && boundRoot?.contains(related) && findTooltipTarget(related, boundRoot)) {
    return
  }
  hideTooltip()
}

const onScrollOrResize = () => {
  if (activeEl && editorTooltip.visible) {
    positionTooltip(activeEl)
  }
}

const attachScrollListener = () => {
  if (scrollListener) {
    return
  }
  scrollListener = onScrollOrResize
  window.addEventListener('scroll', scrollListener, true)
  window.addEventListener('resize', scrollListener)
}

const detachScrollListener = () => {
  if (!scrollListener) {
    return
  }
  window.removeEventListener('scroll', scrollListener, true)
  window.removeEventListener('resize', scrollListener)
  scrollListener = null
}

export const editorTooltipRoot = {
  mounted(el) {
    boundRoot = el
    el.addEventListener('pointerover', onPointerOver)
    el.addEventListener('pointerout', onPointerOut)
    el.addEventListener('focusin', onPointerOver)
    el.addEventListener('focusout', onPointerOut)
    attachScrollListener()
  },
  unmounted(el) {
    el.removeEventListener('pointerover', onPointerOver)
    el.removeEventListener('pointerout', onPointerOut)
    el.removeEventListener('focusin', onPointerOver)
    el.removeEventListener('focusout', onPointerOut)
    if (boundRoot === el) {
      hideTooltip()
      boundRoot = null
      detachScrollListener()
    }
  },
}
