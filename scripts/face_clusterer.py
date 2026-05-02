#!/usr/bin/env python3
"""
Face Clusterer - HDBSCAN clustering for unnamed face embeddings.

Reads 128-dim embeddings from pgvector, clusters with HDBSCAN,
applies 6x over-clustering split rule, outputs JSON assignments.

Supports semi-supervised mode with --anchors for confirmed cluster centroids.

Usage:
    python3 face_clusterer.py --db-url "postgresql://user:pass@host/db"
    python3 face_clusterer.py --input embeddings.json --output clusters.json
    python3 face_clusterer.py --input embeddings.json --output clusters.json --dry-run
    python3 face_clusterer.py --input embeddings.json --output clusters.json --anchors anchors.json

Input JSON format (from stdin or --input file):
    [{"id": 123, "embedding": [0.1, 0.2, ...]}, ...]

Anchors JSON format (--anchors file):
    [{"cluster_id": 1, "centroid": [0.1, 0.2, ...], "name": "John"}, ...]

Output JSON format:
    {"assignments": {"123": 0, "456": 0, "789": 1, ...}, "stats": {...}}
    Noise points (label=-1) get individual singleton labels.
    With anchors: {"assignments": {...}, "anchor_merges": {"123": 1, ...}, "stats": {...}}
"""

import argparse
import json
import sys
import numpy as np
from collections import Counter

try:
    import hdbscan
except ImportError:
    print(json.dumps({"success": False, "error": "hdbscan not installed. Run: pip install hdbscan"}))
    sys.exit(1)


def load_embeddings_from_json(path):
    """Load embeddings from JSON file."""
    if path == '-':
        data = json.load(sys.stdin)
    else:
        with open(path, 'r') as f:
            data = json.load(f)
    return data


def load_embeddings_from_db(db_url):
    """Load unclustered embeddings directly from pgvector."""
    try:
        import psycopg2
    except ImportError:
        print(json.dumps({"success": False, "error": "psycopg2 not installed"}))
        sys.exit(1)

    conn = psycopg2.connect(db_url)
    cur = conn.cursor()
    cur.execute("""
        SELECT id, embedding::text
        FROM face_embeddings
        WHERE person_cluster_id IS NULL
        ORDER BY id
    """)

    data = []
    for row in cur:
        fid = row[0]
        # Parse pgvector text format: [0.1,0.2,...]
        emb_str = row[1].strip('[]')
        embedding = [float(x) for x in emb_str.split(',')]
        data.append({"id": fid, "embedding": embedding})

    cur.close()
    conn.close()
    return data


def load_anchors(path):
    """Load confirmed cluster centroids as anchors."""
    with open(path, 'r') as f:
        return json.load(f)


def cluster_faces(embeddings_data, min_cluster_size=2, min_samples=1, over_cluster_factor=6):
    """
    Run HDBSCAN clustering on face embeddings.

    Args:
        embeddings_data: list of {"id": int, "embedding": [float, ...]}
        min_cluster_size: minimum faces per cluster
        min_samples: HDBSCAN min_samples (1 = permissive)
        over_cluster_factor: split clusters larger than factor × median

    Returns:
        dict with assignments and stats
    """
    if not embeddings_data:
        return {"assignments": {}, "stats": {"total": 0, "clusters": 0}}

    ids = [item["id"] for item in embeddings_data]
    embeddings = np.array([item["embedding"] for item in embeddings_data], dtype=np.float64)

    # L2-normalize for cosine-like behavior with euclidean HDBSCAN
    norms = np.linalg.norm(embeddings, axis=1, keepdims=True)
    norms[norms == 0] = 1
    embeddings_norm = embeddings / norms

    # Run HDBSCAN
    clusterer = hdbscan.HDBSCAN(
        min_cluster_size=min_cluster_size,
        min_samples=min_samples,
        metric='euclidean',  # On L2-normalized vectors, euclidean ~ cosine
        cluster_selection_method='eom',  # Excess of Mass (better for varying density)
        core_dist_n_jobs=-1,
    )

    labels = clusterer.fit_predict(embeddings_norm)

    # Apply over-clustering split rule
    labels = split_overclusters(embeddings_norm, labels, over_cluster_factor)

    # Convert noise points to individual singletons
    max_label = max(labels) if len(labels) > 0 else -1
    singleton_counter = max_label + 1
    for i in range(len(labels)):
        if labels[i] == -1:
            labels[i] = singleton_counter
            singleton_counter += 1

    # Build assignments map
    assignments = {}
    for i, fid in enumerate(ids):
        assignments[str(fid)] = int(labels[i])

    # Compute stats
    label_counts = Counter(labels)
    cluster_sizes = list(label_counts.values())
    num_clusters = len(label_counts)
    singletons = sum(1 for c in cluster_sizes if c == 1)
    large_clusters = sum(1 for c in cluster_sizes if c > 50)

    stats = {
        "total_faces": len(ids),
        "num_clusters": num_clusters,
        "singletons": singletons,
        "large_clusters_gt50": large_clusters,
        "median_cluster_size": int(np.median(cluster_sizes)) if cluster_sizes else 0,
        "mean_cluster_size": round(np.mean(cluster_sizes), 1) if cluster_sizes else 0,
        "max_cluster_size": max(cluster_sizes) if cluster_sizes else 0,
        "size_distribution": {
            "1": sum(1 for s in cluster_sizes if s == 1),
            "2-5": sum(1 for s in cluster_sizes if 2 <= s <= 5),
            "6-10": sum(1 for s in cluster_sizes if 6 <= s <= 10),
            "11-20": sum(1 for s in cluster_sizes if 11 <= s <= 20),
            "21-50": sum(1 for s in cluster_sizes if 21 <= s <= 50),
            "51+": sum(1 for s in cluster_sizes if s > 50),
        },
    }

    return {"assignments": assignments, "stats": stats}


