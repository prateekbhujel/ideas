# Research Log: Cycle 3 — Stage F, G & H (Hidden State, Active Interventions, & Active Questioning)

## HYPOTHESIS
1. **Stage F & G:** Passive correlation learners cannot distinguish common-cause confounders from true causation. An agent equipped with an action repertoire and an Expected Information Gain (EIG) decision rule can choose interventions $do(X)$ that falsify spurious causal models in $O(1)$ steps.
2. **Stage H:** When linguistic tokens are confounded between multiple perceptual properties, an active learner can rank candidate teacher queries by EIG to pick decorrelated test objects, resolving ambiguity with minimal teacher burden.

## MECHANISMS
- `ConfoundedWorld`: Hidden state $H$ triggers Sound $S$ and Light $L$. Observationally $P(L=1 \mid S=1) = 1.0$, but intervening $do(S=1)$ severs the link from $H$ to $S$.
- `ActiveCausalLearner`: Computes prior entropy minus expected posterior entropy for candidate actions:
  $$\text{EIG}(a) = H(\Theta) - \mathbb{E}_{y \sim P(y \mid do(a))} [H(\Theta \mid y, do(a))]$$
  Chooses $\arg\max_a \text{EIG}(a)$.
- `ActiveQuerySelector`: Evaluates candidate objects to ask the teacher about, prioritizing objects that satisfy one hypothesis and violate the other ($\text{EIG} = 1.0\text{ bit}$).

## EMPIRICAL FINDINGS (5 Runs)
1. **Passive vs Active Causal Inference:**
   - Passive Learner: In 5/5 runs concluded **"Sound causes light (p=1.00)"**—a confident false causal assertion.
   - Active Agent: Calculated $\text{EIG}(\text{observe}) = 0.000$ bits vs $\text{EIG}(\text{intervene}) = 0.396$ bits. Actively intervened $do(S=1)$, observing that light remained off. In 1 to 2 interventions across all 5 runs, posterior probability of the spurious hypothesis dropped to **0.000** ($P(\text{Confounded}) \to 1.000$).
2. **Active Information-Seeking Queries:**
   - Discriminator queries (`blue_ball`, `red_cup`): $\text{EIG} = 1.00$ bit (resolves ambiguity in exactly 1 question).
   - Confounded query (`red_ball`): $\text{EIG} = 0.00$ bit (teacher answers YES under both hypotheses, learning nothing).
   - Irrelevant query (`blue_cup`): $\text{EIG} = 0.00$ bit (teacher answers NO under both hypotheses, learning nothing).

## ARCHITECTURAL LESSON
This is a sharp, fundamental dividing line between passive next-token predictors (LLMs) and active agents. No amount of passive internet text scraping can compute $P(Y \mid do(X))$ without unconfounded natural experiments in the corpus. An interactive physical agent can resolve in a single intervention what an infinite stream of passive co-occurrence observations cannot.

## NEXT STEP (Cycle 4: Sample Efficiency vs Pretrained Baselines & Unified Lab Architecture)
Now bring together the proven components:
1. Anonymous bipartite continuity tracker (Stage C)
2. Contrastive PMI association (Stage D & E)
3. Active intervention / query selection (Stage G & H)
Evaluate sample-efficiency curves across budgets [0, 1, 2, 5, 10, 25, 50, 100 demonstrations] on completely novel environments.
