# Roadmap — the brain that never leaves your machine

Today the engine's brain is any OpenAI-compatible chat endpoint (`engine/lib/brain.mjs`). By default that is a **local Ollama**,
so with the reference engine no text leaves your computer. The author's own deployment still uses a hosted model (Claude, through
Claude Code) for the live phone-speed conversation, because small local models were not good enough *at voice latency* — that is the
gap this project wants to close.

## Measured on an Apple-silicon Mac (reference engine, real site text as knowledge, 3 questions each)

| model (Ollama)                  | first answer (cold) | warm answers | quality notes |
|---------------------------------|---------------------|--------------|---------------|
| MiniCPM 2B (q4_K_M)             | ~19 s (one empty)   | 2–6 s        | correct German answer, correctly refused an off-topic question |
| 4B class (spark-x2.5 q4)        | > 30 s (timed out)  | > 30 s       | too slow with a long system prompt on this machine |
| 12B class (gemma4, mlx)         | > 30 s (timed out)  | > 30 s       | too slow for a conversation here |

(These are honest, small, single-machine numbers, not a benchmark. Re-run `scripts/bench-brain.sh` on your hardware.)

## What "done" looks like

* A local model that answers a spoken question about the site **in under ~1.5 s to the first sentence**, in German, English and Italian,
  without inventing facts and while keeping the rules (no promises, no private data, honest about being an AI).
* Streaming from brain to TTS sentence by sentence (the live engine already does this), so speech starts before the answer is complete.
* Prompt shaping for small models: shorter system prompt, retrieval of only the relevant knowledge chunk instead of the whole page text.
* Optional: a tiny classifier in front, so greetings / thanks / goodbyes never reach the big model.
* Everything on one Apple-silicon box (MLX): STT → brain → TTS, no network except the PHP backend.

## Other ideas

* Pluggable STT/TTS adapters so the voice part is not tied to one stack.
* Calendar write-back to other CalDAV servers and a Google Calendar adapter.
* A small evaluation harness: a list of questions with expected facts and "must refuse" cases, scored automatically per model.
