// Ported from Maxforms (de1c8eeb9). Each header rendered by ResizableColumns::apply()
// runs one `resizableColumn` instance, which owns its drag handle.

// Fixed columns carry an exact width. Elastic columns carry a floor and share the leftover
// space under `table-layout: fixed`, so the table never leaves a gap on the right.
const SELECTION_CELL_WIDTH = 52
// Only fits an ActionGroup trigger: bare row actions would overflow into the next column.
const ACTIONS_CELL_WIDTH = 64

let dragGuideline = null
let dragBadge = null
let resizeMeasureCanvas = null

// The guideline lives inside the scroll wrapper, so a horizontal scroll mid-drag keeps it on
// the column edge. The badge is fixed and follows the cursor.
function ensureDragGuideline(wrapper) {
    if (!dragGuideline) {
        dragGuideline = document.createElement('div')
        dragGuideline.className = 'column-resize-guideline'
    }
    if (dragGuideline.parentElement !== wrapper) {
        wrapper.appendChild(dragGuideline)
    }
    return dragGuideline
}

function ensureDragBadge() {
    if (!dragBadge) {
        dragBadge = document.createElement('div')
        dragBadge.className = 'column-resize-badge'
        document.body.appendChild(dragBadge)
    }
    return dragBadge
}

function showDragOverlay(wrapper, contentX, height, width, cursorX, cursorY) {
    const guideline = ensureDragGuideline(wrapper)
    Object.assign(guideline.style, { display: 'block', left: `${contentX}px`, height: `${height}px` })
    const badge = ensureDragBadge()
    Object.assign(badge.style, { display: 'block', left: `${cursorX}px`, top: `${cursorY}px` })
    badge.textContent = `${width}px`
}

function moveDragOverlay(contentX, width, cursorX, cursorY) {
    if (dragGuideline) {
        dragGuideline.style.left = `${contentX}px`
    }
    if (dragBadge) {
        dragBadge.style.left = `${cursorX}px`
        dragBadge.style.top = `${cursorY}px`
        dragBadge.textContent = `${width}px`
    }
}

function hideDragOverlay() {
    if (dragGuideline) dragGuideline.style.display = 'none'
    if (dragBadge) dragBadge.style.display = 'none'
}

// `.fi-ta-content-ctn` scrolls horizontally, which defeats `position: sticky` on the header,
// and a scroll listener lags the compositor. A scroll-driven animation pins it instead.
const stickyHeadersSupported =
    typeof CSS !== 'undefined' && CSS.supports('animation-timeline', 'scroll()')

let stickyHeadersWired = false
let stickyHeadersStyleElement = null

function stickyHeaderOffset() {
    const topbar = document.querySelector('.fi-topbar-ctn')
    if (!topbar || !['sticky', 'fixed'].includes(getComputedStyle(topbar).position)) return 0

    return topbar.offsetHeight
}

function stickyHeaderStyleElement() {
    if (!stickyHeadersStyleElement) {
        stickyHeadersStyleElement = document.createElement('style')
        stickyHeadersStyleElement.id = 'resizable-sticky-headers'
        document.head.appendChild(stickyHeadersStyleElement)
    }

    return stickyHeadersStyleElement
}

// Keyframes cannot interpolate CSS variables, so each table's pixel range is written out.
function measureStickyHeaders() {
    if (!stickyHeadersSupported) return

    const offset = stickyHeaderOffset()
    const scrollY = window.scrollY
    let css = ''

    document.querySelectorAll('table.fi-ta-resizable-table > thead').forEach((thead, index) => {
        const table = thead.parentElement
        const maxShift = Math.max(0, table.offsetHeight - thead.offsetHeight)

        if (maxShift === 0) {
            thead.removeAttribute('data-sticky-header')

            return
        }

        const tableTop = table.getBoundingClientRect().top + scrollY
        const start = Math.max(0, tableTop - offset)

        thead.dataset.stickyHeader = String(index)
        css +=
            `@keyframes pin-header-${index}{from{transform:translateY(0)}to{transform:translateY(${maxShift}px)}}` +
            `[data-sticky-header="${index}"]{` +
            'will-change:transform;' +
            `animation:pin-header-${index} linear both;` +
            'animation-timeline:scroll(root block);' +
            `animation-range:${start}px ${start + maxShift}px}`
    })

    stickyHeaderStyleElement().textContent = css
}

let stickyRecalcPending = false

