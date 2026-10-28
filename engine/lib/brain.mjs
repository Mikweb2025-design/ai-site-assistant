// The "brain": any OpenAI-compatible chat endpoint. Defaults to a LOCAL Ollama (http://127.0.0.1:11434/v1), so by default
// no text leaves your machine. Point ASSISTANT_BRAIN_URL / _MODEL / _KEY at a hosted service if you prefer.
//   ASSISTANT_BRAIN=openai (default) | demo   ("demo" answers with a fixed sentence: handy to test the plumbing without any model)
const KIND = process.env.ASSISTANT_BRAIN || 'openai';
const URL_BASE = (process.env.ASSISTANT_BRAIN_URL || 'http://127.0.0.1:11434/v1').replace(/\/+$/, '');
const MODEL = process.env.ASSISTANT_BRAIN_MODEL || 'qwen2.5:3b';
const KEY = process.env.ASSISTANT_BRAIN_KEY || 'ollama';

export const brainName = () => (KIND === 'demo' ? 'demo (no model)' : `${MODEL} @ ${URL_BASE}`);

/** @param {{role:string, content:string}[]} history  @returns {Promise<string>} */
export async function think(systemPrompt, history, { signal } = {}) {
  if (KIND === 'demo') return `This is the demo brain. You said: "${history.at(-1)?.content.slice(0, 120)}".`;
  const r = await fetch(`${URL_BASE}/chat/completions`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${KEY}` },
    body: JSON.stringify({ model: MODEL, temperature: 0.5, max_tokens: 400, messages: [{ role: 'system', content: systemPrompt }, ...history] }),
    signal: signal || AbortSignal.timeout(60000),
  });
  if (!r.ok) throw new Error(`brain HTTP ${r.status}`);
  const j = await r.json();
  // some reasoning models wrap their thinking in <think>…</think>: never show it
  return String(j.choices?.[0]?.message?.content || '').replace(/<think>[\s\S]*?<\/think>/g, '').trim();
}
