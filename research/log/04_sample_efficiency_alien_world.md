# Research Log: Cycle 4 — Sample-Efficiency on Alien World & Fast Adaptation

## HYPOTHESIS
1. In a novel world with alien vocabulary and mechanics (unseen by any pretrained foundation model), an active grounded learner (bipartite continuity + contrastive PPMI) achieves high compositional accuracy with orders of magnitude fewer demonstrations than online gradient descent (SGD) or nearest-neighbor instance memory.
2. Fast rule reversal requires local lateral inhibition / negative evidence update; pure additive co-occurrence fails to adapt within reasonable interactions.

## THE BENCHMARK: THE GLYPHIC GRID WORLD
- 4 alien forms: `[alpha, beta, gamma, delta]` mapped to novel tokens `[zarv, thok, plen, kren]`.
- 4 alien lattice cells: `[cell_X, cell_Y, cell_Z, cell_W]` mapped to novel tokens `[miv, jup, vorg, drel]`.
- 16 combinations (12 train, 4 held-out evaluation pairs).
- Demonstration budgets: $B \in [0, 1, 2, 5, 10, 25, 50, 100]$.
- 5 random seeds per condition.

## EMPIRICAL RESULTS (5 Seeds Mean)
### 1. Held-Out Compositional Accuracy:
| Model | B=0 | B=1 | B=2 | B=5 | B=10 | B=25 | B=50 | B=100 |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| **HARI-Step1** | **0.00** | **0.00** | **0.00** | **0.50** | **0.60** | **0.90** | **1.00** | **1.00** |
| Online SGD | 0.00 | 0.00 | 0.00 | 0.00 | 0.00 | 0.00 | 0.00 | 0.00 |
| Nearest Neighbor | 0.00 | 0.00 | 0.00 | 0.00 | 0.00 | 0.00 | 0.00 | 0.00 |

### 2. Confident False Action Rate (ACT_WRONG):
| Model | B=0 | B=1 | B=2 | B=5 | B=10 | B=25 | B=50 | B=100 |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| **HARI-Step1** | 0.00 | 0.00 | 0.30 | 0.05 | 0.30 | 0.05 | **0.00** | **0.00** |
| Online SGD | 0.00 | 0.00 | 0.00 | 0.00 | 0.00 | 0.00 | 0.00 | 0.00 |
| Nearest Neighbor | 0.00 | 0.00 | 0.00 | 0.00 | 0.00 | 0.00 | 0.00 | 0.00 |

### 3. Safe Refusal Rate (ASK):
| Model | B=0 | B=1 | B=2 | B=5 | B=10 | B=25 | B=50 | B=100 |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| **HARI-Step1** | 1.00 | 1.00 | 0.70 | 0.45 | 0.10 | 0.05 | 0.00 | 0.00 |
| Online SGD | 1.00 | 1.00 | 1.00 | 1.00 | 1.00 | 1.00 | 1.00 | 1.00 |
| Nearest Neighbor | 1.00 | 1.00 | 1.00 | 1.00 | 1.00 | 1.00 | 1.00 | 1.00 |

## RULE REVERSAL ADAPTATION
- When a rule reverses (`zarv` previously meant `alpha` 25 times; teacher now demonstrates `zarv` $\to$ `beta`):
  - Without lateral inhibition, pure additive counts require $\ge 25$ additional counter-examples to dilute out the old association.
  - With local lateral inhibition ($\times 0.10$ suppression on conflicting slots during correction), the model adapts in **exactly 1 correction** step!

## KEY FINDING
HARI-Step1 achieves 90% held-out compositional accuracy on an entirely novel world within 25 demonstrations, and 100% within 50 demonstrations, where standard online gradient descent and nearest neighbor memory fail to form confident predictions.
