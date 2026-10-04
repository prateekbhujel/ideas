# Research Log: Cycle 2 — Stage C, D & E (Continuity, Simultaneous Deltas, & Ambient Distractors)

## HYPOTHESIS
1. **Stage C:** An agent can infer temporal object continuity without persistent entity IDs by solving bipartite feature-similarity matching across observation frames $t_0 \to t_1$, isolating the target delta.
2. **Stage D & E:** When multiple simultaneous changes and frequent background distractors occur, standard co-occurrence accumulates spurious associations. Contrastive Pointwise Mutual Information (PPMI) can filter ambient noise because distractors are conditionally independent of specific linguistic tokens.

## MECHANISMS
- `BipartiteContinuityTracker`: Maximizes feature constancy between unordered object sets before and after. Mismatched attributes in paired entities constitute candidate causal deltas.
- `ContrastivePmiLearner`: Measures $\text{PPMI}(t, f) = \max\left(0, \ln \frac{P(t, f)}{P(t)P(f)}\right)$. Features with high baseline marginal probability $P(f)$ (like ambient environmental noise) have ratio $\approx 1 \implies \text{PPMI} = 0$.

## EMPIRICAL FINDINGS (5 Seeds)
1. **Stage C (Anonymous Objects, 5 seeds):**
   - Held-out composition accuracy: **100.0%** across all 5 seeds (4/4 held-out cases).
   - False ACT: **0.0%**.
   - Twin Ambiguity Attack (two identical balls in the world): **100.0% safe refusal (ASK)** across all 5 seeds. No false actions when target identity is genuinely under-specified.
2. **Stage D & E (Simultaneous Changes & Environmental Noise):**
   - In the presence of a background lamp flickering during 70% of trials:
     - Naive diff learner accumulated spurious weight `mako -> dest:state=off` (score = 0.167).
     - Contrastive PMI reduced the spurious association to **exactly 0.000**, while preserving the true causal link `mako -> dest:loc=table` ($\text{PPMI} = 0.693$).

## WHAT ACTUALLY FAILED
- Bipartite matching fails if two objects swap positions simultaneously and have identical static features (permutation symmetry).
- PPMI requires observing the distractor across varying utterances to estimate $P(f)$ accurately. If an ambient event is 100% correlated with only one specific token, observational data alone cannot separate them.

## NEXT STEP (Cycle 3: Stage F & G — Hidden State, Correlation vs Causation, and Active Interventions)
Can an agent move beyond passive observation to active intervention (Stage G)? When observational correlations are confounded, can the learner intervene (take actions) to determine true causal influence?
