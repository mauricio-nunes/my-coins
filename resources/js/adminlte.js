/**
 * AdminLTE 4 + Bootstrap 5 entry point.
 *
 * Published by `php artisan adminlte:install`. Add this file to your
 * vite.config.js input array, then `npm run dev` / `npm run build`.
 */

// Bootstrap (provides dropdowns, modals, tooltips, offcanvas, etc.)
import * as bootstrap from 'bootstrap'

// OverlayScrollbars — AdminLTE uses it for the sidebar scroller (optional)
import { OverlayScrollbars } from 'overlayscrollbars'

// AdminLTE plugins (PushMenu, Treeview, CardWidget, FullScreen, DirectChat,
// Layout, accessibility). The data-lte-* API is wired on DOMContentLoaded.
import 'admin-lte'
import ApexCharts from 'apexcharts'
import TomSelect from 'tom-select'

window.ApexCharts = ApexCharts
window.TomSelect = TomSelect
window.bootstrap = bootstrap

/**
 * Initialise an optional plugin only when its global is present.
 * Plugin libraries (ApexCharts, jsVectorMap, FullCalendar, Sortable,
 * Flatpickr, Tom Select, Tabulator, Quill) are loaded lazily via the
 * @pluginScripts directive as global <script> tags, so we feature-detect
 * before touching them.
 */
function whenReady(fn) {
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', fn)
  } else {
    fn()
  }
}

function parseConfig(el, attr) {
  const raw = el.getAttribute(attr)
  if (!raw) return {}
  try {
    return JSON.parse(raw)
  } catch (e) {
    console.warn('AdminLTE: invalid JSON in', attr, e)
    return {}
  }
}

// --- ApexCharts ------------------------------------------------------------
function initCharts() {
  if (typeof window.ApexCharts === 'undefined') return
  document.querySelectorAll('[data-apexchart]').forEach((el) => {
    if (el.dataset.apexchartReady) return
    const config = parseConfig(el, 'data-apexchart-config')
    if (el.dataset.apexchartCurrency === 'BRL') {
      const currency = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' })
      config.yaxis = {
        ...(config.yaxis || {}),
        labels: { ...(config.yaxis?.labels || {}), formatter: value => currency.format(value) },
      }
      config.tooltip = {
        ...(config.tooltip || {}),
        y: { ...(config.tooltip?.y || {}), formatter: value => currency.format(value) },
      }
    }
    try {
      new window.ApexCharts(el, config).render()
      el.dataset.apexchartReady = 'true'
    } catch (e) {
      console.warn('AdminLTE: ApexCharts init failed (check the chart config)', e)
    }
  })
}

// --- jsVectorMap -----------------------------------------------------------
function initVectorMaps() {
  if (typeof window.jsVectorMap === 'undefined') return
  document.querySelectorAll('[data-jsvectormap]').forEach((el) => {
    if (el.dataset.jsvectormapReady || !el.id) return
    const config = parseConfig(el, 'data-jsvectormap-config')
    try {
      new window.jsVectorMap({ selector: '#' + el.id, ...config })
      el.dataset.jsvectormapReady = 'true'
    } catch (e) {
      console.warn('AdminLTE: jsVectorMap init failed (is the map data file loaded?)', e)
    }
  })
}

// --- FullCalendar ----------------------------------------------------------
function initCalendars() {
  if (typeof window.FullCalendar === 'undefined') return
  document.querySelectorAll('[data-fullcalendar]').forEach((el) => {
    if (el.dataset.fullcalendarReady) return
    const config = parseConfig(el, 'data-fullcalendar-config')
    new window.FullCalendar.Calendar(el, config).render()
    el.dataset.fullcalendarReady = 'true'
  })
}

// --- SortableJS (generic lists + kanban boards) ----------------------------
function initSortables() {
  if (typeof window.Sortable === 'undefined') return

  // Generic sortable lists — items in the same group can be dragged between lists.
  document.querySelectorAll('[data-sortable]').forEach((el) => {
    if (el.dataset.sortableReady) return
    const options = parseConfig(el, 'data-sortable-options')
    window.Sortable.create(el, { animation: 150, ...options })
    el.dataset.sortableReady = 'true'
  })

  // Kanban boards — every lane shares one group so cards move between lanes.
  document.querySelectorAll('[data-sortable-kanban]').forEach((board) => {
    board.querySelectorAll('[data-sortable-group]').forEach((lane) => {
      if (lane.dataset.sortableReady) return
      window.Sortable.create(lane, {
        group: 'kanban-' + (board.id || 'board'),
        animation: 150,
      })
      lane.dataset.sortableReady = 'true'
    })
  })
}