function scheduleStickyRecalc() {
    if (!stickyHeadersSupported) return
    if (stickyRecalcPending) return

    stickyRecalcPending = true
    requestAnimationFrame(() => {
        stickyRecalcPending = false
        measureStickyHeaders()
    })
}

function wireStickyHeaders() {
    if (stickyHeadersWired) return
    stickyHeadersWired = true

    if (!stickyHeadersSupported) return

    window.addEventListener('resize', scheduleStickyRecalc, { passive: true })
    document.addEventListener('livewire:navigated', scheduleStickyRecalc)

    measureStickyHeaders()
}

function computeMinTableWidth(table) {
    let total = 0
    table.querySelectorAll('thead th').forEach((th) => {
        const saved = th.dataset.resizableWidth
        if (saved) { total += parseInt(saved, 10); return }
        if (th.classList.contains('fi-ta-selection-cell')) { total += SELECTION_CELL_WIDTH; return }
        if (th.classList.contains('fi-ta-actions-header-cell')) { total += ACTIONS_CELL_WIDTH; return }
        total += Math.round(th.offsetWidth)
    })
    return total
}

function applyTableSizing(table) {
    table.style.tableLayout = 'fixed'

    const wrapper = table.closest('.fi-ta-content-ctn')
    const wrapperWidth = wrapper?.clientWidth ?? 0
    const sumOfFloors = computeMinTableWidth(table)

    // When the columns overflow, elastic columns get exact widths: the browser would
    // otherwise average a dragged width away when it shares the space out.
    if (sumOfFloors > wrapperWidth) {
        table.style.width = `${sumOfFloors}px`
        table.style.minWidth = ''
        applyElasticWidths(table, true)
    } else {
        table.style.width = '100%'
        table.style.minWidth = ''
        applyElasticWidths(table, false)
    }
}

function applyElasticWidths(table, pinned) {
    table.querySelectorAll('thead th[data-resizable-type="elastic"]').forEach((th) => {
        const saved = th.dataset.resizableWidth
        const columnId = th.dataset.resizableColumnId
        if (!saved || !columnId) return

        const header = pinned
            ? `min-width: ${saved}px; width: ${saved}px; max-width: ${saved}px`
            : `min-width: ${saved}px`
        const cell = `${header}; overflow: hidden`

        th.style.cssText = header
        table.querySelectorAll(`[data-resizable-column-id="${CSS.escape(columnId)}"]`).forEach((node) => {
            if (node === th) return
            node.style.cssText = cell
        })
    })
}

const widthRecalcPending = new WeakSet()

function scheduleSizingUpdate(table) {
    if (widthRecalcPending.has(table)) return
    widthRecalcPending.add(table)
    requestAnimationFrame(() => {
        widthRecalcPending.delete(table)
        if (table.isConnected) {
            applyTableSizing(table)
            scheduleStickyRecalc()
        }
    })
}

// Observing the wrappers catches sidebar collapses and window resizes without watching rows.
const wrapperResizeObserver = typeof ResizeObserver === 'undefined'
    ? null
    : new ResizeObserver((entries) => {
        entries.forEach((entry) => {
            const table = entry.target.querySelector('table.fi-ta-resizable-table')
            if (table) scheduleSizingUpdate(table)
        })
    })

// A Livewire morph resets the wrapper's horizontal scroll to 0. The position is kept off the
// DOM, which the morph would strip, so a sort or search does not jump back to the left edge.
const wrapperScrollLeft = new WeakMap()
const scrollTrackedWrappers = new WeakSet()

function trackWrapperScroll(wrapper) {
    if (scrollTrackedWrappers.has(wrapper)) return
    scrollTrackedWrappers.add(wrapper)
    wrapper.addEventListener('scroll', () => {
        wrapperScrollLeft.set(wrapper, wrapper.scrollLeft)
    }, { passive: true })
}

function lockTable(table) {
    if (table.dataset.resizableLayoutLocked === '1') return

    table.classList.add('fi-ta-resizable-table')
    applyTableSizing(table)

    const wrapper = table.closest('.fi-ta-content-ctn')
    if (wrapper) {
        trackWrapperScroll(wrapper)
        if (getComputedStyle(wrapper).position === 'static') {
            wrapper.style.position = 'relative'
        }
        if (wrapperResizeObserver && !wrapper.dataset.resizableWrapperObserved) {
            wrapperResizeObserver.observe(wrapper)
            wrapper.dataset.resizableWrapperObserved = '1'
        }
    }

    table.dataset.resizableLayoutLocked = '1'
    scheduleStickyRecalc()
}

let morphHookRegistered = false

