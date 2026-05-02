# Face Metadata Writeback

PLOS face metadata writeback is a standards-based portability feature. Its
purpose is to keep human-approved face regions and person labels with the media
collection instead of trapping that knowledge only in an application database.

This is not derived from PhotoPrism, LibrePhotos, digiKam, Lightroom, or any
other photo manager implementation. Those projects are useful workflow and
interoperability references. PLOS should target open metadata formats and
ExifTool-compatible behavior, not copied application code.

## Metadata Shape

The public target mapping is:

- Face rectangles: XMP Metadata Working Group region structures, typically
  `mwg-rs:Regions`.
- Person names: IPTC Extension `Iptc4xmpExt:PersonInImage`.
- Search/discovery labels: `dc:subject` and IPTC keyword fields when the
  operator chooses keyword writeback.
- Risky or read-only originals: XMP sidecars rather than in-place writes.

MWG as an organization has disbanded, but the region schema remains a practical
de-facto interoperability shape through ExifTool, Adobe tooling, digiKam, and
Lightroom-compatible workflows.

## Safety Contract

Writeback must be conservative:

- Off by default.
- Runtime-gated by `PLOS_METADATA_WRITEBACK_ENABLED=false` and
  `PLOS_METADATA_WRITEBACK_IN_PLACE=false` in public defaults.
- Human-approved labels and regions only; raw model suggestions are candidates,
  not facts.
- Dry-run or diff preview before write.
- Sidecar-only mode for RAW, read-only, or risky containers.
- Original-file backup or reversible write policy when editing files directly.
- Conflict detection before overwriting existing `mwg-rs:Regions`,
  `PersonInImage`, `dc:subject`, or keyword values from another tool.
- Extra consent for minors, sensitive labels, private albums, or public export.

## Runtime Boundary

The database remains the working state for review queues, face clustering,
genealogy links, and audit trails. Metadata writeback is a portability output:
after an operator confirms a face/person link, PLOS can mark the file as needing
metadata sync and later write the approved region/name set.

The public edition currently blocks physical writeback unless the operator
explicitly enables both writeback and in-place writes. Sidecar-first writeback
remains the preferred public direction for RAW, read-only, or archival originals.
Private media, real family photos, and personal genealogy data must never be
bundled as fixtures.

## Implementation References

Current public-bound implementation points:

- `app/Services/FaceRegionService.php`: XMP/MWG face-region read/write logic.
- `app/Services/ExifWritebackService.php`: composed metadata writeback for
  dates, faces, tags, and locations.
- `app/Services/Genealogy/FaceLinkBridgeService.php`: optional genealogy bridge
  that marks confirmed face/person links for metadata sync.
- `tests/Fixtures/media/face-regions-sample.xmp`: synthetic public fixture for
  face-region metadata shape.
- `tests/Fixtures/PROVENANCE.md`: fixture source and license record.

## Public Provenance Rule

For release review, describe this feature as:

> Standards-based face/person metadata writeback using XMP/MWG regions, IPTC
> PersonInImage, keyword fields, and ExifTool-compatible read/write paths.

Avoid wording that claims direct adaptation from a specific photo-manager
codebase. A public release can reference PhotoPrism, LibrePhotos, digiKam, and
Lightroom as interoperability context, but implementation code should be
PLOS-native unless a separate file-level license review explicitly allows reuse.
