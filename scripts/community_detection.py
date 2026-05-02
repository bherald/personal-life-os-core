#!/usr/bin/env python3
"""
Community Detection for Knowledge Graph
Uses Leiden algorithm via python-igraph for hierarchical community detection.

Usage:
    python3 community_detection.py --input edges.json --output communities.json
    python3 community_detection.py --input edges.json --output communities.json --resolutions 1.0,0.5,0.25

Input JSON format:
    {
        "edges": [[source_entity_id, target_entity_id, confidence], ...],
        "entity_ids": [1, 2, 3, ...]  // optional, includes isolated nodes
    }

Output JSON format:
    {
        "success": true,
        "communities": {
            "level_0": {
                "assignments": {"entity_id": community_id, ...},
                "num_communities": N,
                "modularity": 0.XX,
                "sizes": {"0": 5, "1": 3, ...}
            },
            ...
        },
        "hierarchy": {
            "level_0_to_1": {"child_community": parent_community, ...}
        },
        "entity_degrees": {"entity_id": degree, ...},
        "pagerank": {"entity_id": score, ...},
        "bridge_entities": [entity_id, ...],
        "stats": {
            "total_nodes": N,
            "total_edges": N,
            "levels": N,
            "duration_ms": N
        }
    }
"""

import argparse
import json
import sys
import time

try:
    import igraph as ig
except ImportError:
    print(json.dumps({
        "success": False,
        "error": "python-igraph not installed. Run: pip3 install python-igraph"
    }))
    sys.exit(1)

try:
    import leidenalg
except ImportError:
    print(json.dumps({
        "success": False,
        "error": "leidenalg not installed. Run: pip3 install leidenalg"
    }))
    sys.exit(1)


def load_data(path):
    """Load edge data from JSON file or stdin."""
    if path == '-':
        data = json.load(sys.stdin)
    else:
        with open(path, 'r') as f:
            data = json.load(f)
    return data


def build_graph(data):
    """Build igraph Graph from edge list with entity ID mapping."""
    edges = data.get('edges', [])
    entity_ids = set(data.get('entity_ids', []))

    # Collect all unique entity IDs from edges
    for src, tgt, *_ in edges:
        entity_ids.add(int(src))
        entity_ids.add(int(tgt))

    entity_ids = sorted(entity_ids)

    if not entity_ids:
        return None, {}, {}

    # Map entity IDs to sequential vertex indices
    id_to_idx = {eid: idx for idx, eid in enumerate(entity_ids)}
    idx_to_id = {idx: eid for eid, idx in id_to_idx.items()}

    # Build graph
    g = ig.Graph(n=len(entity_ids), directed=False)
    g.vs['entity_id'] = entity_ids

    edge_list = []
    weights = []
    seen_edges = set()

    for row in edges:
        src, tgt = int(row[0]), int(row[1])
        weight = float(row[2]) if len(row) > 2 else 1.0

        # Deduplicate undirected edges
        edge_key = (min(src, tgt), max(src, tgt))
        if edge_key in seen_edges:
            continue
        seen_edges.add(edge_key)

        if src in id_to_idx and tgt in id_to_idx:
            edge_list.append((id_to_idx[src], id_to_idx[tgt]))
            weights.append(weight)

    if edge_list:
        g.add_edges(edge_list)
        g.es['weight'] = weights

    return g, id_to_idx, idx_to_id


def detect_communities(g, resolutions, idx_to_id):
    """Run Leiden at multiple resolutions for hierarchical communities."""
    results = {}
    hierarchy = {}

    for level, resolution in enumerate(resolutions):
        partition = leidenalg.find_partition(
            g,
            leidenalg.RBConfigurationVertexPartition,
            weights='weight' if g.ecount() > 0 else None,
            resolution_parameter=resolution,
            n_iterations=-1,  # iterate until convergence
            seed=42  # reproducible
        )

        assignments = {}
        sizes = {}
        for comm_id, members in enumerate(partition):
            for vertex_idx in members:
                entity_id = idx_to_id[vertex_idx]
                assignments[str(entity_id)] = comm_id
            sizes[str(comm_id)] = len(members)

        results[f"level_{level}"] = {
            "assignments": assignments,
            "num_communities": len(partition),
            "modularity": partition.modularity,
            "resolution": resolution,
            "sizes": sizes
        }

        # Build hierarchy mapping between adjacent levels
        if level > 0:
            prev_level = f"level_{level - 1}"
            prev_assignments = results[prev_level]["assignments"]
            curr_assignments = assignments

            # Map: for each prev community, find which curr community has majority of its members
            prev_to_curr = {}
            from collections import defaultdict
            community_mapping = defaultdict(lambda: defaultdict(int))

            for entity_id, prev_comm in prev_assignments.items():
                curr_comm = curr_assignments.get(entity_id)
                if curr_comm is not None:
                    community_mapping[prev_comm][curr_comm] += 1

            for prev_comm, curr_counts in community_mapping.items():
                # Assign to majority parent community
                parent = max(curr_counts, key=curr_counts.get)
                prev_to_curr[str(prev_comm)] = parent

            hierarchy[f"level_{level - 1}_to_{level}"] = prev_to_curr

    return results, hierarchy


