# Research Log: Cycle 1 — Stage A & Stage B (Raw World Diff Grounding & Correlation Attacks)

## HYPOTHESIS
An online learner receiving only raw world state diffs (without SemanticFrame, role tags, or effect templates) can discover latent entity and action associations via local co-occurrence (Jaccard association) and generalize to held-out compositions in novel worlds.

## WHY IT MAY WORK
In a physical transition, only a subset of attributes change (the delta), while the intrinsic identity attributes of the manipulated object remain invariant. Words that consistently co-occur with invariant attributes must refer to the entity, while words co-occurring with post-transition states must refer to destinations/outcomes.

## CHEAPEST FALSIFICATION
1. Can simple baselines (ExactMemorization, NearestNeighbor, NaiveBayes) match or exceed the learner?
2. Does the learner fail when semantic labels are replaced by opaque feature dimensions (Stage B)?
3. Adversarial correlation attack: If two object features (e.g. dim0 and dim1, shape and color) are perfectly correlated during training, what happens when a test object has a novel color and a distractor has the correlated color? Does the learner make a confident false ACT or recognize ambiguity?

## BASELINES
1. ExactMemorization (replay delta on exact utterance match)
2. NearestNeighborMemory (retrieve closest utterance in Jaccard distance)
3. NaiveBayesSlotLearner (conditional frequency table)
4. DeltaRuleOpaqueLearner (online error-driven Widrow-Hoff linear weights)

## RESULT
- **Stage A (5 seeds):**
  - ExactMemorization: 0.0% accuracy (safely ASKs).
  - NearestNeighbor: 0.0% accuracy (safely ASKs).
  - NaiveBayesSlotLearner: 100.0% accuracy (4/4 held-out cases).
  - AssociativeDiffLearner: 100.0% accuracy (4/4 held-out cases).
  *Finding:* A 30-line Naive Bayes baseline matches the associative learner on Stage A.

- **Stage B (Opaque Discrete Dimensions, 5 seeds):**
  - Standard held-out composition: Both Associative (Jaccard) and Delta-Rule achieve 100.0% accuracy.
  - Opaque discrete features do not disrupt compositionality.

## ADVERSARIAL ATTACK (Correlated Dimensions)
- When dim0 and dim1 are 100% correlated in training, testing against a target with novel dim1 and a distractor with the correlated dim1:
  - `Delta_Rule`: Scores are tied (0.3299 vs 0.3299). It blindly executes on whichever entity appears first in memory iteration order, producing a **confident false action (ACT_WRONG with confidence = 0.990)** when the distractor is listed first.
  - `Associative_Jaccard`: Detects that topEntityScore (0.33) and secondEntityScore (0.33) fall within the ambiguity margin ($|\Delta| < 0.08$), and **safely refuses to act (ASK = 4/4, ACT_WRONG = 0)**.

## WHAT ACTUALLY FAILED
1. Passive observation *cannot* mathematically disambiguate perfectly correlated features. Any learner claiming to "know" which one is the true referent without intervention or disambiguating data is committing shortcut learning or exploiting dictionary ordering.
2. Naive error-driven linear models lack calibrated epistemic uncertainty and commit confident errors on tied evidence.

## NEXT HYPOTHESIS (Cycle 2: Stage C — Temporal Continuity Without Entity IDs)
In real perception, objects do not arrive with persistent ID labels (`e1`, `e2`). If we strip entity IDs from the observation, can a learner infer temporal continuity (solve the bipartite matching problem between pre- and post-transition entity sets) purely from sensory feature overlap, and still isolate the causal delta?
