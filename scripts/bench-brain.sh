#!/bin/bash
# Compare local models as the assistant's brain: ./scripts/bench-brain.sh llama3.1:8b qwen2.5:3b ...
# Needs Ollama running and ASSISTANT_OWNER / ASSISTANT_KNOWLEDGE set (see README).
cd "$(dirname "$0")/.."
export ASSISTANT_ORIGINS="${ASSISTANT_ORIGINS:-http://localhost:8080}" PORT=5099
for M in "$@"; do
  ASSISTANT_BRAIN_MODEL="$M" node mock-engine/server.mjs > /dev/null 2>&1 &
  PID=$!; sleep 2; echo "=== $M"
  for Q in "Who is this site about, and what do they do?" "Ist die Person offen für neue Stellen?" "Give me a good carbonara recipe."; do
    echo "Q: $Q"; node scripts/ask.mjs "$Q" en ws://127.0.0.1:5099/guest/ws | cut -c1-240
  done
  kill $PID 2>/dev/null; sleep 1
done
