# Face Recognition Setup

PLOS face recognition uses local Python code and dlib-compatible model files.
The model files are intentionally not committed to git because they are large
third-party assets with their own upstream terms. Public operators must download
them separately and keep provenance with the install.

## Required Files

Place both files in `scripts/`:

- `scripts/shape_predictor_68_face_landmarks.dat`
- `scripts/dlib_face_recognition_resnet_model_v1.dat`

Download and verify the upstream compressed files:

```bash
curl -fL -o /tmp/shape_predictor_68_face_landmarks.dat.bz2 \
  http://dlib.net/files/shape_predictor_68_face_landmarks.dat.bz2
echo "7d6637b8f34ddb0c1363e09a4628acb34314019ec3566fd66b80c04dda6980f5  /tmp/shape_predictor_68_face_landmarks.dat.bz2" | sha256sum -c -
bunzip2 -k /tmp/shape_predictor_68_face_landmarks.dat.bz2
mv /tmp/shape_predictor_68_face_landmarks.dat scripts/

curl -fL -o /tmp/dlib_face_recognition_resnet_model_v1.dat.bz2 \
  http://dlib.net/files/dlib_face_recognition_resnet_model_v1.dat.bz2
echo "abb1f61041e434465855ce81c2bd546e830d28bcbed8d27ffbe5bb408b11553a  /tmp/dlib_face_recognition_resnet_model_v1.dat.bz2" | sha256sum -c -
bunzip2 -k /tmp/dlib_face_recognition_resnet_model_v1.dat.bz2
mv /tmp/dlib_face_recognition_resnet_model_v1.dat scripts/
```

Then run:

```bash
php artisan setup:doctor --profile=media
```

## Runtime Shape

- `scripts/face_detector.py` uses the `face_recognition` package for single
  image detection and embeddings.
- `scripts/face_detector_batch.py` loads the dlib detector, landmark predictor,
  and face recognition model once, then processes many image paths from stdin.
- Face rows are stored in PLOS tables first. Physical metadata writeback is a
  separate opt-in output and is disabled by default in public installs.

## Public Safety Rules

- Do not commit the `.dat` model files or real photos as fixtures.
- Record source URLs and checksums if you mirror the files internally.
- Treat face embeddings and person labels as biometric/personal data.
- Only write face/person metadata after human approval. See
  `docs/face-metadata-writeback.md` for the writeback gate and public defaults.

## Provenance

The URLs above are the upstream dlib-hosted model files used by common
dlib/face_recognition workflows. PLOS invokes permissive Python libraries, but
model weights remain third-party assets. Keep them outside the public repository
unless a separate release review intentionally vendors them with compatible
license attribution.