def cluster_faces_with_anchors(embeddings_data, anchors_data, min_cluster_size=2, min_samples=1):
    """
    Semi-supervised HDBSCAN: cluster unknown faces using confirmed clusters as anchors.

    Approach:
    1. Run HDBSCAN on all unknown faces
    2. For each resulting cluster, compute centroid and compare to anchor centroids
    3. If cosine similarity >= 0.88, assign entire HDBSCAN cluster to that anchor
    4. Remaining clusters get new IDs as usual

    This is more robust than approximate_predict (which degrades with high noise)
    and respects the cluster structure HDBSCAN finds.

    Args:
        embeddings_data: list of {"id": int, "embedding": [float, ...]}
        anchors_data: list of {"cluster_id": int, "centroid": [float, ...], "name": str}
        min_cluster_size: HDBSCAN min_cluster_size
        min_samples: HDBSCAN min_samples

    Returns:
        dict with assignments, anchor_merges, and stats
    """
    HIGH_CONFIDENCE = 0.92  # Cosine similarity threshold for anchor matching — matches PHP pipeline (dlib 128-dim needs strict threshold)

    if not embeddings_data:
        return {"assignments": {}, "anchor_merges": {}, "stats": {"total_faces": 0}}

    ids = [item["id"] for item in embeddings_data]
    embeddings = np.array([item["embedding"] for item in embeddings_data], dtype=np.float64)

    # L2-normalize
    norms = np.linalg.norm(embeddings, axis=1, keepdims=True)
    norms[norms == 0] = 1
    embeddings_norm = embeddings / norms

    # Prepare anchor centroids (L2-normalized)
    anchor_centroids = []
    anchor_ids = []
    anchor_names = []
    for a in anchors_data:
        c = np.array(a["centroid"], dtype=np.float64)
        n = np.linalg.norm(c)
        if n > 0:
            c = c / n
        anchor_centroids.append(c)
        anchor_ids.append(a["cluster_id"])
        anchor_names.append(a.get("name", ""))
    anchor_matrix = np.array(anchor_centroids) if anchor_centroids else np.zeros((0, 128))

    # Run HDBSCAN on unknown faces
    clusterer = hdbscan.HDBSCAN(
        min_cluster_size=min_cluster_size,
        min_samples=min_samples,
        metric='euclidean',
        cluster_selection_method='eom',
        core_dist_n_jobs=-1,
    )
    labels = clusterer.fit_predict(embeddings_norm)

    # For each HDBSCAN cluster, compute centroid and check against anchors
    unique_labels = set(l for l in labels if l >= 0)
    cluster_to_anchor = {}  # hdbscan_label -> anchor_cluster_id

    for label in unique_labels:
        indices = [i for i, l in enumerate(labels) if l == label]
        cluster_embeddings = embeddings_norm[indices]
        centroid = cluster_embeddings.mean(axis=0)
        centroid_norm = np.linalg.norm(centroid)
        if centroid_norm > 0:
            centroid = centroid / centroid_norm

        if len(anchor_matrix) > 0:
            # Cosine similarity = dot product of L2-normalized vectors
            similarities = anchor_matrix @ centroid
            best_idx = np.argmax(similarities)
            best_sim = similarities[best_idx]

            if best_sim >= HIGH_CONFIDENCE:
                cluster_to_anchor[label] = anchor_ids[best_idx]

    # Build assignments and anchor_merges
    assignments = {}
    anchor_merges = {}  # face_embedding_id -> existing_cluster_id (for merging into confirmed)
    new_cluster_counter = 0

    # Map HDBSCAN labels to output labels for non-anchor clusters
    label_remap = {}

    for i, fid in enumerate(ids):
        hdbscan_label = labels[i]

        if hdbscan_label >= 0 and hdbscan_label in cluster_to_anchor:
            # This face should be merged into an existing confirmed cluster
            anchor_merges[str(fid)] = cluster_to_anchor[hdbscan_label]
        elif hdbscan_label >= 0:
            # Regular new cluster
            if hdbscan_label not in label_remap:
                label_remap[hdbscan_label] = new_cluster_counter
                new_cluster_counter += 1
            assignments[str(fid)] = label_remap[hdbscan_label]
        else:
            # Noise point -> singleton
            assignments[str(fid)] = new_cluster_counter
            new_cluster_counter += 1

    # Stats
    label_counts = Counter(labels)
    cluster_sizes = list(label_counts.values())
    stats = {
        "total_faces": len(ids),
        "hdbscan_clusters": len(unique_labels),
        "anchor_matched_clusters": len(cluster_to_anchor),
        "anchor_matched_faces": len(anchor_merges),
        "new_clusters": new_cluster_counter,
        "noise_points": sum(1 for l in labels if l == -1),
        "singletons": sum(1 for s in cluster_sizes if s == 1),
    }

    return {
        "assignments": assignments,
        "anchor_merges": anchor_merges,
        "stats": stats,
    }


