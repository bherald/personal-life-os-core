# GenealogyView Component Refactoring Roadmap

## Overview
This document outlines the incremental refactoring plan for splitting the monolithic `GenealogyView.vue` (10,967 lines) into smaller, maintainable components.

**Status**: Phase 1 Complete - Infrastructure Created

## Current Structure

```
resources/js/src/
├── composables/
│   ├── useTimezone.js        (existing)
│   └── useGenealogyTree.js   (NEW - tree rendering logic)
├── components/
│   └── genealogy/
│       ├── tabs/             (empty - for future extraction)
│       ├── modals/           (empty - for future extraction)
│       └── shared/           (empty - for shared components)
└── views/
    └── GenealogyView.vue     (10,967 lines - to be refactored)
```

## Refactoring Phases

### Phase 1: Infrastructure (COMPLETE)
- [x] Create directory structure for genealogy components
- [x] Create `useGenealogyTree.js` composable for tree visualization
- [x] Document refactoring plan

### Phase 2: Tab Extraction (PLANNED)
Extract each tab panel into a separate component:

| Component | Lines | Complexity | Dependencies |
|-----------|-------|------------|--------------|
| TreeTab.vue | ~110 | High | d3, topola, useGenealogyTree |
| SearchTab.vue | ~45 | Low | API calls |
| SurnamesTab.vue | ~35 | Low | State only |
| SourcesTab.vue | ~220 | Medium | CRUD, citations |
| RepositoriesTab.vue | ~105 | Medium | CRUD |
| ReportsTab.vue | ~120 | Medium | Multiple reports |
| MediaTab.vue | ~185 | High | Face tagging, uploads |
| ToolsTab.vue | ~590 | Very High | Multiple tool sections |
| RecentTab.vue | ~20 | Low | State only |

### Phase 3: Modal Extraction (PLANNED)
30+ modals to be extracted to `/components/genealogy/modals/`:

**High Priority**:
- PersonEditModal.vue
- FamilyEditModal.vue
- MediaDetailModal.vue
- FaceTaggingModal.vue

**Medium Priority**:
- SourceModal.vue
- RepositoryModal.vue
- CitationModal.vue
- EventModal.vue

**Lower Priority**:
- All report modals (Statistics, Timeline, Pedigree, etc.)
- Settings modals (Privacy, Collaborators, etc.)

### Phase 4: Shared Components (PLANNED)
Extract reusable components to `/components/genealogy/shared/`:

- PersonCard.vue (person display card)
- MediaThumbnail.vue (media grid item)
- PaginationControls.vue
- SearchInput.vue
- ConfirmDialog.vue

### Phase 5: State Management (FUTURE)
Consider Pinia store for genealogy state if needed after component extraction.

## Extraction Guidelines

### When Extracting Components:
1. Use `defineProps` for data passed from parent
2. Use `defineEmits` for events back to parent
3. Import composables for shared logic (e.g., `useGenealogyTree`)
4. Preserve v-model bindings where appropriate

### API Calls:
- Keep API calls in parent component initially
- Pass data via props, emit events for mutations
- Consider dedicated API service file later

### Testing After Each Extraction:
1. Verify UI renders correctly
2. Test user interactions (clicks, form submissions)
3. Verify data flows correctly
4. Check console for errors
5. Test on both light and dark themes

## Priority Order for Extraction

1. **Low-hanging fruit first**: SearchTab, SurnamesTab, RecentTab
2. **Independent modals**: ConfirmDialog, simple CRUD modals
3. **Complex tabs with modals**: Sources + SourceModal, Repositories + RepositoryModal
4. **High-complexity**: MediaTab + MediaDetailModal + FaceTaggingModal
5. **Very complex**: ToolsTab (consider further splitting)
6. **Tree visualization**: TreeTab (after composable is proven stable)

## Notes

- The `useGenealogyTree` composable is ready for use but not yet integrated into GenealogyView.vue
- Integration should happen during TreeTab extraction
- All changes should be incremental with QA testing between each step

## Related Files

- `/docs/genealogy-module-review.md` - Complete module review with priorities
- `/resources/js/src/composables/useGenealogyTree.js` - Tree rendering composable