// A morph keeps the server-rendered widths but strips the table class, the lock flag, the
// label wrappers and the handles, so every resizable table is rebuilt from its headers.
function rebuildResizableTablesAfterMorph() {
    const tables = new Set()
    document.querySelectorAll('thead th[x-data^="resizableColumn"]').forEach((th) => {
        const table = th.closest('table.fi-ta-table')
        if (table) tables.add(table)
    })

    tables.forEach((table) => {
        delete table.dataset.resizableLayoutLocked
        lockTable(table)
        table.querySelectorAll('thead th[x-data^="resizableColumn"]').forEach(reapplyHeader)

        const wrapper = table.closest('.fi-ta-content-ctn')
        const savedScrollLeft = wrapper && wrapperScrollLeft.get(wrapper)
        if (wrapper && savedScrollLeft && wrapper.scrollLeft !== savedScrollLeft) {
            wrapper.scrollLeft = savedScrollLeft
        }
    })

    scheduleStickyRecalc()
}

// `morphed` fires once per component after the morph settles. `morph.updated` fires per node
// mid-morph, and the rest of the same pass strips what it adds.
function ensureGlobalMorphHook() {
    if (morphHookRegistered) return
    if (!window.Livewire?.hook) return

    window.Livewire.hook('morphed', rebuildResizableTablesAfterMorph)

    morphHookRegistered = true
}

function createHandleBar() {
    const handle = document.createElement('button')
    handle.type = 'button'
    handle.classList.add('column-resize-handle-bar')
    handle.setAttribute('aria-label', window.filamentData?.resizeColumnLabel ?? '')
    handle.title = window.filamentData?.resizeColumnHint ?? ''

    return handle
}

function reapplyHeader(th) {
    if (!th.querySelector(':scope > .fi-ta-resizable-label')) {
        const wrapper = document.createElement('span')
        wrapper.className = 'fi-ta-resizable-label'
        Array.from(th.childNodes).forEach((node) => {
            if (node.nodeType === 1 && node.classList?.contains('column-resize-handle-bar')) return
            wrapper.appendChild(node)
        })
        th.insertBefore(wrapper, th.firstChild)
    }
    if (!th.querySelector('.column-resize-handle-bar')) {
        const handle = createHandleBar()
        th.appendChild(handle)
        const data = window.Alpine?.$data?.(th)
        if (data && typeof data.startResize === 'function') {
            handle.addEventListener('mousedown', (e) => data.startResize(e))
            if (typeof data.autoFit === 'function') {
                handle.addEventListener('dblclick', (e) => {
                    e.preventDefault()
                    e.stopPropagation()
                    data.autoFit()
                })
            }
        }
    }
}