def split_overclusters(embeddings, labels, factor=6):
    """
    Split clusters that are > factor × median size using tighter HDBSCAN.
    Prevents one mega-cluster from swallowing diverse faces.
    """
    labels = list(labels)
    label_counts = Counter(l for l in labels if l >= 0)

    if not label_counts:
        return labels

    sizes = list(label_counts.values())
    median_size = np.median(sizes)
    threshold = max(factor * median_size, 20)  # At least 20

    max_label = max(labels) if labels else 0

    for cluster_label, size in label_counts.items():
        if size <= threshold:
            continue

        # Get indices of faces in this cluster
        indices = [i for i, l in enumerate(labels) if l == cluster_label]
        sub_embeddings = embeddings[indices]

        # Re-cluster with stricter parameters
        sub_clusterer = hdbscan.HDBSCAN(
            min_cluster_size=max(2, int(size * 0.05)),  # 5% of original size
            min_samples=2,
            metric='euclidean',
            cluster_selection_method='eom',
        )
        sub_labels = sub_clusterer.fit_predict(sub_embeddings)

        # Remap sub-labels to global label space
        for j, idx in enumerate(indices):
            if sub_labels[j] == -1:
                labels[idx] = -1  # Will become singleton later
            elif sub_labels[j] == 0:
                pass  # Keep original label for largest sub-cluster
            else:
                labels[idx] = max_label + sub_labels[j]

        # Only count non-noise sub-labels (noise = -1); if all noise, max stays same
        sub_max = max((int(l) for l in sub_labels if l >= 0), default=0)
        max_label += sub_max

    return labels


def main():
    parser = argparse.ArgumentParser(description='HDBSCAN face clustering')
    parser.add_argument('--input', '-i', help='Input JSON file (or - for stdin)')
    parser.add_argument('--output', '-o', help='Output JSON file (default: stdout)')
    parser.add_argument('--db-url', help='PostgreSQL connection URL for direct DB access')
    parser.add_argument('--anchors', help='JSON file with confirmed cluster centroids for semi-supervised mode')
    parser.add_argument('--min-cluster-size', type=int, default=2)
    parser.add_argument('--min-samples', type=int, default=1)
    parser.add_argument('--over-cluster-factor', type=int, default=6)
    parser.add_argument('--dry-run', action='store_true', help='Show stats without outputting assignments')
    args = parser.parse_args()

    # Load embeddings
    if args.db_url:
        data = load_embeddings_from_db(args.db_url)
    elif args.input:
        data = load_embeddings_from_json(args.input)
    else:
        print(json.dumps({"success": False, "error": "Provide --input or --db-url"}))
        sys.exit(1)

    if not data:
        result = {"success": True, "assignments": {}, "stats": {"total_faces": 0, "num_clusters": 0}}
    elif args.anchors:
        # Semi-supervised mode with confirmed cluster anchors
        anchors = load_anchors(args.anchors)
        result = cluster_faces_with_anchors(
            data,
            anchors,
            min_cluster_size=args.min_cluster_size,
            min_samples=args.min_samples,
        )
        result["success"] = True
    else:
        result = cluster_faces(
            data,
            min_cluster_size=args.min_cluster_size,
            min_samples=args.min_samples,
            over_cluster_factor=args.over_cluster_factor,
        )
        result["success"] = True

    if args.dry_run:
        # Only output stats
        output = {"success": True, "stats": result.get("stats", {}), "dry_run": True}
    else:
        output = result

    # Output
    if args.output:
        with open(args.output, 'w') as f:
            json.dump(output, f)
        print(json.dumps({"success": True, "output_file": args.output, "stats": result.get("stats", {})}))
    else:
        print(json.dumps(output))


if __name__ == '__main__':
    main()
