#!/usr/bin/env python3
"""
N102 — Local HTR (Handwritten Text Recognition) pipeline using TrOCR.

Model: microsoft/trocr-base-handwritten (~340 MB)
Fallback: same model on CPU when CUDA is unavailable or incompatible

Usage:
    echo '{"image_path": "/path/to/scan.jpg"}' | python3 htr_transcribe.py

Returns JSON:
    {
        "text": "transcribed handwritten text here",
        "model": "microsoft/trocr-base-handwritten",
        "device": "cuda",
        "confidence": 0.87,
        "lines": ["line 1", "line 2"]
    }

On error:
    {"error": "error message"}

To install:
    pip install torch torchvision transformers Pillow tiktoken sentencepiece

Model cache: ~/.cache/huggingface/hub/  (auto-downloaded on first use)
"""

import json
import os
import sys


def is_cuda_compatibility_error(error: str) -> bool:
    error = error.lower()
    return (
        "no kernel image is available" in error
        or "cuda error" in error
        or "cudaerrornokernelimagefordevice" in error
    )


def transcribe(image_path: str, force_cpu: bool = False) -> dict:
    try:
        import torch
        from transformers import TrOCRProcessor, VisionEncoderDecoderModel
        from PIL import Image
    except ImportError as e:
        return {"error": f"dependency_missing: {e}. Install: pip install torch transformers Pillow"}

    if not os.path.exists(image_path):
        return {"error": f"file_not_found: {image_path}"}

    # Select device conservatively.
    # On shared 6 GB GPUs, low free VRAM can still OOM even with the smaller model,
    # so force CPU fallback unless there is comfortable headroom.
    device = "cpu"
    model_name = "microsoft/trocr-base-handwritten"

    if torch.cuda.is_available() and not force_cpu:
        try:
            free_vram, _total_vram = torch.cuda.mem_get_info(0)
            if free_vram > 2 * 1024 * 1024 * 1024:
                device = "cuda"
                model_name = "microsoft/trocr-base-handwritten"
        except Exception:
            device = "cpu"
            model_name = "microsoft/trocr-base-handwritten"

    try:
        processor = TrOCRProcessor.from_pretrained(model_name)
        model = VisionEncoderDecoderModel.from_pretrained(model_name).to(device)
        model.eval()
    except Exception as e:
        if device == "cuda" and is_cuda_compatibility_error(str(e)):
            return transcribe(image_path, force_cpu=True)
        return {"error": f"model_load_failed: {e}"}

    try:
        img = Image.open(image_path).convert("RGB")
    except Exception as e:
        return {"error": f"image_open_failed: {e}"}

    # TrOCR processes single lines best — split tall images into horizontal strips
    width, height = img.size
    lines = []

    # If image is taller than 2× its width, split into line strips
    # Otherwise treat as a single-line or short-form document
    if height > width * 2 and height > 200:
        strip_height = max(80, height // max(1, height // 120))
        strips = []
        for y in range(0, height, strip_height):
            strip = img.crop((0, y, width, min(y + strip_height, height)))
            if strip.height > 20:  # skip empty strips
                strips.append(strip)
    else:
        strips = [img]

    confidences = []

    for strip in strips[:20]:  # cap at 20 lines to prevent runaway
        try:
            pixel_values = processor(images=strip, return_tensors="pt").pixel_values.to(device)

            with torch.no_grad():
                generated_ids = model.generate(
                    pixel_values,
                    max_new_tokens=128,
                    output_scores=True,
                    return_dict_in_generate=True,
                )

            sequence_ids = generated_ids.sequences
            text = processor.batch_decode(sequence_ids, skip_special_tokens=True)[0].strip()

            # Estimate confidence from sequence scores (average log-prob → sigmoid)
            if hasattr(generated_ids, "scores") and generated_ids.scores:
                import math
                score_tensors = generated_ids.scores
                log_probs = []
                for step_scores in score_tensors:
                    probs = torch.softmax(step_scores, dim=-1)
                    best = float(probs.max().cpu())
                    if best > 0:
                        log_probs.append(math.log(best))
                conf = math.exp(sum(log_probs) / len(log_probs)) if log_probs else 0.5
            else:
                conf = 0.5

            if text:
                lines.append(text)
                confidences.append(min(conf, 1.0))

        except Exception as e:
            if device == "cuda" and is_cuda_compatibility_error(str(e)):
                try:
                    del model
                    torch.cuda.empty_cache()
                except Exception:
                    pass

                return transcribe(image_path, force_cpu=True)
            return {"error": f"strip_transcription_failed: {e}"}

    full_text = "\n".join(lines)
    avg_conf = sum(confidences) / len(confidences) if confidences else 0.0

    return {
        "text": full_text,
        "lines": lines,
        "model": model_name,
        "device": device,
        "confidence": round(avg_conf, 4),
        "line_count": len(lines),
    }


def main():
    try:
        raw = sys.stdin.read().strip()
        data = json.loads(raw)
        image_path = data.get("image_path", "")

        if not image_path:
            print(json.dumps({"error": "no_image_path provided"}))
            return

        result = transcribe(image_path)
        print(json.dumps(result))

    except json.JSONDecodeError:
        print(json.dumps({"error": "invalid_json_input"}))
    except Exception as e:
        print(json.dumps({"error": str(e)}))


if __name__ == "__main__":
    main()
