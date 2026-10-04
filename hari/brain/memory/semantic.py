"""
HARI Semantic Memory & Grounded Associative Concept Store.
Directly implements our Stage A-H empirical findings:
- Unsupervised raw sensory-diff token-feature bindings
- Contrastive Positive Pointwise Mutual Information (PPMI)
- Local lateral inhibition (x0.10) for 1-shot rule reversal without global retraining
- Dual-timescale recency decay
- Strict O(1) resource ceilings (1024 tokens, 1024 features)
- Checksummed persistence to external SSD
"""

import math
import time
import json
import hashlib
from pathlib import Path
from typing import Dict, List, Optional, Any, Tuple
from hari.core.config import (
    MAX_SEMANTIC_TOKENS,
    MAX_SEMANTIC_FEATURES,
    STATE_DIR,
)

class SemanticMemory:
    def __init__(
        self,
        max_tokens: int = MAX_SEMANTIC_TOKENS,
        max_features: int = MAX_SEMANTIC_FEATURES,
        decay_half_life: float = 10000.0,
    ):
        self.max_tokens = max_tokens
        self.max_features = max_features
        self.decay_half_life = decay_half_life
        self.step: int = 0
        self.total_events: float = 0.0

        # token -> {'count': float, 'last': int, 'pairs': {feature: {'count': float, 'last': int}}}
        self.tokens: Dict[str, Dict[str, Any]] = {}
        # feature -> {'count': float, 'last': int}
        self.features: Dict[str, Dict[str, Any]] = {}
        # Explicit user-taught key-value facts (e.g. "project name" -> "Ground")
        self.facts: Dict[str, Dict[str, Any]] = {}

    def _decay(self, count: float, last_step: int) -> float:
        delta = max(0, self.step - last_step)
        if delta == 0 or count <= 0.0:
            return count
        return count * (0.5 ** (delta / self.decay_half_life))

    @staticmethod
    def tokenize(text: str) -> List[str]:
        cleaned = "".join(ch.lower() if ch.isalnum() or ch.isspace() else " " for ch in text)
        toks = cleaned.strip().split()
        # Keep unique in order
        seen = set()
        out = []
        for t in toks:
            if t not in seen and len(t) > 0:
                seen.add(t)
                out.append(t)
        return out

    def ground_experience(
        self,
        utterance: str,
        sensory_features: List[Tuple[str, str]],  # [(feature_key, category)] e.g. [("ent:color=red", "ent:color")]
        is_correction: bool = False
    ) -> Dict[str, Any]:
        """Learn associations between linguistic tokens and perceptual features."""
        tokens = self.tokenize(utterance)
        if not tokens or not sensory_features:
            return {"status": "ignored", "tokens": len(tokens)}

        strength = 3.0 if is_correction else 1.0
        self.step += 1
        self.total_events += strength

        # 1. Update feature marginals
        for feat_key, _ in sensory_features:
            entry = self.features.get(feat_key, {"count": 0.0, "last": self.step})
            entry["count"] = self._decay(entry["count"], entry["last"]) + strength
            entry["last"] = self.step
            self.features[feat_key] = entry

        # 2. Update token marginals & co-occurrences
        for tok in tokens:
            t_entry = self.tokens.get(tok, {"count": 0.0, "last": self.step, "pairs": {}})
            t_entry["count"] = self._decay(t_entry["count"], t_entry["last"]) + strength
            t_entry["last"] = self.step

            # Local Lateral Inhibition on Teacher Correction:
            # Suppress conflicting feature associations in the exact same slot/category
            if is_correction and t_entry["pairs"]:
                for truth_feat, category in sensory_features:
                    for old_feat, p_data in list(t_entry["pairs"].items()):
                        if old_feat != truth_feat and old_feat.startswith(category):
                            p_data["count"] = self._decay(p_data["count"], p_data["last"]) * 0.10
                            p_data["last"] = self.step

            # Reinforce true co-occurrences
            for feat_key, _ in sensory_features:
                p_entry = t_entry["pairs"].get(feat_key, {"count": 0.0, "last": self.step})
                p_entry["count"] = self._decay(p_entry["count"], p_entry["last"]) + strength
                p_entry["last"] = self.step
                t_entry["pairs"][feat_key] = p_entry

            self.tokens[tok] = t_entry

        self._enforce_caps()
        return {"status": "learned", "tokens": len(tokens), "features": len(sensory_features)}

    def ppmi(self, token: str, feature: str) -> float:
        """Compute Positive Pointwise Mutual Information."""
        t_data = self.tokens.get(token)
        f_data = self.features.get(feature)
        if not t_data or not f_data or feature not in t_data["pairs"] or self.total_events <= 0.0:
            return 0.0

        tc = self._decay(t_data["count"], t_data["last"])
        fc = self._decay(f_data["count"], f_data["last"])
        p_data = t_data["pairs"][feature]
        pc = self._decay(p_data["count"], p_data["last"])

        if tc <= 0.0 or fc <= 0.0 or pc <= 0.0:
            return 0.0

        p_tf = pc / self.total_events
        p_t = tc / self.total_events
        p_f = fc / self.total_events

        ratio = p_tf / (p_t * p_f)
        return math.log(ratio) if ratio > 1.0 else 0.0

    def query_concepts_for_utterance(self, utterance: str) -> List[Dict[str, Any]]:
        """Returns top grounded feature associations across tokens in utterance."""
        tokens = self.tokenize(utterance)
        candidates: Dict[str, float] = {}
        provenance: Dict[str, Dict[str, Any]] = {}

        for tok in tokens:
            t_data = self.tokens.get(tok)
            if not t_data:
                continue
            for feat in t_data["pairs"]:
                val = self.ppmi(tok, feat)
                if val > candidates.get(feat, 0.0):
                    candidates[feat] = val
                    provenance[feat] = {"token": tok, "ppmi": val}

        sorted_cand = sorted(candidates.items(), key=lambda x: x[1], reverse=True)
        return [
            {"feature": feat, "score": score, "token": provenance[feat]["token"]}
            for feat, score in sorted_cand[:10]
        ]

    def store_fact(self, subject: str, predicate: str, value: Any, provenance: str = "") -> None:
        """Stores explicit semantic relation (e.g. 'project', 'name', 'Ground')."""
        key = f"{subject.lower().strip()}:{predicate.lower().strip()}"
        self.facts[key] = {
            "subject": subject,
            "predicate": predicate,
            "value": value,
            "provenance": provenance,
            "timestamp": time.time(),
        }

    def query_fact(self, subject: str, predicate: Optional[str] = None) -> Optional[Any]:
        subject_clean = subject.lower().strip()
        if predicate:
            key = f"{subject_clean}:{predicate.lower().strip()}"
            if key in self.facts:
                return self.facts[key]["value"]
        # Search all predicates for subject
        for k, v in self.facts.items():
            if k.startswith(f"{subject_clean}:"):
                return v["value"]
            if subject_clean in k:
                return v["value"]
        return None

    def _enforce_caps(self) -> None:
        if len(self.tokens) > self.max_tokens:
            sorted_t = sorted(
                self.tokens.items(),
                key=lambda x: self._decay(x[1]["count"], x[1]["last"]),
                reverse=True
            )
            self.tokens = dict(sorted_t[:self.max_tokens])

        if len(self.features) > self.max_features:
            sorted_f = sorted(
                self.features.items(),
                key=lambda x: self._decay(x[1]["count"], x[1]["last"]),
                reverse=True
            )
            self.features = dict(sorted_f[:self.max_features])

    def save(self, filepath: Optional[Path] = None) -> Path:
        target = filepath or (STATE_DIR / "semantic_memory.json")
        payload = {
            "step": self.step,
            "total_events": self.total_events,
            "tokens": self.tokens,
            "features": self.features,
            "facts": self.facts,
        }
        raw = json.dumps(payload, indent=2)
        checksum = hashlib.sha256(raw.encode("utf-8")).hexdigest()
        envelope = {"checksum": checksum, "data": payload}
        target.write_text(json.dumps(envelope, indent=2), encoding="utf-8")
        return target

    @classmethod
    def load(cls, filepath: Optional[Path] = None) -> "SemanticMemory":
        target = filepath or (STATE_DIR / "semantic_memory.json")
        if not target.exists():
            return cls()
        content = json.loads(target.read_text(encoding="utf-8"))
        checksum = content.get("checksum")
        payload = content.get("data", {})
        calc_checksum = hashlib.sha256(json.dumps(payload, indent=2).encode("utf-8")).hexdigest()
        if checksum and checksum != calc_checksum:
            raise ValueError("Semantic memory checksum mismatch - state corrupted!")

        mem = cls()
        mem.step = payload.get("step", 0)
        mem.total_events = payload.get("total_events", 0.0)
        mem.tokens = payload.get("tokens", {})
        mem.features = payload.get("features", {})
        mem.facts = payload.get("facts", {})
        return mem

    def stats(self) -> Dict[str, Any]:
        total_pairs = sum(len(t["pairs"]) for t in self.tokens.values())
        return {
            "tokens_count": len(self.tokens),
            "features_count": len(self.features),
            "pairs_count": total_pairs,
            "facts_count": len(self.facts),
            "step": self.step,
            "total_events": self.total_events,
        }
