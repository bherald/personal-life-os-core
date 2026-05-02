<template>
  <div class="knowledge-notebook-tree text-sm">
    <div v-if="loading" class="text-ops-text-muted text-xs py-2">Loading notebooks...</div>
    <div v-else-if="tree.length === 0" class="text-ops-text-muted text-xs py-2">No notebooks</div>
    <template v-else>
      <NotebookNode
        v-for="node in tree"
        :key="node.id"
        :node="node"
        :depth="0"
        :selected-id="selectedNotebook?.id"
        @select="selectNotebook"
      />
    </template>
  </div>
</template>

<script setup>
import { ref, onMounted, defineComponent, h } from 'vue'
import axios from 'axios'

const props = defineProps({
  selectedNotebook: { type: Object, default: null }
})

const emit = defineEmits(['select'])

const tree = ref([])
const loading = ref(false)

// Recursive tree node component
const NotebookNode = defineComponent({
  name: 'NotebookNode',
  props: {
    node: { type: Object, required: true },
    depth: { type: Number, default: 0 },
    selectedId: { type: String, default: null }
  },
  emits: ['select'],
  setup(props, { emit }) {
    const expanded = ref(false) // All collapsed on load

    function toggle() {
      if (props.node.children?.length > 0) {
        expanded.value = !expanded.value
      }
    }

    function select(e) {
      e.stopPropagation()
      emit('select', { id: props.node.id, title: props.node.title, note_count: props.node.totalCount || props.node.noteCount })
    }

    return () => {
      const hasChildren = props.node.children?.length > 0
      const isSelected = props.selectedId === props.node.id
      const indent = `${props.depth * 12}px`

      const row = h('div', {
        class: [
          'flex items-center gap-1 py-1 px-1 rounded cursor-pointer transition-colors group',
          isSelected ? 'bg-ops-sky/20 text-ops-sky' : 'text-ops-text-muted hover:bg-ops-plum/30 hover:text-ops-peach'
        ],
        style: { paddingLeft: indent },
        onClick: select
      }, [
        // Expand/collapse toggle
        hasChildren ? h('button', {
          class: 'w-4 h-4 flex items-center justify-center flex-shrink-0 text-ops-text-muted hover:text-ops-peach',
          onClick: (e) => { e.stopPropagation(); toggle() }
        }, [
          h('svg', {
            class: ['w-3 h-3 transition-transform', expanded.value ? 'rotate-90' : ''],
            fill: 'none', stroke: 'currentColor', viewBox: '0 0 24 24'
          }, [
            h('path', { 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'stroke-width': '2', d: 'M9 5l7 7-7 7' })
          ])
        ]) : h('span', { class: 'w-4 flex-shrink-0' }),
        // Notebook icon (book)
        h('svg', {
          class: 'w-3.5 h-3.5 flex-shrink-0',
          fill: 'none', stroke: 'currentColor', viewBox: '0 0 24 24'
        }, [
          h('path', {
            'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'stroke-width': '2',
            d: 'M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253'
          })
        ]),
        // Name
        h('span', { class: 'truncate text-xs' }, props.node.title),
        // Count
        h('span', { class: 'text-[10px] opacity-40 ml-auto flex-shrink-0' }, props.node.totalCount || props.node.noteCount || 0)
      ])

      const children = expanded.value && hasChildren
        ? props.node.children.map(child =>
            h(NotebookNode, {
              node: child,
              depth: props.depth + 1,
              selectedId: props.selectedId,
              onSelect: (nb) => emit('select', nb)
            })
          )
        : []

      return h('div', [row, ...children])
    }
  }
})

function buildTree(notebooks) {
  const root = []
  const nodeMap = {}

  // Index all notebooks
  for (const nb of notebooks) {
    nodeMap[nb.id] = {
      id: nb.id,
      title: nb.title,
      parentId: nb.parent_id,
      noteCount: nb.note_count || 0,
      totalCount: nb.note_count || 0,
      children: []
    }
  }

  // Build hierarchy
  for (const nb of notebooks) {
    const node = nodeMap[nb.id]
    if (nb.parent_id && nodeMap[nb.parent_id]) {
      nodeMap[nb.parent_id].children.push(node)
    } else {
      root.push(node)
    }
  }

  // Bubble up counts
  function sumCounts(node) {
    let total = node.noteCount
    for (const child of node.children) {
      total += sumCounts(child)
    }
    node.totalCount = total
    return total
  }
  root.forEach(sumCounts)

  // Sort: children alphabetically, root by total count desc
  function sortChildren(node) {
    node.children.sort((a, b) => a.title.localeCompare(b.title))
    node.children.forEach(sortChildren)
  }
  root.sort((a, b) => b.totalCount - a.totalCount)
  root.forEach(sortChildren)

  // Filter out empty notebooks (0 total notes)
  function filterEmpty(nodes) {
    return nodes.filter(n => {
      n.children = filterEmpty(n.children)
      return n.totalCount > 0
    })
  }

  return filterEmpty(root)
}

async function loadNotebooks() {
  loading.value = true
  try {
    const { data } = await axios.get('/api/joplin/notebooks')
    if (data.success && data.data) {
      tree.value = buildTree(data.data)
    }
  } catch (err) {
    console.error('Failed to load notebooks:', err)
  } finally {
    loading.value = false
  }
}

function selectNotebook(notebook) {
  emit('select', notebook)
}

onMounted(loadNotebooks)
</script>