function registerResizableColumn() {
    ensureGlobalMorphHook()
    wireStickyHeaders()
    window.Alpine.data('resizableColumn', (columnName, columnId, min = 40, max = 1000) => ({
        minWidth: min,
        maxWidth: max,
        debounceMs: 400,
        tableSelector: '.fi-ta-table',
        tableWrapperSelector: '.fi-ta-content-ctn',
        // Relationship columns keep the dot in Filament's cell class (`user.name`).
        cellSelector: `.fi-ta-cell-${CSS.escape(columnId)}`,
        column: null,
        table: null,
        tableWrapper: null,
        handleBar: null,
        saveTimer: null,

        init() {
            this.column = this.$el
            this.table = this.$el.closest(this.tableSelector)
            this.tableWrapper = this.$el.closest(this.tableWrapperSelector)
            if (!this.column || !this.table || !this.tableWrapper) return

            this.column.classList.add('fi-ta-resizable-header')

            lockTable(this.table)
            this.wrapHeaderLabel()
            this.createHandle()
        },

        wrapHeaderLabel() {
            if (this.column.querySelector(':scope > .fi-ta-resizable-label')) return
            const wrapper = document.createElement('span')
            wrapper.className = 'fi-ta-resizable-label'
            while (this.column.firstChild) {
                wrapper.appendChild(this.column.firstChild)
            }
            this.column.appendChild(wrapper)
        },

        createHandle() {
            const existing = this.column.querySelector('.column-resize-handle-bar')
            if (existing) existing.remove()

            this.handleBar = createHandleBar()
            this.column.appendChild(this.handleBar)
            this.handleBar.addEventListener('mousedown', (e) => this.startResize(e))
            this.handleBar.addEventListener('dblclick', (e) => {
                e.preventDefault()
                e.stopPropagation()
                this.autoFit()
            })
        },

        startResize(event) {
            event.preventDefault()
            event.stopPropagation()
            this.handleBar.classList.add('active')
            document.body.classList.add('resizing-column')

            const startX = event.pageX
            const startColumnWidth = this.column.offsetWidth
            // In the wrapper's content coordinates the column's left edge does not move with
            // scrollLeft, so the guideline sits at contentLeftEdge + currentWidth.
            const wrapperRect = this.tableWrapper.getBoundingClientRect()
            const contentLeftEdge =
                this.column.getBoundingClientRect().left - wrapperRect.left + this.tableWrapper.scrollLeft
            const guidelineHeight = this.table.offsetHeight
            let currentWidth = startColumnWidth
            // Shown on the first move only, so a double-click never flashes the overlay.
            let overlayShown = false

            const onMove = (moveEvent) => {
                if (moveEvent.pageX === startX) return
                currentWidth = Math.round(
                    Math.min(
                        this.maxWidth,
                        Math.max(this.minWidth, startColumnWidth + (moveEvent.pageX - startX)),
                    ),
                )
                this.applyWidth(currentWidth)

                const contentX = contentLeftEdge + currentWidth
                if (overlayShown) {
                    moveDragOverlay(contentX, currentWidth, moveEvent.clientX, moveEvent.clientY)
                } else {
                    showDragOverlay(this.tableWrapper, contentX, guidelineHeight, currentWidth, moveEvent.clientX, moveEvent.clientY)
                    overlayShown = true
                }
            }

            const onUp = () => {
                this.handleBar.classList.remove('active')
                document.body.classList.remove('resizing-column')
                hideDragOverlay()
                if (currentWidth !== startColumnWidth) {
                    this.scheduleSave(currentWidth)
                }
                document.removeEventListener('mousemove', onMove)
                document.removeEventListener('mouseup', onUp)
            }

            document.addEventListener('mousemove', onMove)
            document.addEventListener('mouseup', onUp)
        },

        applyWidth(width) {
            const isElastic = this.column.dataset.resizableType === 'elastic'
            const headerStyle = isElastic
                ? `min-width: ${width}px`
                : `min-width: ${width}px; width: ${width}px; max-width: ${width}px`
            const cellStyle = `${headerStyle}; overflow: hidden`

            this.column.style.cssText = headerStyle
            this.column.dataset.resizableWidth = String(width)
            this.table.querySelectorAll(this.cellSelector).forEach((cell) => {
                cell.style.cssText = cellStyle
                cell.dataset.resizableWidth = String(width)
            })
            scheduleSizingUpdate(this.table)
        },

        scheduleSave(width) {
            clearTimeout(this.saveTimer)
            this.saveTimer = setTimeout(() => {
                if (this.$wire && typeof this.$wire.updateResizableColumnWidth === 'function') {
                    this.$wire.updateResizableColumnWidth(columnName, String(width))
                }
            }, this.debounceMs)
        },

        // `table-layout: fixed` never sizes a column to its content, so the widest visible
        // text is measured on a canvas instead.
        autoFit() {
            const width = this.measureContentWidth()
            if (!width) return
            this.applyWidth(width)
            this.scheduleSave(width)
        },

        measureContentWidth() {
            resizeMeasureCanvas ??= document.createElement('canvas')
            const ctx = resizeMeasureCanvas.getContext('2d')

            const sampleCell = this.table.querySelector(this.cellSelector)
            let padding = 24
            if (sampleCell) {
                const cellStyle = getComputedStyle(sampleCell)
                padding = parseFloat(cellStyle.paddingLeft) + parseFloat(cellStyle.paddingRight)
            }

            const measure = (host, reserve = 0) => {
                const label = host.querySelector('.fi-ta-resizable-label') ?? host
                const text = label.textContent.trim()
                if (!text) return 0
                const style = getComputedStyle(label)
                ctx.font = `${style.fontWeight} ${style.fontSize} ${style.fontFamily}`
                return ctx.measureText(text).width + reserve
            }

            // Leaves room for the header's sort icon and handle.
            let widest = measure(this.column, 28)
            this.table.querySelectorAll(this.cellSelector).forEach((cell) => {
                widest = Math.max(widest, measure(cell))
            })

            return Math.round(Math.min(this.maxWidth, Math.max(this.minWidth, widest + padding + 2)))
        },
    }))
}

if (window.Alpine && window.Alpine.version) {
    registerResizableColumn()
} else {
    document.addEventListener('alpine:init', registerResizableColumn)
}
