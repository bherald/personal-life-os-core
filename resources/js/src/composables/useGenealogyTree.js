/**
 * useGenealogyTree - Vue 3 Composable for Family Tree Rendering
 *
 * Encapsulates tree visualization logic using Topola and D3.js
 * Extracted from GenealogyView.vue to support component decomposition
 *
 * @see /docs/genealogy-module-review.md Priority 1.2
 */

import { ref, computed, nextTick, watch } from 'vue';
import * as d3 from 'd3';
import { HourglassChart, AncestorChart, DescendantChart, KinshipChart, RelativesChart, FancyChart, DetailedRenderer, SimpleRenderer, CircleRenderer } from 'topola';

/**
 * Tree visualization composable
 * @param {Object} options - Configuration options
 * @param {Ref<number|null>} options.selectedTreeId - Currently selected tree ID
 * @param {Ref<Object|null>} options.treeData - Tree data from API
 * @param {Function} options.onPersonSelect - Callback when person is selected in tree
 */
export function useGenealogyTree(options = {}) {
  const { selectedTreeId, treeData, onPersonSelect } = options;

  // State
  const homePersonId = ref(null);
  const focusPersonId = ref(null); // N137: temporary focus without changing home
  const expandedFamily = ref(false); // N137: show expanded siblings/children
  const treeViewMode = ref('hourglass');
  const treeLoaded = ref(false);
  const treeSvg = ref(null);
  const chartInstance = ref(null);
  const currentZoom = ref(null);
  const hoveredPerson = ref(null);
  const hoverPosition = ref({ x: 0, y: 0 });

  // Tree view modes
  const treeModes = [
    { id: 'hourglass', label: 'Hourglass' },
    { id: 'ancestors', label: 'Ancestors' },
    { id: 'descendants', label: 'Descendants' },
    { id: 'kinship', label: 'Kinship' },
    { id: 'relatives', label: 'Relatives' },
    { id: 'fancy', label: 'Fancy' },
  ];

  // Computed
  const hoverPanelStyle = computed(() => ({
    left: `${hoverPosition.value.x + 15}px`,
    top: `${hoverPosition.value.y + 15}px`,
  }));

  /**
   * Create Topola data provider from tree data
   */
  const createDataProvider = () => {
    const data = treeData?.value;
    if (!data) return null;

    const persons = data.persons || {};
    const families = data.families || {};

    return {
      getIndi: (id) => {
        const person = persons[id];
        if (!person) return null;

        return {
          // Core Indi interface
          getId: () => String(id),
          getFamiliesAsSpouse: () => (person.families_as_spouse || person.spouse_of_families || []).map(fid => String(fid)),
          getFamilyAsChild: () => person.family_as_child || person.child_of_family ? String(person.family_as_child || person.child_of_family) : null,
          // IndiDetails interface
          getFirstName: () => person.given_name || '',
          getLastName: () => person.surname || '',
          getMaidenName: () => null,
          getNumberOfChildren: () => null,
          getNumberOfMarriages: () => (person.families_as_spouse || person.spouse_of_families || []).length || null,
          getBirthDate: () => person.birth_date ? { date: { year: parseInt(person.birth_date.split('-')[0]) || null } } : null,
          getBirthPlace: () => person.birth_place || null,
          getDeathDate: () => person.death_date ? { date: { year: parseInt(person.death_date.split('-')[0]) || null } } : null,
          getDeathPlace: () => person.death_place || null,
          isConfirmedDeath: () => !!person.death_date,
          getSex: () => person.sex || 'U',
          getImageUrl: () => person.photo || null,
          getImages: () => person.photo ? [{ url: person.photo }] : null,
          getNotes: () => null,
          getEvents: () => null,
          showId: () => false,
          showSex: () => true,
        };
      },
      getFam: (id) => {
        const family = families[id];
        if (!family) return null;

        return {
          getId: () => String(id),
          getFather: () => family.husband_id ? String(family.husband_id) : null,
          getMother: () => family.wife_id ? String(family.wife_id) : null,
          getChildren: () => family.children?.map(id => String(id)) || [],
          getMarriageDate: () => family.marriage_date ? { date: { year: parseInt(family.marriage_date.split('-')[0]) || null } } : null,
          getMarriagePlace: () => family.marriage_place || null,
        };
      },
    };
  };

  /**
   * Render the family tree visualization
   */
  // N137: The active person for tree rendering — focus if set, otherwise home
  const activePersonId = computed(() => focusPersonId.value || homePersonId.value);

  const renderTree = async () => {
    if (!activePersonId.value) return;

    // Wait for SVG ref to be available
    let retries = 0;
    while (!treeSvg.value && retries < 10) {
      await nextTick();
      retries++;
    }

    if (!treeSvg.value) {
      console.error('renderTree: SVG ref not available after retries');
      return;
    }

    treeLoaded.value = false;

    // Clear existing content
    d3.select(treeSvg.value).selectAll('*').remove();

    const dataProvider = createDataProvider();
    if (!dataProvider) {
      console.error('renderTree: No data provider available');
      treeLoaded.value = true;
      return;
    }

    // Check if we have valid data
    const homePerson = dataProvider.getIndi(String(activePersonId.value));
    if (!homePerson) {
      console.error('Active person not found in tree data');
      treeLoaded.value = true;
      return;
    }

    try {
      // Create renderer with callbacks
      const renderer = new DetailedRenderer({
        data: dataProvider,
        horizontal: false,
        indiCallback: (info) => {
          const personData = treeData?.value?.persons?.[info.id];
          if (personData) {
            // N137: Click-to-focus — re-center tree on clicked person
            focusPerson(parseInt(info.id));
            if (onPersonSelect) {
              onPersonSelect(personData);
            }
          }
        },
      });

      // Create chart based on mode
      const chartOptions = {
        data: dataProvider,
        renderer: renderer,
        svgSelector: '#tree-svg',
        startIndi: String(activePersonId.value),
        horizontal: false,
        animate: true,
      };

      // Select chart type based on view mode
      let chart;
      if (treeViewMode.value === 'ancestors') {
        chart = new AncestorChart(chartOptions);
      } else if (treeViewMode.value === 'descendants') {
        chart = new DescendantChart(chartOptions);
      } else if (treeViewMode.value === 'kinship') {
        chart = new KinshipChart(chartOptions);
      } else if (treeViewMode.value === 'relatives') {
        chart = new RelativesChart(chartOptions);
      } else if (treeViewMode.value === 'fancy') {
        chart = new FancyChart(chartOptions);
      } else {
        chart = new HourglassChart(chartOptions);
      }
      chartInstance.value = chart;

      // Render the chart
      chart.render();

      // Setup zoom/pan
      const svg = d3.select(treeSvg.value);
      const zoom = d3.zoom()
        .scaleExtent([0.1, 4])
        .on('zoom', (event) => {
          svg.select('g').attr('transform', event.transform);
        });

      svg.call(zoom);
      currentZoom.value = zoom;

      // Center the chart
      const svgNode = treeSvg.value;
      const bbox = svgNode.getBBox();
      const containerWidth = svgNode.clientWidth;
      const containerHeight = svgNode.clientHeight;

      const scale = Math.min(
        containerWidth / (bbox.width + 100),
        containerHeight / (bbox.height + 100),
        1
      );

      const translateX = (containerWidth - bbox.width * scale) / 2 - bbox.x * scale;
      const translateY = (containerHeight - bbox.height * scale) / 2 - bbox.y * scale;

      svg.call(zoom.transform, d3.zoomIdentity.translate(translateX, translateY).scale(scale));

      treeLoaded.value = true;
    } catch (error) {
      console.error('Failed to render tree:', error);
      treeLoaded.value = true;
    }
  };

  /**
   * Zoom controls
   */
  const zoomIn = () => {
    if (!treeSvg.value || !currentZoom.value) return;
    const svg = d3.select(treeSvg.value);
    svg.transition().duration(300).call(currentZoom.value.scaleBy, 1.3);
  };

  const zoomOut = () => {
    if (!treeSvg.value || !currentZoom.value) return;
    const svg = d3.select(treeSvg.value);
    svg.transition().duration(300).call(currentZoom.value.scaleBy, 0.7);
  };

  const resetZoom = () => {
    if (!treeSvg.value || !currentZoom.value) return;
    const svg = d3.select(treeSvg.value);
    svg.transition().duration(500).call(currentZoom.value.transform, d3.zoomIdentity);
    renderTree();
  };

  /**
   * N137: Temporarily focus on a person without changing the saved home person.
   * Tree re-renders centered on this person. Use returnHome() to go back.
   */
  const focusPerson = (personId) => {
    if (personId === homePersonId.value) {
      // Clicking home person clears focus
      focusPersonId.value = null;
    } else {
      focusPersonId.value = personId;
    }
    hoveredPerson.value = null;
  };

  /**
   * N137: Return to home person (clear temporary focus)
   */
  const returnHome = () => {
    focusPersonId.value = null;
    hoveredPerson.value = null;
  };

  /**
   * Whether currently focused on a non-home person
   */
  const isFocusedAway = computed(() => focusPersonId.value && focusPersonId.value !== homePersonId.value);

  /**
   * Set home person and persist to localStorage
   */
  const setHomePerson = (personId) => {
    homePersonId.value = personId;
    focusPersonId.value = null; // Clear temporary focus
    hoveredPerson.value = null;

    if (personId && selectedTreeId?.value) {
      localStorage.setItem(`genealogy_home_person_${selectedTreeId.value}`, personId);
    }
  };

  /**
   * Load home person from localStorage
   */
  const loadSavedHomePerson = () => {
    if (!selectedTreeId?.value) return;

    const savedId = localStorage.getItem(`genealogy_home_person_${selectedTreeId.value}`);
    if (savedId) {
      homePersonId.value = parseInt(savedId);
    }
  };

  /**
   * Get person initials for avatar placeholder
   */
  const getInitials = (person) => {
    const first = person?.given_name?.[0] || '';
    const last = person?.surname?.[0] || '';
    return (first + last).toUpperCase();
  };

  /**
   * Handle person hover in tree
   */
  const onPersonHover = (person, event) => {
    hoveredPerson.value = person;
    if (event) {
      hoverPosition.value = { x: event.clientX, y: event.clientY };
    }
  };

  /**
   * Clear hovered person
   */
  const clearHover = () => {
    hoveredPerson.value = null;
  };

  // Watch for tree view mode or expanded family changes
  watch([treeViewMode, expandedFamily], () => {
    if (activePersonId.value) {
      renderTree();
    }
  });

  // Watch for active person changes (home or focus)
  watch(activePersonId, async (newId) => {
    if (newId) {
      await nextTick();
      renderTree();
    }
  });

  return {
    // State
    homePersonId,
    focusPersonId,
    activePersonId,
    expandedFamily,
    isFocusedAway,
    treeViewMode,
    treeLoaded,
    treeSvg,
    chartInstance,
    hoveredPerson,
    hoverPosition,
    hoverPanelStyle,

    // Constants
    treeModes,

    // Methods
    renderTree,
    zoomIn,
    zoomOut,
    resetZoom,
    setHomePerson,
    focusPerson,
    returnHome,
    loadSavedHomePerson,
    getInitials,
    onPersonHover,
    clearHover,
    createDataProvider,
  };
}

export default useGenealogyTree;
