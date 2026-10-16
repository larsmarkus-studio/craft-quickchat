# Generates src/web/ding.wav: a soft two-note chime (E6 then A6). Stdlib only.
import math, struct, wave, sys

RATE = 16000
out = sys.argv[1] if len(sys.argv) > 1 else 'src/web/ding.wav'
samples = []
for freq, start, length in [(1318.5, 0.0, 0.35), (1760.0, 0.12, 0.45)]:
    begin = int(start * RATE)
    n = int(length * RATE)
    while len(samples) < begin + n:
        samples.append(0.0)
    for i in range(n):
        t = i / RATE
        env = min(1.0, t / 0.005) * math.exp(-t * 9)  # 5 ms attack, quick decay
        samples[begin + i] += 0.3 * env * math.sin(2 * math.pi * freq * t)

with wave.open(out, 'wb') as w:
    w.setnchannels(1)
    w.setsampwidth(2)
    w.setframerate(RATE)
    w.writeframes(b''.join(struct.pack('<h', int(max(-1, min(1, s)) * 32767)) for s in samples))
