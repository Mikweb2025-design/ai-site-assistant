/* mic-worklet.js — AudioWorklet that turns the microphone into 20 ms frames of 8 kHz mono floats (160 samples),
   the format the engine protocol expects. Area-averaging resampler: every output sample is the mean of the input
   samples that fall inside its 125 µs window (a box filter, which also acts as the anti-alias filter). */
class MicCapture extends AudioWorkletProcessor {
  constructor() {
    super();
    this.step = sampleRate / 8000;      // input samples per output sample (may be fractional, e.g. 5.5125 for 44.1 kHz)
    this.acc = 0;                       // running sum of the current output window
    this.weight = 0;                    // how much of the window is already filled (in input samples)
    this.frame = new Float32Array(160);
    this.n = 0;
  }
  process(inputs) {
    const ch = inputs[0] && inputs[0][0];
    if (!ch) return true;
    for (let i = 0; i < ch.length; i++) {
      let v = ch[i], w = 1;
      while (this.weight + w >= this.step) {      // this input sample completes the window (maybe with a remainder)
        const used = this.step - this.weight;
        this.acc += v * used;
        this.frame[this.n++] = this.acc / this.step;
        if (this.n === 160) { this.port.postMessage(this.frame.slice()); this.n = 0; }
        w -= used; this.weight = 0; this.acc = 0;
      }
      this.acc += v * w; this.weight += w;
    }
    return true;
  }
}
registerProcessor("cattura", MicCapture);
