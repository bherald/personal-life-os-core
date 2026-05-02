<template>
  <div class="knowledge-folder-tree text-sm">
    <div v-if="loading" class="text-ops-text-muted text-xs py-2">Loading folders...</div>
    <div v-else-if="tree.length === 0" class="text-ops-text-muted text-xs py-2">No folders</div>
    <template v-else>
      <FolderNode
        v-for="node in tree"
        :key="node.path"
        :node="node"
        :depth="0"
        :selected-path="selectedFolder?.path"
        @select="selectFolder"
      />
    </template>
  </div>
</template>

<script setup>
import { ref, onMounted, watch, defineComponent, h } from 'vue'
import axios from 'axios'

const props = defineProps({
  selectedFolder: { type: Object, default: null }
})

const emit = defineEmits(['select', 'close'])

const tree = ref([])
const loading = ref(false)

// Recursive tree node component
const FolderNode = defineComponent({
  name: 'FolderNode',
  props: {
    node: { type: Object, required: true },
    depth: { type: Number, default: 0 },
    selectedPath: { type: String, default: null }
  },
  emits: ['select'],
  setup(props, { emit }) {
    const expanded = ref(false)

    // Auto-expand when this node is an ancestor of the selected path
    watch(() => props.selectedPath, (path) => {
      if (path && props.node.children?.length > 0) {
        if (path.startsWith(props.node.path + '/')) {
          expanded.value = true
        }
      }
    }, { immediate: true })

    function toggle() {
      if (props.node.children?.length > 0) {
        expanded.value = !expanded.value
      }
    }

    function select(e) {
      e.stopPropagation()
      emit('select', { path: props.node.path, name: props.node.name, file_count: props.node.count })
    }

    return () => {
      const hasChildren = props.node.children?.length > 0
      const isSelected = props.selectedPath === props.node.path
      const indent = `${props.depth * 12}px`

      const row = h('div', {
        class: [
          'flex items-center gap-1 py-1 px-1 rounded cursor-pointer transition-colors group',
          isSelected ? 'bg-ops-gold/20 text-ops-gold' : 'text-ops-text-muted hover:bg-ops-plum/30 hover:text-ops-peach'
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
        // Folder icon
        h('svg', {
          class: 'w-3.5 h-3.5 flex-shrink-0',
          fill: 'none', stroke: 'currentColor', viewBox: '0 0 24 24'
        }, [
          h('path', {
            'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'stroke-width': '2',
            d: expanded.value && hasChildren
              ? 'M5 19a2 2 0 01-2-2V7a2 2 0 012-2h4l2 2h6a2 2 0 012 2v1M5 19h14a2 2 0 002-2v-5a2 2 0 00-2-2H9a2 2 0 00-2 2v5a2 2 0 01-2 2z'
              : 'M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z'
          })
        ]),
        // Name
        h('span', { class: 'truncate text-xs' }, props.node.name),
        // Count
        h('span', { class: 'text-[10px] opacity-40 ml-auto flex-shrink-0' }, props.node.totalCount || props.node.count)
      ])

      const children = expanded.value && hasChildren
        ? props.node.children.map(child =>
            h(FolderNode, {
              node: child,
              depth: props.depth + 1,
              selectedPath: props.selectedPath,
              onSelect: (f) => emit('select', f)
            })
          )
        : []

      return h('div', [row, ...children])
    }
  }
})

function buildTree(flatFolders) {
  const root = []
  const nodeMap = {}
  const sorted = [...flatFolders].sort((a, b) => a.path.localeCompare(b.path))

  for (const folder of sorted) {
    const parts = folder.path.split('/')
    const name = parts[parts.length - 1]

    const node = {
      path: folder.path,
      name: name,
      count: folder.file_count,
      totalCount: folder.file_count,
      children: []
    }

    nodeMap[folder.path] = node

    const parentPath = parts.slice(0, -1).join('/')
    if (parentPath && nodeMap[parentPath]) {
      nodeMap[parentPath].children.push(node)
      let p = parentPath
      while (p && nodeMap[p]) {
        nodeMap[p].totalCount = (nodeMap[p].totalCount || nodeMap[p].count) + folder.file_count
        const pp = p.split('/').slice(0, -1).join('/')
        p = pp
      }
    } else {
      root.push(node)
    }
  }

  return root
}

async function loadFolders() {
  loading.value = true
  try {
    const { data } = await axios.get('/api/media/folders')
    if (data.success && data.data) {
      const rows = data.data.filter(f => f.path)
      const rootPath = rows.find(f => !f.path.includes('/'))?.path || rows[0]?.path.split('/')[0]
      const cleaned = rows
        .filter(f => !rootPath || f.path === rootPath || f.path.startsWith(`${rootPath}/`))
        .filter(f => f.path)
        .map(f => ({
          ...f,
          path: f.path
        }))
      tree.value = buildTree(cleaned)
      if (tree.value.length === 1 && tree.value[0].children?.length) {
        tree.value = tree.value[0].children
      }
    }
  } catch (err) {
    console.error('Failed to load folders:', err)
  } finally {
    loading.value = false
  }
}

function selectFolder(folder) {
  emit('select', folder)
}

onMounted(loadFolders)
</script>
