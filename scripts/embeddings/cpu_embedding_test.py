"""CPU input boundary tests; no model download or inference required."""

import contextlib
import io
import json
import sys
import types
import unittest
from unittest.mock import Mock, patch

import cpu_embedding as subject


class CpuEmbeddingInputTest(unittest.TestCase):
    def run_main(self, raw, preserve=True):
        output = io.StringIO()
        generator = Mock(return_value=[0.5] * 768)
        with patch.object(subject, 'load_model', return_value=('tokenizer', 'model')), \
                patch.object(subject, 'generate_embedding', generator), \
                patch.object(sys, 'argv', ['cpu_embedding.py'] + (['--preserve-input'] if preserve else [])), \
                patch.object(sys, 'stdin', io.TextIOWrapper(io.BytesIO(raw), encoding='utf-8')), \
                contextlib.redirect_stdout(output):
            code = 0
            try:
                subject.main()
            except SystemExit as error:
                code = error.code
        return code, json.loads(output.getvalue()), generator

    def test_preserved_stdin_keeps_crlf_whitespace_unicode_and_nul(self):
        original = ('  José 日本語\r\n\t\0' * 6000) + ' FULL SOURCE END  '
        code, result, generator = self.run_main(original.encode('utf-8'))
        self.assertEqual(code, 0)
        self.assertEqual(result['dimension'], 768)
        generator.assert_called_once_with(original, 'tokenizer', 'model', preserve_input=True)

    def test_legacy_stdin_behavior_is_unchanged(self):
        code, _, generator = self.run_main(b'  old\r\ninput  ', preserve=False)
        self.assertEqual(code, 0)
        generator.assert_called_once_with('old\ninput', 'tokenizer', 'model', preserve_input=False)

    def test_invalid_utf8_reports_failure_without_inference(self):
        code, result, generator = self.run_main(b'original\xff')
        self.assertEqual(code, 1)
        self.assertIn('decode', result['error'])
        generator.assert_not_called()

    def test_empty_input_reports_failure_without_inference(self):
        code, result, generator = self.run_main(b'')
        self.assertEqual(code, 1)
        self.assertEqual(result['error'], 'No text provided')
        generator.assert_not_called()

    def test_local_model_loading_propagates_download_policy(self):
        for local_only in (True, False):
            with self.subTest(local_only=local_only):
                tokenizer, model = Mock(), Mock()
                with patch.dict(sys.modules, {'torch': types.SimpleNamespace(), 'transformers': types.SimpleNamespace(
                        AutoTokenizer=tokenizer, AutoModel=model)}):
                    subject.load_model(local_files_only=local_only)
                tokenizer.from_pretrained.assert_called_once_with(subject.MODEL_NAME, local_files_only=local_only)
                model.from_pretrained.assert_called_once_with(subject.MODEL_NAME, local_files_only=local_only)

    def test_context_overflow_never_shortens_or_calls_model(self):
        tokenizer = Mock(return_value={'input_ids': types.SimpleNamespace(shape=(1, 513))})
        model = Mock()
        with patch.dict(sys.modules, {'torch': types.SimpleNamespace()}):
            with self.assertRaisesRegex(ValueError, 'Original input was not shortened'):
                subject.generate_embedding('  full source\r\n', tokenizer, model, preserve_input=True)
        tokenizer.assert_called_once_with('  full source\r\n', padding=True, truncation=False,
                                          max_length=512, return_tensors='pt')
        model.assert_not_called()

    def test_valid_input_reaches_existing_pooling_in_both_modes(self):
        for preserve in (True, False):
            with self.subTest(preserve=preserve):
                tokenizer = Mock(return_value={'input_ids': types.SimpleNamespace(shape=(1, 512))})
                tensor = unittest.mock.MagicMock()
                tensor.__getitem__.return_value.tolist.return_value = [0.5] * 768
                model = Mock(return_value=types.SimpleNamespace(last_hidden_state=tensor))
                normalize = Mock(return_value=tensor)
                torch = types.SimpleNamespace(no_grad=contextlib.nullcontext,
                                              nn=types.SimpleNamespace(functional=types.SimpleNamespace(normalize=normalize)))
                with patch.dict(sys.modules, {'torch': torch}):
                    result = subject.generate_embedding('  exact input  ', tokenizer, model, preserve_input=preserve)
                self.assertEqual(result, [0.5] * 768)
                self.assertEqual(tokenizer.call_args.kwargs['truncation'], not preserve)
                model.assert_called_once()
                normalize.assert_called_once()


if __name__ == '__main__':
    unittest.main()
