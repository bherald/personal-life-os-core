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

Model cache: provision approved assets separately; runtime stays offline.
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


def frame_texts(segments):
    grouped = {}
    for segment in segments:
        if isinstance(segment.get("text"), str):
            grouped.setdefault(segment["frame_number"], []).append(segment["text"])
    return [{"frame_number": number, "text": "\n".join(texts)} for number, texts in grouped.items()]


def decoded_text(segments, framed):
    if framed:
        return "\f".join(frame["text"] for frame in frame_texts(segments))
    return "\n".join(segment["text"] for segment in segments if isinstance(segment.get("text"), str))


def transcribe(image_path: str, force_cpu: bool = False, cli_options=None, progress=None) -> dict:
    # Ordinary scheduled callers retain their established routing and limits.
    # CLI callers must explicitly select a local snapshot and one device.
    strict = cli_options is not None
    framed = isinstance(cli_options, dict) and cli_options.get("schema") == "plos.cli.htr.v2"
    if strict:
        required = {"schema", "model_path", "device", "max_new_tokens"} | ({"frame_selection"} if framed else set())
        if (not isinstance(cli_options, dict)
                or set(cli_options) != required
                or cli_options.get("schema") not in ("plos.cli.htr.v1", "plos.cli.htr.v2")
                or cli_options.get("device") not in ("cpu", "cuda")
                or type(cli_options.get("max_new_tokens")) is not int
                or not 1 <= cli_options["max_new_tokens"] <= 4096
                or not isinstance(cli_options.get("model_path"), str)
                or "\0" in cli_options["model_path"]
                or not os.path.isabs(cli_options["model_path"])
                or os.path.realpath(cli_options["model_path"]) != cli_options["model_path"]
                or not os.path.isdir(cli_options["model_path"])
                or force_cpu):
            return {"error": "invalid_cli_htr_contract"}
    os.environ.update({"HF_HUB_OFFLINE": "1", "TRANSFORMERS_OFFLINE": "1", "HF_HUB_DISABLE_TELEMETRY": "1"})
    try:
        import torch
        from transformers import TrOCRProcessor, VisionEncoderDecoderModel
        from PIL import Image
    except ImportError as e:
        return {"error": f"dependency_missing: {e}. Install: pip install torch transformers Pillow"}

    if not os.path.exists(image_path):
        return {"error": f"file_not_found: {image_path}"}

    frame_metadata = {}
    frame_plan = []
    if strict:
        try:
            with Image.open(image_path) as original:
                count = getattr(original, "n_frames", 1)
                if not framed and count != 1:
                    return {"error": "explicit_frame_contract_required", "complete": False, "source_frame_count": count}
                if framed:
                    selection = cli_options["frame_selection"]
                    selected = list(range(1, count + 1)) if selection == "all" else selection
                    if (not isinstance(selected, list) or not selected
                            or any(type(n) is not int or n < 1 or n > count for n in selected)
                            or len(set(selected)) != len(selected)):
                        return {"error": "invalid_frame_selection", "complete": False, "source_frame_count": count}
                    frames = []
                    for number in selected:
                        original.seek(number - 1)
                        width, height = original.size
                        strip_height = max(80, height // max(1, height // 120)) if height > width * 2 and height > 200 else height
                        bounds = list(range(0, height, strip_height))
                        frames.append({"frame_number": number, "width": width, "height": height, "strip_count": len(bounds)})
                        for index, top in enumerate(bounds):
                            frame_plan.append({"frame_number": number, "frame_strip_index": index,
                                               "top": top, "bottom": min(top + strip_height, height), "width": width})
                    frame_metadata = {"source_frame_count": count, "frame_selection": selection,
                                      "selected_frames": selected, "frames": frames}
                    if progress is not None:
                        progress({"schema": "plos.cli.htr-frames.v1", **frame_metadata, "total_strips": len(frame_plan)})
        except Exception as e:
            return {"error": f"image_frame_preflight_failed: {e}", "complete": False}

    # Select device conservatively.
    # On shared 6 GB GPUs, low free VRAM can still OOM even with the smaller model,
    # so force CPU fallback unless there is comfortable headroom.
    device = cli_options["device"] if strict else "cpu"
    model_name = cli_options["model_path"] if strict else "microsoft/trocr-base-handwritten"

    if strict and device == "cuda":
        try:
            if not torch.cuda.is_available() or torch.cuda.mem_get_info(0)[0] <= 2 * 1024 * 1024 * 1024:
                return {"error": "selected_cuda_unavailable", "device": device}
        except Exception:
            return {"error": "selected_cuda_unavailable", "device": device}
    elif not strict and torch.cuda.is_available() and not force_cpu:
        try:
            free_vram, _total_vram = torch.cuda.mem_get_info(0)
            if free_vram > 2 * 1024 * 1024 * 1024:
                device = "cuda"
                model_name = "microsoft/trocr-base-handwritten"
        except Exception:
            device = "cpu"
            model_name = "microsoft/trocr-base-handwritten"

    try:
        processor_options = {"local_files_only": True, "trust_remote_code": False}
        model_options = {**processor_options, "use_safetensors": True} if strict else dict(processor_options)
        processor = TrOCRProcessor.from_pretrained(model_name, **processor_options)
        model = VisionEncoderDecoderModel.from_pretrained(model_name, **model_options).to(device)
        model.eval()
    except Exception as e:
        if not strict and device == "cuda" and is_cuda_compatibility_error(str(e)):
            return transcribe(image_path, force_cpu=True)
        return {"error": f"model_load_failed: {e}"}

    lines = []
    current_frame = None
    img = None
    if framed:
        strips = frame_plan
    else:
        try:
            if strict:
                img = Image.open(image_path).convert("RGB")
            else:
                # Count coverage without extending ordinary inference to other
                # frames or to strips excluded by its established limits.
                with Image.open(image_path) as original:
                    source_frame_count = getattr(original, "n_frames", 1)
                    source_total_strips = 0
                    for frame in range(source_frame_count):
                        original.seek(frame)
                        width, height = original.size
                        strip_height = max(80, height // max(1, height // 120)) if height > width * 2 and height > 200 else height
                        source_total_strips += len(range(0, height, strip_height))
                    original.seek(0)
                    img = original.convert("RGB")
        except Exception as e:
            return {"error": f"image_open_failed: {e}"}
        # Preserve ordinary behavior; strict mode keeps the final tiny strip.
        width, height = img.size
        if height > width * 2 and height > 200:
            strip_height = max(80, height // max(1, height // 120))
            strips = []
            for y in range(0, height, strip_height):
                strip = img.crop((0, y, width, min(y + strip_height, height)))
                if strict or strip.height > 20:
                    strips.append(strip)
        else:
            strips = [img]

    confidences = []
    segments = []
    token_limit = cli_options["max_new_tokens"] if strict else 128
    eos_ids = getattr(getattr(model, "generation_config", None), "eos_token_id", None)
    eos_ids = [eos_ids] if type(eos_ids) is int else eos_ids
    valid_eos = isinstance(eos_ids, list) and bool(eos_ids) and all(type(token) is int for token in eos_ids)
    if strict:
        if not valid_eos:
            return {"error": "selected_model_has_no_explicit_eos_contract", "device": device}

    termination_reasons = []
    processed_strips = 0
    for index, strip in enumerate(strips if strict else strips[:20]):
        try:
            frame_position = strip if framed else {}
            if framed:
                if current_frame != frame_position["frame_number"]:
                    if img is not None:
                        img.close()
                    with Image.open(image_path) as original:
                        original.seek(frame_position["frame_number"] - 1)
                        img = original.convert("RGB")
                    current_frame = frame_position["frame_number"]
                strip = img.crop((0, frame_position["top"], frame_position["width"], frame_position["bottom"]))
            pixel_values = processor(images=strip, return_tensors="pt").pixel_values.to(device)

            with torch.no_grad():
                generated_ids = model.generate(
                    pixel_values,
                    max_new_tokens=token_limit,
                    output_scores=True,
                    return_dict_in_generate=True,
                )

            sequence_ids = generated_ids.sequences
            if strict:
                token_ids = sequence_ids[0].tolist()
                segment = {"index": index, "text": None, "token_ids": token_ids,
                           "complete": False, "width": strip.width, "height": strip.height}
                if framed:
                    segment.update({k: frame_position[k] for k in ["frame_number", "frame_strip_index", "top", "bottom"]})
                segments.append(segment)
                if progress is not None:
                    progress({"schema": "plos.cli.htr-segment.v2" if framed else "plos.cli.htr-segment.v1", "stage": "generated", **segment})
            text = processor.batch_decode(sequence_ids, skip_special_tokens=True)[0]
            if not strict:
                text = text.strip()
                token_ids = sequence_ids[0].tolist()
                processed_strips += 1
                if not valid_eos:
                    termination_reasons.append("missing_eos_contract")
                elif token_ids and token_ids[-1] in eos_ids:
                    termination_reasons.append("eos")
                elif len(getattr(generated_ids, "scores", [])) >= token_limit:
                    termination_reasons.append("token_limit")
                else:
                    termination_reasons.append("non_eos")
            if strict:
                complete = bool(token_ids) and token_ids[-1] in eos_ids
                segment.update({"text": text, "complete": complete})
                if progress is not None:
                    progress({"schema": "plos.cli.htr-segment.v2" if framed else "plos.cli.htr-segment.v1", "stage": "decoded", **segment})
                if not complete:
                    return {"error": "incomplete_generation", "complete": False, "model": model_name,
                            "device": device, "max_new_tokens": token_limit, "segments": segments,
                            "text": decoded_text(segments, framed), "processed_strips": len(segments),
                            "total_strips": len(strips), **frame_metadata}

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

            if strict or text:
                lines.append(text)
                confidences.append(min(conf, 1.0))

        except Exception as e:
            if not strict and device == "cuda" and is_cuda_compatibility_error(str(e)):
                try:
                    del model
                    torch.cuda.empty_cache()
                except Exception:
                    pass

                return transcribe(image_path, force_cpu=True)
            if strict:
                return {"error": f"strip_transcription_failed: {e}", "complete": False,
                        "model": model_name, "device": device, "segments": segments,
                        "text": decoded_text(segments, framed), "processed_strips": len(segments),
                        "total_strips": len(strips), **frame_metadata}
            return {"error": f"strip_transcription_failed: {e}", "text": "\n".join(lines),
                    "lines": lines, "complete": False, "termination": "error",
                    "processed_strips": processed_strips, "total_strips": source_total_strips,
                    "source_frame_count": source_frame_count, "max_new_tokens": token_limit}

    full_text = decoded_text(segments, True) if framed else "\n".join(lines)
    avg_conf = sum(confidences) / len(confidences) if confidences else 0.0

    result = {
        "text": full_text,
        "lines": lines,
        "model": model_name,
        "device": device,
        "confidence": round(avg_conf, 4),
        "line_count": len(lines),
    }
    if strict:
        result.update({"schema": "plos.cli.htr-result.v2" if framed else "plos.cli.htr-result.v1", "complete": True, "segments": segments,
                       "total_strips": len(strips), "processed_strips": len(segments),
                       "max_new_tokens": token_limit, "fallback_used": False})
        if framed:
            result.update({**frame_metadata, "frame_texts": frame_texts(segments)})
    else:
        termination = next((reason for reason in termination_reasons if reason != "eos"), "eos")
        if processed_strips != source_total_strips:
            termination = "partial_coverage"
        complete = processed_strips == source_total_strips and processed_strips > 0 and termination == "eos"
        result.update({"complete": complete, "termination": termination,
                       "total_strips": source_total_strips, "processed_strips": processed_strips,
                       "source_frame_count": source_frame_count, "max_new_tokens": token_limit})
    return result


def main():
    try:
        raw = sys.stdin.read().strip()
        data = json.loads(raw)
        image_path = data.get("image_path", "")

        if not image_path:
            print(json.dumps({"error": "no_image_path provided"}))
            return

        if "cli" in data and not isinstance(data["cli"], dict):
            print(json.dumps({"error": "invalid_cli_htr_contract"}))
            return
        progress = (lambda event: print(json.dumps(event, ensure_ascii=False), flush=True)) if "cli" in data else None
        result = transcribe(image_path, cli_options=data.get("cli"), progress=progress)
        print(json.dumps(result))

    except json.JSONDecodeError:
        print(json.dumps({"error": "invalid_json_input"}))
    except Exception as e:
        print(json.dumps({"error": str(e)}))


if __name__ == "__main__":
    main()
