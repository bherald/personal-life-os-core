/**
 * CompactFamilyRenderer - Custom Topola renderer with contained cards
 *
 * Fixed-width cards showing:
 * - Photo (or placeholder)
 * - Name
 * - Birth date (mm/dd/yyyy)
 * - Death date or "Living"
 */

import * as d3 from 'd3';

// Card dimensions
const CARD_WIDTH = 140;
const CARD_HEIGHT = 180;
const PHOTO_SIZE = 80;
const PADDING = 8;
const TEXT_HEIGHT = 16;

export class CompactFamilyRenderer {
  constructor(options) {
    this.options = options;
    this.data = options.data;
    this.horizontal = options.horizontal || false;
    this.indiCallback = options.indiCallback;
  }

  getPreferredIndiSize(id) {
    return [CARD_WIDTH, CARD_HEIGHT];
  }

  getPreferredFamSize(id) {
    return [CARD_WIDTH * 0.6, 40];
  }

  updateNodes(nodes) {
    nodes.forEach(node => {
      if (node.data.indi) {
        node.data.width = CARD_WIDTH;
        node.data.height = CARD_HEIGHT;
      }
      if (node.data.family) {
        node.data.familyWidth = CARD_WIDTH * 0.6;
        node.data.familyHeight = 40;
      }
    });
  }

  getFamilyAnchor(node) {
    return [node.data.width / 2, node.data.height];
  }

  getSpouseAnchor(node) {
    return [node.data.width / 2, node.data.height / 2];
  }

  getIndiAnchor(node) {
    return [node.data.width / 2, 0];
  }

  formatDate(dateObj) {
    if (!dateObj || !dateObj.date) return null;
    const d = dateObj.date;
    if (d.year && d.month && d.day) {
      return `${String(d.month).padStart(2, '0')}/${String(d.day).padStart(2, '0')}/${d.year}`;
    }
    if (d.year) return String(d.year);
    return null;
  }