// --- Flatpickr (date/time pickers) -----------------------------------------
function initDatePickers() {
  if (typeof window.flatpickr === 'undefined') return
  document.querySelectorAll('[data-flatpickr]').forEach((el) => {
    if (el.dataset.flatpickrReady) return
    window.flatpickr(el, parseConfig(el, 'data-flatpickr-config'))
    el.dataset.flatpickrReady = 'true'
  })
}

// --- Tom Select (searchable selects) ---------------------------------------
function initTomSelects() {
  if (typeof window.TomSelect === 'undefined') return
  document.querySelectorAll('[data-tom-select]').forEach((el) => {
    if (el.dataset.tomSelectReady) return
    try {
      new window.TomSelect(el, parseConfig(el, 'data-tom-select-config'))
      el.dataset.tomSelectReady = 'true'
    } catch (e) {
      console.warn('AdminLTE: Tom Select init failed', e)
    }
  })
}

// --- Tabulator (data tables) ------------------------------------------------
function initDatatables() {
  if (typeof window.Tabulator === 'undefined') return
  document.querySelectorAll('[data-tabulator-config]').forEach((el) => {
    if (el.dataset.tabulatorReady) return
    try {
      new window.Tabulator(el, parseConfig(el, 'data-tabulator-config'))
      el.dataset.tabulatorReady = 'true'
    } catch (e) {
      console.warn('AdminLTE: Tabulator init failed (check the column/data config)', e)
    }
  })
}

// --- Quill (rich text editor) -----------------------------------------------
// The <x-adminlte-editor> component renders an empty div plus a hidden input
// that carries the value to the server. Quill owns the div; we mirror its HTML
// back into the input on every change so a normal form POST submits it.
function initEditors() {
  if (typeof window.Quill === 'undefined') return
  document.querySelectorAll('[data-quill]').forEach((el) => {
    if (el.dataset.quillReady) return

    const target = document.querySelector(el.getAttribute('data-quill-target'))
    let quill
    try {
      quill = new window.Quill(el, parseConfig(el, 'data-quill-config'))
    } catch (e) {
      console.warn('AdminLTE: Quill init failed', e)
      return
    }

    // Seed the editor with the current value (old input or the model's value).
    if (target && target.value) {
      quill.clipboard.dangerouslyPasteHTML(target.value)
    }

    if (target) {
      const sync = () => {
        // An empty Quill still reports '<p><br></p>'; store '' instead so
        // `required` and `nullable` validation behave as expected.
        target.value = quill.getText().trim() === '' ? '' : quill.root.innerHTML
      }
      quill.on('text-change', sync)
      // Catch programmatic changes that don't emit text-change.
      el.closest('form')?.addEventListener('submit', sync)
    }

    el.dataset.quillReady = 'true'
  })
}

// --- Sidebar treeview a11y --------------------------------------------------
// AdminLTE's Treeview toggles .menu-open on the <li>; mirror that state onto
// the toggle link's aria-expanded so screen readers track open/closed submenus.
function initTreeviewA11y() {
  const sidebar = document.querySelector('.app-sidebar')
  if (!sidebar || typeof MutationObserver === 'undefined') return
  const observer = new MutationObserver((mutations) => {
    mutations.forEach((m) => {
      const link = m.target.querySelector(':scope > a.nav-link[aria-expanded]')
      if (link) link.setAttribute('aria-expanded', m.target.classList.contains('menu-open') ? 'true' : 'false')
    })
  })
  sidebar.querySelectorAll('li.nav-item').forEach((li) => {
    if (li.querySelector(':scope > ul.nav-treeview')) {
      observer.observe(li, { attributes: true, attributeFilter: ['class'] })
    }
  })
}

