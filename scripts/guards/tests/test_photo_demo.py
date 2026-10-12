"""Verify licensed synthetic demo assets and their actual saved metadata."""
import hashlib
import json
from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[3]
PHOTOS = ROOT / 'examples/photo-knowledge'


class PhotoDemoTest(unittest.TestCase):
    def test_exact_licensed_sources_and_derivatives_have_provenance(self):
        records = json.loads((PHOTOS / 'PROVENANCE.json').read_text())
        self.assertEqual(len(records), 2)
        for record in records:
            self.assertEqual(record['license'], 'CC BY 4.0')
            self.assertEqual(record['license_url'], 'https://creativecommons.org/licenses/by/4.0/')
            self.assertEqual(record['attribution'], 'Photo: Kreavo (kreavo.ai)')
            self.assertTrue(record['changes'])
            self.assertIn('fictional', record['changes'])
            for name, expected in record['files'].items():
                self.assertEqual(Path(name).name, name)
                data = (PHOTOS / name).read_bytes()
                self.assertLess(len(data), 1_000_000)
                self.assertEqual(hashlib.sha256(data).hexdigest(), expected)

    @unittest.skipUnless(shutil.which('exiftool'), 'ExifTool is required for actual image readback')
    def test_standalone_jpegs_retain_fictional_labels_regions_and_credit(self):
        for stem, name in [('alex-rowan', 'Alex Rowan'), ('morgan-rowan', 'Morgan Rowan')]:
            output = subprocess.check_output([
                'exiftool', '-j', '-struct', '-XMP:All', str(PHOTOS / (stem + '-embedded.jpg')),
            ], text=True, timeout=15)
            data = json.loads(output)[0]
            self.assertIn(name, data.get('PersonInImage', []))
            region = data['RegionInfo']['RegionList'][0]
            self.assertEqual(region['Name'], name)
            self.assertEqual(region['Type'], 'Face')
            self.assertEqual(region['Area']['Unit'], 'normalized')
            self.assertEqual(data['RegionInfo']['AppliedToDimensions']['W'], 480)
            self.assertEqual(data['RegionInfo']['AppliedToDimensions']['H'], 596)
            text = json.dumps(data)
            self.assertIn('Kreavo', text)
            self.assertIn('creativecommons.org/licenses/by/4.0', text)
            self.assertNotIn('face_embeddings', text)
            self.assertNotIn('embedding_vector', text)


if __name__ == '__main__':
    unittest.main()
