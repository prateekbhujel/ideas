# HARI Life MVP

This is the active HARI experiment.

It is deliberately **not** a transformer, not a pretrained model, and not a batch-training pipeline. During normal use it does not run backpropagation or ask for a GPU.

The MVP asks a narrower question:

> Can a fixed-budget system learn continually from sequential experience, form reusable meanings and programs, correct itself locally, retain useful knowledge, and know when it should ask instead of guessing?

## What is implemented

- **Fast episodic memory**: surprising/corrective events are stored immediately. The episode budget is fixed.
- **Adaptive language associations**: words become associated with semantic atoms through repeated grounded demonstrations. Counts decay lazyly and only touched structures are updated.
- **Schema learning**: HARI learns which roles an action normally requires.
- **Compositional inference**: learned pieces can be recombined in utterances that were never demonstrated.
- **Negation transfer**: a learned negation marker can compose with a new action/object combination.
- **One-shot concept introduction**: a new object can be introduced once and then reused inside another learned action.
- **Local correction**: an explicit correction suppresses conflicting local associations without replaying the entire history.
- **Program induction**: repeated action/outcome pairs are abstracted into executable effect patterns such as `location.{object}={destination}`.
- **Prediction error**: an experience is more surprising when current meaning or world prediction disagrees with the observation.
- **One-shot mutable facts**: facts change immediately without retraining the language mechanism.
- **Inspectable reasons**: `why` returns the actual associations used, not a generated chain-of-thought story.
- **Persistence**: state is checksum-protected and survives process restart.
- **Hard budgets**: tokens, token/meaning links, episodes, programs, and facts have fixed ceilings.

## Run it

```bash
php hari/life/demo.php
php hari/life/tests.php
php hari/life/adversarial.php
php hari/life/benchmark.php
php hari/life/cli.php
```

The interactive shell persists to `hari/life/hari-life-state.json` unless another state path is supplied:

```bash
php hari/life/cli.php /tmp/my-hari.json
```

### Teach a tiny language

```text
teach mako lumi mesa => MOVE object=ball destination=table | location.ball=table
teach mako piko tara => MOVE object=cup destination=shelf | location.cup=shelf
teach mako lumi tara => MOVE object=ball destination=shelf | location.ball=shelf
teach mako piko mesa => MOVE object=cup destination=table | location.cup=table
```

Then try:

```text
mako lumi tara
why mako lumi tara
```

Correction is explicit:

```text
correct mako nova mesa => MOVE object=phone destination=table | location.phone=table
```

Facts are immediate and separate from language learning:

```text
fact krishna.city=Kathmandu
recall krishna.city
fact krishna.city=Pokhara
recall krishna.city
```

## Current benchmark

`benchmark.php` deliberately trains on some combinations and tests on combinations that were never shown. It also compares against an exact-phrase memory baseline, tests cross-program one-shot transfer, local correction, unknown-input refusal, restart retention, and a long distractor stream that fills the token budget.

The benchmark writes JSON and exits non-zero if required properties fail:

```bash
php hari/life/benchmark.php --json=/tmp/hari-life-result.json
```

The numbers are **synthetic-world numbers**. They are not evidence that HARI understands English, Nepali, arbitrary human conversation, or the physical world.

## Resource contract

Current default hard ceilings:

- 1,024 surface tokens
- 48 token/meaning associations per token
- 256 episodic memories
- 256 induced programs
- 256 mutable facts

When the token budget fills, low-strength/old token structures are evicted. Episodes are compacted by a priority that favors corrections, surprise, and recent useful experience.

This is intentional: more lifetime experience is not allowed to imply unbounded RAM.

## What is *not* solved

- raw speech or vision
- real English/Nepali acquisition
- word order / morphology at human-language complexity
- social reasoning
- rich causal discovery
- planning over many steps
- autonomous real-world actions
- human-like memory consolidation
- proof that this architecture scales beyond small grounded worlds

The current lexical learner is still a statistical association mechanism over token/semantic co-occurrence. The program inducer uses a deliberately restricted unification rule. Those are mechanisms to test the lifecycle, not claims that we solved cognition.

## Why this is an MVP rather than a diagram

It is a persistent interactive program with a learning loop, resource limits, tests, adversarial tests, benchmark, inspectable state, restart behavior, and CI. You can teach it new vocabulary/concepts while it is running and immediately test generalization and correction.

The next research question is not “make it bigger.” It is: **which mechanism fails first when the world, language, ambiguity and lifetime get harder while the same resource ceiling remains?**