function initFinanceForms() {
  document.querySelectorAll('[data-auto-submit]').forEach((input) => {
    input.addEventListener('change', () => input.form?.requestSubmit())
  })

  const copyModalElement = document.querySelector('#copyBudgetModal')
  if (copyModalElement && window.bootstrap) {
    const modal = window.bootstrap.Modal.getOrCreateInstance(copyModalElement)
    const form = copyModalElement.querySelector('[data-budget-copy-form]')
    const name = copyModalElement.querySelector('[data-budget-copy-name]')
    const month = copyModalElement.querySelector('[name="destination_month"]')
    const source = copyModalElement.querySelector('[name="source_budget_id"]')
    const configureCopy = (button, preserveMonth = false) => {
      form.action = button.dataset.budgetCopyUrl
      name.textContent = button.dataset.budgetName
      source.value = button.dataset.budgetId
      if (!preserveMonth) month.value = button.dataset.nextMonth
    }
    document.querySelectorAll('[data-budget-copy]').forEach((button) => {
      button.addEventListener('click', () => {
        configureCopy(button)
        modal.show()
      })
    })
    if (copyModalElement.dataset.openOnLoad !== undefined) {
      const failedButton = document.querySelector(`[data-budget-copy][data-budget-id="${source.value}"]`)
      if (failedButton) {
        configureCopy(failedButton, true)
        modal.show()
      }
    }
  }

  document.querySelectorAll('[data-recurrence-toggle]').forEach((toggle) => {
    const fields = toggle.closest('form')?.querySelector('[data-recurrence-fields]')
    if (!fields) return
    const syncRecurrence = () => {
      fields.classList.toggle('d-none', !toggle.checked)
      fields.querySelectorAll('[data-recurrence-input]').forEach((input) => {
        input.disabled = !toggle.checked
      })
    }
    toggle.addEventListener('change', syncRecurrence)
    syncRecurrence()
  })

  document.querySelectorAll('form[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (event) => {
      const deletingFutureOccurrences = form.querySelector('[name="recurrence_scope"]')?.value === 'future'
      const message = deletingFutureOccurrences && form.dataset.confirmFuture
        ? form.dataset.confirmFuture
        : form.dataset.confirm
      if (!window.confirm(message)) event.preventDefault()
    })
  })

  document.querySelectorAll('form[data-filter-required]').forEach((form) => {
    const criteria = [...form.querySelectorAll('[data-filter-criterion]')]
    const submit = form.querySelector('[data-filter-submit]')
    const feedback = form.querySelector('[data-filter-feedback]')
    if (!submit) return

    const hasCriteria = () => criteria.some((field) => {
      if (field instanceof HTMLSelectElement && field.multiple) {
        return [...field.selectedOptions].some((option) => option.value !== '')
      }
      return field.value.trim() !== ''
    })
    const syncFilterState = () => {
      const enabled = hasCriteria()
      submit.disabled = !enabled
      feedback?.classList.toggle('d-none', enabled)
    }

    form.addEventListener('input', syncFilterState)
    form.addEventListener('change', syncFilterState)
    syncFilterState()
  })

  const type = document.querySelector('#type')
  const category = document.querySelector('#category_id')
  if (type && category && category.querySelector('[data-type]')) {
    const syncCategories = () => {
      category.querySelectorAll('option[data-type]').forEach((option) => {
        option.hidden = option.dataset.type !== type.value
        option.disabled = option.hidden
      })
      if (category.selectedOptions[0]?.disabled) category.value = ''
    }
    type.addEventListener('change', syncCategories)
    syncCategories()
  }

  document.querySelectorAll('[data-transfer-form]').forEach((form) => {
    const source = form.querySelector('#source_account_id')
    const destination = form.querySelector('#destination_account_id')
    if (!source || !destination) return
    const syncAccounts = () => {
      destination.querySelectorAll('option').forEach((option) => {
        option.disabled = option.value !== '' && option.value === source.value && !option.selected
      })
    }
    source.addEventListener('change', syncAccounts)
    syncAccounts()
  })

  document.querySelectorAll('[data-import-row]').forEach((row) => {
    if (row.dataset.locked === 'true') return
    const ignore = row.querySelector('[data-import-ignore]')
    const transfer = row.querySelector('[data-import-transfer]')
    const category = row.querySelector('[data-import-category]')
    const destination = row.querySelector('[data-import-destination]')
    if (!ignore || !category || !destination) return

    const syncImportRow = () => {
      const isIgnored = ignore.checked
      const isTransfer = Boolean(transfer?.checked) && !isIgnored
      if (transfer) transfer.disabled = isIgnored
      category.disabled = isIgnored || isTransfer
      category.required = !isIgnored && !isTransfer
      destination.disabled = !isTransfer
      destination.required = isTransfer
      row.classList.toggle('import-row-muted', isIgnored)
    }

    ignore.addEventListener('change', syncImportRow)
    transfer?.addEventListener('change', syncImportRow)
    syncImportRow()
  })
}

whenReady(() => {
  // Wire OverlayScrollbars to the sidebar (matches the AdminLTE demo behaviour)
  const sidebar = document.querySelector('.sidebar-wrapper')
  if (sidebar && window.innerWidth > 992) {
    OverlayScrollbars(sidebar, {
      scrollbars: { theme: 'os-theme-light', autoHide: 'leave', clickScroll: true },
    })
  }

  initCharts()
  initVectorMaps()
  initCalendars()
  initSortables()
  initDatePickers()
  initTomSelects()
  initDatatables()
  initEditors()
  initTreeviewA11y()
  initFinanceForms()
})
