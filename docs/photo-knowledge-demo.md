# Portable photo knowledge demo

Two realistic synthetic portrait examples show selected descriptive knowledge
stored inside a JPEG. **Alex Rowan** and **Morgan Rowan** are fictional names.
The manually illustrated face rectangles are not face-recognition results.
Numerical face embeddings and private review evidence are not included.

| Fictional example | Portrait with embedded metadata | Plain derivative | Metadata sidecar |
| --- | --- | --- | --- |
| Alex Rowan | [JPEG](../examples/photo-knowledge/alex-rowan-embedded.jpg) | [JPEG](../examples/photo-knowledge/alex-rowan-plain.jpg) | [XMP](../examples/photo-knowledge/alex-rowan.xmp) |
| Morgan Rowan | [JPEG](../examples/photo-knowledge/morgan-rowan-embedded.jpg) | [JPEG](../examples/photo-knowledge/morgan-rowan-plain.jpg) | [XMP](../examples/photo-knowledge/morgan-rowan.xmp) |

![Alex Rowan — fictional synthetic demonstration](../examples/photo-knowledge/alex-rowan-embedded.jpg)

![Morgan Rowan — fictional synthetic demonstration](../examples/photo-knowledge/morgan-rowan-embedded.jpg)

Photo: [Kreavo (kreavo.ai)](https://kreavo.ai/free-ai-influencer-photos),
[CC BY 4.0](https://creativecommons.org/licenses/by/4.0/).
The provider describes these as AI-generated portraits. Demonstration copies
are resized and add fictional descriptive metadata. Complete sources, changes,
licenses and SHA256 hashes are in [PROVENANCE.md](../examples/photo-knowledge/PROVENANCE.md)
and [PROVENANCE.json](../examples/photo-knowledge/PROVENANCE.json).

Read the actual saved fields with ExifTool:

```bash
exiftool -j -struct -XMP:All examples/photo-knowledge/alex-rowan-embedded.jpg
exiftool -j -struct -XMP:All examples/photo-knowledge/morgan-rowan-embedded.jpg
```

Included readback JSON records the fictional labels, MWG regions, applied image
dimensions and attribution. Decoded pixel comparison between each plain
derivative and its metadata-bearing copy confirms that embedding metadata did
not change the demonstrated pixel content. It does not compare the resized
derivative with the original download.

This is a standards-based portability demonstration, not automatic identity
verification or a universal metadata-compatibility claim. Keep original files,
inspect existing fields, preview approved changes and read them back. Verify
what survives each editor, export and sharing route. A separate companion
project documents the method and maintenance workflow; its publication is
independent of this release.