def compute_centrality(g, idx_to_id):
    """Compute degree and PageRank for all entities."""
    degrees = {}
    pagerank = {}

    if g.vcount() == 0:
        return degrees, pagerank

    # Degree
    for idx, degree in enumerate(g.degree()):
        entity_id = idx_to_id[idx]
        degrees[str(entity_id)] = degree

    # PageRank
    if g.ecount() > 0:
        pr = g.pagerank(weights='weight')
        for idx, score in enumerate(pr):
            entity_id = idx_to_id[idx]
            pagerank[str(entity_id)] = round(score, 8)
    else:
        for idx in range(g.vcount()):
            entity_id = idx_to_id[idx]
            pagerank[str(entity_id)] = 0.0

    return degrees, pagerank


def find_bridge_entities(g, idx_to_id, communities_level_0):
    """Find entities that bridge multiple communities (their neighbors span 2+ communities)."""
    bridges = []
    assignments = communities_level_0.get("assignments", {})

    for vertex_idx in range(g.vcount()):
        entity_id = idx_to_id[vertex_idx]
        my_comm = assignments.get(str(entity_id))
        if my_comm is None:
            continue

        neighbor_comms = set()
        for neighbor_idx in g.neighbors(vertex_idx):
            neighbor_id = idx_to_id[neighbor_idx]
            neighbor_comm = assignments.get(str(neighbor_id))
            if neighbor_comm is not None:
                neighbor_comms.add(neighbor_comm)

        if len(neighbor_comms) > 1:
            bridges.append(entity_id)

    return bridges


def main():
    parser = argparse.ArgumentParser(description='Community detection for knowledge graph')
    parser.add_argument('--input', required=True, help='Input JSON file path (or - for stdin)')
    parser.add_argument('--output', help='Output JSON file path (default: stdout)')
    parser.add_argument('--resolutions', default='1.0,0.5,0.25',
                        help='Comma-separated resolution parameters for Leiden (default: 1.0,0.5,0.25)')
    parser.add_argument('--min-community-size', type=int, default=2,
                        help='Minimum community size to report (default: 2)')
    parser.add_argument('--dry-run', action='store_true',
                        help='Show graph stats without running detection')
    args = parser.parse_args()

    start_time = time.time()

    try:
        data = load_data(args.input)
    except Exception as e:
        output = {"success": False, "error": f"Failed to load input: {str(e)}"}
        print(json.dumps(output))
        sys.exit(1)

    g, id_to_idx, idx_to_id = build_graph(data)

    if g is None or g.vcount() == 0:
        output = {
            "success": False,
            "error": "No entities found in input data"
        }
        print(json.dumps(output))
        sys.exit(1)

    if args.dry_run:
        output = {
            "success": True,
            "dry_run": True,
            "stats": {
                "total_nodes": g.vcount(),
                "total_edges": g.ecount(),
                "density": g.density() if g.vcount() > 1 else 0,
                "components": len(g.connected_components()),
                "is_connected": g.is_connected()
            }
        }
        result_json = json.dumps(output)
        if args.output:
            with open(args.output, 'w') as f:
                f.write(result_json)
        print(result_json)
        sys.exit(0)

    # Parse resolutions
    resolutions = [float(r.strip()) for r in args.resolutions.split(',')]

    # Run community detection
    communities, hierarchy = detect_communities(g, resolutions, idx_to_id)

    # Filter small communities
    min_size = args.min_community_size
    for level_key, level_data in communities.items():
        filtered_sizes = {k: v for k, v in level_data["sizes"].items() if v >= min_size}
        # Don't filter assignments — keep all, but report filtered sizes
        level_data["sizes_filtered"] = filtered_sizes
        level_data["communities_above_min_size"] = len(filtered_sizes)

    # Compute centrality measures
    degrees, pagerank = compute_centrality(g, idx_to_id)

    # Find bridge entities (using level 0 — most granular)
    bridges = find_bridge_entities(g, idx_to_id, communities.get("level_0", {}))

    elapsed_ms = int((time.time() - start_time) * 1000)

    output = {
        "success": True,
        "communities": communities,
        "hierarchy": hierarchy,
        "entity_degrees": degrees,
        "pagerank": pagerank,
        "bridge_entities": bridges,
        "stats": {
            "total_nodes": g.vcount(),
            "total_edges": g.ecount(),
            "levels": len(resolutions),
            "density": round(g.density(), 6) if g.vcount() > 1 else 0,
            "components": len(g.connected_components()),
            "duration_ms": elapsed_ms
        }
    }

    result_json = json.dumps(output)

    if args.output:
        with open(args.output, 'w') as f:
            f.write(result_json)
        # Print summary to stdout for PHP to capture
        print(json.dumps({
            "success": True,
            "output_file": args.output,
            "stats": output["stats"]
        }))
    else:
        print(result_json)


if __name__ == '__main__':
    main()
