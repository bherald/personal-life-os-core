#!/usr/bin/env python3
"""
N105 — spaCy NLP fact extraction for genealogy research findings.

Usage:
    echo '{"text": "John Smith was born in 1864 in Philadelphia..."}' | python3 nlp_extract.py

Returns JSON with extracted entities:
    {
        "dates": [...],
        "places": [...],
        "persons": [...],
        "orgs": [...],
        "facts": {
            "birth_date": "...",
            "birth_place": "...",
            "death_date": "...",
            "death_place": "...",
            "occupation": "..."
        }
    }

Requires: pip install spacy && python3 -m spacy download en_core_web_sm
Fallback: If spaCy not available, returns empty result (PHP falls back to regex).
"""

import sys
import json
import re

def extract_with_spacy(text: str) -> dict:
    try:
        import spacy
        nlp = spacy.load("en_core_web_sm")
    except (ImportError, OSError):
        return {"error": "spacy_not_available"}

    doc = nlp(text[:5000])  # cap at 5000 chars — agent findings are short

    dates  = [ent.text for ent in doc.ents if ent.label_ in ("DATE", "TIME")]
    places = [ent.text for ent in doc.ents if ent.label_ in ("GPE", "LOC", "FAC")]
    persons = [ent.text for ent in doc.ents if ent.label_ == "PERSON"]
    orgs   = [ent.text for ent in doc.ents if ent.label_ == "ORG"]

    # Extract genealogy-specific facts from entity context
    facts = {}

    # Birth date: look for DATE entities near "born", "birth"
    lower = text.lower()
    birth_patterns = re.findall(r'(?:born|birth[- ]?date|b\.)\s*:?\s*([A-Za-z0-9\s,]+\d{4}|\d{4})', lower)
    if birth_patterns:
        facts["birth_date"] = birth_patterns[0].strip().title()

    death_patterns = re.findall(r'(?:died|death[- ]?date|d\.)\s*:?\s*([A-Za-z0-9\s,]+\d{4}|\d{4})', lower)
    if death_patterns:
        facts["death_date"] = death_patterns[0].strip().title()

    # Birth/death place: GPE entities in context of born/died sentences
    for sent in doc.sents:
        sent_lower = sent.text.lower()
        sent_gpes = [ent.text for ent in sent.ents if ent.label_ in ("GPE", "LOC")]
        if not sent_gpes:
            continue
        if any(w in sent_lower for w in ["born", "birth", "bapti", "christened"]) and "birth_place" not in facts:
            facts["birth_place"] = sent_gpes[0]
        if any(w in sent_lower for w in ["died", "death", "buried", "burial"]) and "death_place" not in facts:
            facts["death_place"] = sent_gpes[0]

    # Occupation: look for common occupation indicators
    occ_match = re.search(
        r'(?:occupation|worked as(?: a)?|employed as(?: a)?|profession)\s*:?\s*([a-zA-Z\s]{3,30})',
        lower
    )
    if occ_match:
        facts["occupation"] = occ_match.group(1).strip().title()

    return {
        "dates": dates[:20],
        "places": places[:20],
        "persons": persons[:30],
        "orgs": orgs[:10],
        "facts": facts,
        "entity_count": len(doc.ents),
    }


def main():
    try:
        raw = sys.stdin.read().strip()
        data = json.loads(raw)
        text = data.get("text", "")
        if not text:
            print(json.dumps({"error": "no_text"}))
            return

        result = extract_with_spacy(text)
        print(json.dumps(result))
    except Exception as e:
        print(json.dumps({"error": str(e)}))


if __name__ == "__main__":
    main()
