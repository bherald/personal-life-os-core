# Photorealistic synthetic demonstration portraits

Photo: [Kreavo (kreavo.ai)](https://kreavo.ai/free-ai-influencer-photos).
Images licensed [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/).
The provider states these images were AI-generated with Google's Nano Banana Pro
and depict no real person. That is the provider's declaration, not an independent
guarantee about resemblance to every real person. Retrieved 2026-10-12.

| Fictional demonstration label | Provider's image title | Files |
| --- | --- | --- |
| Alex Rowan | Male AI influencer street style, black hoodie, golden hour | `alex-rowan-*` |
| Morgan Rowan | Studio portrait of an AI influencer with red hair and freckles | `morgan-rowan-*` |

Names, the Demo Workshop event, Example Hall, and 2000-01-01 are deliberately
fictional sample context. They do not identify real people, describe the source
image's actual creation date, or establish a historical event. Face rectangles
were selected manually to illustrate metadata geometry; no face-recognition
model or biometric embeddings were used.

Each downloaded image was resized to 480 × 596 and re-encoded as RGB JPEG at
quality 88 without copying upstream EXIF/XMP. The `-plain.jpg` derivative is the
demonstration preimage. The `-embedded.jpg` derivative adds reviewed fictional
labels, MWG regions, a caption and keywords, plus source/creator/rights credit.
The `.xmp` sidecar contains the same metadata. Read-back JSON includes only XMP
fields and the public filename.

[PROVENANCE.json](PROVENANCE.json) records exact source URLs, original SHA-256,
output SHA-256, transformations, license, and credits. The downloaded originals
are not redistributed here. These external images remain under CC BY 4.0;
the project's MIT license applies to its code and documentation, not those images.
Keep this credit and license when reusing or adapting the portraits. For social
use, put the linked credit near the image or in the post's credits and label the
image AI-generated with fictional names.

## Verified example

ExifTool 12.76 reads back Alex Rowan and Morgan Rowan in both the embedded region
name and PersonInImage. Applied dimensions are 480 × 596; MWG centers are
`(0.4925, 0.1875)` and `(0.5025, 0.35)` respectively. Captions, keywords, and
CC BY attribution also read back. Decoded RGB pixels were compared before and
after metadata embedding and remained identical. This checks these examples;
it does not certify all photo managers or export services.

To reproduce the read-back:

```sh
exiftool -json -struct -XMP:all examples/photos/alex-rowan-embedded.jpg examples/photos/morgan-rowan-embedded.jpg
```