  render(enter, update) {
    const self = this;

    // Enter - new nodes
    const enterG = enter.append('g')
      .attr('class', 'compact-person')
      .attr('transform', d => `translate(${d.x - CARD_WIDTH/2}, ${d.y - CARD_HEIGHT/2})`);

    // Card background
    enterG.append('rect')
      .attr('class', d => {
        const indi = this.data.getIndi(d.data.indi.id);
        const sex = indi?.getSex() || 'U';
        return `person-card person-card-${sex.toLowerCase()}`;
      })
      .attr('width', CARD_WIDTH)
      .attr('height', CARD_HEIGHT)
      .attr('rx', 6)
      .attr('ry', 6)
      .on('click', (event, d) => {
        if (self.indiCallback) {
          self.indiCallback({ id: d.data.indi.id });
        }
      });

    // Photo container background
    enterG.append('rect')
      .attr('class', 'photo-bg')
      .attr('x', (CARD_WIDTH - PHOTO_SIZE) / 2)
      .attr('y', PADDING)
      .attr('width', PHOTO_SIZE)
      .attr('height', PHOTO_SIZE)
      .attr('rx', 4)
      .attr('ry', 4);

    // Photo image (clipped)
    enterG.append('clipPath')
      .attr('id', d => `photo-clip-${d.data.indi.id}`)
      .append('rect')
      .attr('x', (CARD_WIDTH - PHOTO_SIZE) / 2)
      .attr('y', PADDING)
      .attr('width', PHOTO_SIZE)
      .attr('height', PHOTO_SIZE)
      .attr('rx', 4)
      .attr('ry', 4);

    enterG.append('image')
      .attr('class', 'person-photo')
      .attr('x', (CARD_WIDTH - PHOTO_SIZE) / 2)
      .attr('y', PADDING)
      .attr('width', PHOTO_SIZE)
      .attr('height', PHOTO_SIZE)
      .attr('clip-path', d => `url(#photo-clip-${d.data.indi.id})`)
      .attr('preserveAspectRatio', 'xMidYMid slice')
      .attr('href', d => {
        const indi = this.data.getIndi(d.data.indi.id);
        return indi?.getImageUrl() || '';
      })
      .style('display', d => {
        const indi = this.data.getIndi(d.data.indi.id);
        return indi?.getImageUrl() ? 'block' : 'none';
      });

    // Placeholder icon when no photo
    enterG.append('text')
      .attr('class', 'photo-placeholder')
      .attr('x', CARD_WIDTH / 2)
      .attr('y', PADDING + PHOTO_SIZE / 2 + 8)
      .attr('text-anchor', 'middle')
      .style('display', d => {
        const indi = this.data.getIndi(d.data.indi.id);
        return indi?.getImageUrl() ? 'none' : 'block';
      })
      .text('👤');

    // Name (truncated)
    const textY = PADDING + PHOTO_SIZE + 12;
    enterG.append('text')
      .attr('class', 'person-name')
      .attr('x', CARD_WIDTH / 2)
      .attr('y', textY)
      .attr('text-anchor', 'middle')
      .text(d => {
        const indi = this.data.getIndi(d.data.indi.id);
        const name = `${indi?.getFirstName() || ''} ${indi?.getLastName() || ''}`.trim();
        return name.length > 18 ? name.substring(0, 16) + '…' : name;
      })
      .append('title')
      .text(d => {
        const indi = this.data.getIndi(d.data.indi.id);
        return `${indi?.getFirstName() || ''} ${indi?.getLastName() || ''}`.trim();
      });

    // Birth date
    enterG.append('text')
      .attr('class', 'person-date birth')
      .attr('x', CARD_WIDTH / 2)
      .attr('y', textY + TEXT_HEIGHT + 2)
      .attr('text-anchor', 'middle')
      .text(d => {
        const indi = this.data.getIndi(d.data.indi.id);
        const birth = this.formatDate(indi?.getBirthDate());
        return birth ? `b. ${birth}` : '';
      });

    // Death date or Living
    enterG.append('text')
      .attr('class', 'person-date death')
      .attr('x', CARD_WIDTH / 2)
      .attr('y', textY + TEXT_HEIGHT * 2 + 4)
      .attr('text-anchor', 'middle')
      .text(d => {
        const indi = this.data.getIndi(d.data.indi.id);
        if (indi?.isConfirmedDeath()) {
          const death = this.formatDate(indi?.getDeathDate());
          return death ? `d. ${death}` : 'd. ?';
        }
        return 'Living';
      });

    // Sex indicator
    enterG.append('text')
      .attr('class', 'sex-indicator')
      .attr('x', CARD_WIDTH - 12)
      .attr('y', CARD_HEIGHT - 8)
      .attr('text-anchor', 'middle')
      .text(d => {
        const indi = this.data.getIndi(d.data.indi.id);
        const sex = indi?.getSex();
        return sex === 'M' ? '♂' : sex === 'F' ? '♀' : '?';
      });

    // Update existing nodes
    update.attr('transform', d => `translate(${d.x - CARD_WIDTH/2}, ${d.y - CARD_HEIGHT/2})`);
  }

  getCss() {
    return `
      .compact-person { cursor: pointer; }
      .person-card {
        fill: #f8f9fa;
        stroke: #dee2e6;
        stroke-width: 1px;
        transition: all 0.2s ease;
      }
      .person-card:hover {
        stroke: #ff9900;
        stroke-width: 2px;
        filter: drop-shadow(0 2px 4px rgba(0,0,0,0.2));
      }
      .person-card-m { fill: #e3f2fd; stroke: #90caf9; }
      .person-card-f { fill: #fce4ec; stroke: #f48fb1; }
      .person-card-u { fill: #f5f5f5; stroke: #bdbdbd; }
      .photo-bg { fill: #e9ecef; }
      .person-photo { }
      .photo-placeholder { font-size: 32px; fill: #adb5bd; }
      .person-name {
        font-family: verdana, arial, sans-serif;
        font-size: 11px;
        font-weight: bold;
        fill: #212529;
      }
      .person-date {
        font-family: verdana, arial, sans-serif;
        font-size: 10px;
        fill: #495057;
      }
      .person-date.death { fill: #6c757d; }
      .sex-indicator {
        font-size: 12px;
        fill: #6c757d;
      }
    `;
  }
}
