"""
Continuously generates meditation audio segments driven by live olive-tree
dendrometer data, and drops them into /data/queue/ for the streamer to pick up.

Design notes (see project discussion):
- Drone bed: the FULL original recording (~14 min), with only its own 18
  original bowl-strike spots patched over with a clean excerpt -- the exact
  method already validated in meditation_new_v3.mp3. This loops every ~14
  minutes rather than being reassembled from separate windows, so the
  harmonic character never shifts (assets/drone_bed_full.flac).
- Bowl strikes: reused verbatim from 18 real recordings (assets/clips/*.flac),
  unmodified pitch, full ~31s decay tail. Each clip carries its own background
  drone from wherever in the original it was recorded, which would otherwise
  briefly override the (single, consistent) drone bed for its ~31s duration --
  so clips are high-pass filtered (removing everything below the bowl's own
  range) and ADDED on top of the drone, which itself never stops or changes.
- Timing: wait_time = activity_based_base(35-48s) * random_jitter(0.85-1.15).
  Activity = normalized rate-of-change of the dendrometer signal (Dendro_12).
- Never fully repeats: fresh random draw every cycle, no fixed seed.
- Resilient: API failures fall back to the last known-good data; a failed
  cycle just produces a drone-only segment and tries again next cycle.
"""
import os, sys, time, json, glob, traceback
import numpy as np
import soundfile as sf
import requests
from scipy.signal import butter, sosfiltfilt

ASSETS = "/app/assets"
DATA_DIR = "/data"
QUEUE_DIR = f"{DATA_DIR}/queue"
STATE_FILE = f"{DATA_DIR}/state.json"
STATUS_FILE = f"{DATA_DIR}/status.json"
CACHE_FILE = f"{DATA_DIR}/last_good_dendro.csv"

API_BASE = "https://companion.upgmbh.cloud/data/api/v1"
API_KEY = os.environ["DENDRO_API_KEY"]
LOGGER_SERIAL = os.environ.get("DENDRO_LOGGER", "865260083885479")  # Dendro_12
CHANNEL = "Dendrometer"

SR = 44100
CLIP_LEN = 31.0
CLIP_PRE = 1.0
EDGE_FADE = 0.3
HARD_MIN_WAIT = 10.0   # bowls are additive now (never replace the drone), so a
                       # little overlap between events is fine sonically --
                       # the original recording does this too in places
MIN_WAIT = 25.0
MAX_WAIT = 40.0
JITTER_LO, JITTER_HI = 0.85, 1.15

SEGMENT_LEN = 300.0     # seconds of audio rendered per cycle
CYCLE_SLEEP = 300.0     # seconds between cycles -- MUST equal SEGMENT_LEN so
                        # production exactly matches real-time playback speed
                        # (otherwise the queue backlog grows/shrinks without bound)
STARTUP_BUFFER_SEGMENTS = 3  # render this many segments immediately at startup
                             # (without waiting) to build a cushion against a
                             # slow/failed cycle later
KEEP_SEGMENTS = 6       # how many recent segments to retain on disk

os.makedirs(QUEUE_DIR, exist_ok=True)


def log(msg):
    print(f"[{time.strftime('%Y-%m-%d %H:%M:%S')}] {msg}", flush=True)


def load_state():
    if os.path.exists(STATE_FILE):
        with open(STATE_FILE) as f:
            return json.load(f)
    return {
        "seq": 0,
        "drone_phase": 0.0,       # sample-position offset into the drone loop
        "wait_remaining": 5.0,    # seconds until the next bowl event
        "clip_ptr": 0,            # round-robin pointer into the clip bank
    }


def save_state(state):
    tmp = STATE_FILE + ".tmp"
    with open(tmp, "w") as f:
        json.dump(state, f)
    os.replace(tmp, STATE_FILE)


def write_status(activity, next_wait, playing_clip_index=None):
    tmp = STATUS_FILE + ".tmp"
    with open(tmp, "w") as f:
        json.dump({
            "updated": time.time(),
            "activity": activity,
            "next_wait_hint": next_wait,
        }, f)
    os.replace(tmp, STATUS_FILE)


def fetch_dendro_data():
    """Fetch recent dendrometer readings; fall back to last known-good on failure."""
    end = time.strftime("%Y-%m-%dT%H:%M:%S.000Z", time.gmtime())
    start = time.strftime("%Y-%m-%dT%H:%M:%S.000Z", time.gmtime(time.time() - 3 * 3600))
    url = (f"{API_BASE}?start={start}&end={end}&loggers={LOGGER_SERIAL}"
           f"&channels={CHANNEL}&pivot=long")
    for attempt in range(3):
        try:
            r = requests.get(url, headers={"x-api-key": API_KEY}, timeout=10)
            r.raise_for_status()
            with open(CACHE_FILE, "w", encoding="utf-8") as f:
                f.write(r.text)
            return parse_csv(r.text)
        except Exception as e:
            log(f"API fetch attempt {attempt+1}/3 failed: {e}")
            time.sleep(2)
    log("API unreachable, falling back to last known-good cached data")
    if os.path.exists(CACHE_FILE):
        with open(CACHE_FILE, encoding="utf-8") as f:
            return parse_csv(f.read())
    return None


def parse_csv(text):
    import io, csv
    reader = csv.reader(io.StringIO(text), delimiter=";", quotechar='"')
    rows = list(reader)
    if not rows or len(rows) < 3:
        return None
    header = rows[0]
    ti, vi = header.index("time"), header.index("value")
    times, vals = [], []
    for row in rows[1:]:
        if len(row) <= max(ti, vi):
            continue
        try:
            t = time.strptime(row[ti][:19], "%Y-%m-%dT%H:%M:%S")
            v = float(row[vi].replace(",", "."))
            times.append(time.mktime(t))
            vals.append(v)
        except Exception:
            continue
    if len(times) < 2:
        return None
    order = np.argsort(times)
    return np.array(times)[order], np.array(vals)[order]


def current_activity(dendro):
    """Normalized 0-1 'how much is happening right now' from the last readings."""
    if dendro is None:
        return 0.0  # unknown -> treat as calm (longest, safest wait)
    times, vals = dendro
    if len(times) < 3:
        return 0.0
    gaps = np.diff(times)
    rates = np.abs(np.diff(vals)) / np.maximum(gaps, 1.0) * 60.0
    if rates.max() - rates.min() < 1e-9:
        return 0.0
    norm = (rates - rates.min()) / (rates.max() - rates.min())
    return float(norm[-1])  # most recent rate, normalized against recent history


def equal_power_fade(n, fade_in):
    t = np.linspace(0, 1, n)
    return np.sin(t * np.pi / 2) if fade_in else np.cos(t * np.pi / 2)


CLIP_HIGHPASS_HZ = 300.0  # removes the clip's own embedded background drone;
                          # safe to filter fairly hard since the real drone bed
                          # is always playing underneath and never needs help
                          # from the clip for low-end content
CLIP_GAIN = 0.85          # slight headroom so additive mixing with the
                          # continuously-running drone never clips


def highpass(x, cutoff_hz, sr, order=4):
    sos = butter(order, cutoff_hz, btype="highpass", fs=sr, output="sos")
    return sosfiltfilt(sos, x, axis=0)


def load_assets():
    drone, sr = sf.read(f"{ASSETS}/drone_bed_full.flac", always_2d=True)
    assert sr == SR
    clip_files = sorted(glob.glob(f"{ASSETS}/clips/*.flac"))
    clips = [highpass(sf.read(f, always_2d=True)[0], CLIP_HIGHPASS_HZ, sr) * CLIP_GAIN
             for f in clip_files]
    return drone, clips


def render_segment(drone, clips, state, dendro):
    n_channels = drone.shape[1]
    n_samples = int(SEGMENT_LEN * SR)
    canvas = np.zeros((n_samples, n_channels), dtype=np.float64)

    # tile the drone loop starting from the carried-over phase
    dlen = len(drone)
    phase = int(state["drone_phase"]) % dlen
    pos = 0
    while pos < n_samples:
        take = min(dlen - phase, n_samples - pos)
        canvas[pos:pos + take] = drone[phase:phase + take]
        pos += take
        phase = (phase + take) % dlen
    new_phase = (state["drone_phase"] + n_samples) % dlen

    events_placed = []
    t = 0.0
    wait_remaining = state["wait_remaining"]
    clip_ptr = state["clip_ptr"]
    activity = current_activity(dendro)

    while True:
        if wait_remaining > SEGMENT_LEN - t:
            wait_remaining -= (SEGMENT_LEN - t)
            break
        t += wait_remaining
        clip = clips[clip_ptr % len(clips)]
        clip_ptr += 1

        place_start = int(round((t - CLIP_PRE) * SR))
        L = len(clip)
        place_end = place_start + L
        c0, c1 = 0, L
        if place_start < 0:
            c0 = -place_start
            place_start = 0
        if place_end > n_samples:
            c1 = L - (place_end - n_samples)
            place_end = n_samples
        seg_clip = clip[c0:c1]
        Lc = len(seg_clip)
        if Lc > 0:
            # additive, not a crossfade-replace: the drone underneath keeps
            # playing completely unchanged, the (already high-pass filtered)
            # clip only ever adds on top of it. A short linear fade at each
            # edge just avoids a click at the exact start/end sample.
            ef = min(int(EDGE_FADE * SR), Lc // 2)
            win = np.ones(Lc)
            if ef > 0:
                win[:ef] = np.linspace(0, 1, ef)
                win[-ef:] = np.linspace(1, 0, ef)
            canvas[place_start:place_end] += seg_clip * win[:, None]
            events_placed.append(t)

        base = MIN_WAIT + (1 - activity) * (MAX_WAIT - MIN_WAIT)
        jitter = np.random.uniform(JITTER_LO, JITTER_HI)
        wait_remaining = max(base * jitter, HARD_MIN_WAIT)

    state["drone_phase"] = new_phase
    state["wait_remaining"] = wait_remaining
    state["clip_ptr"] = clip_ptr
    return canvas, events_placed, activity


def cleanup_old_segments():
    files = sorted(glob.glob(f"{QUEUE_DIR}/seg_*.wav"))
    for f in files[:-KEEP_SEGMENTS] if len(files) > KEEP_SEGMENTS else []:
        try:
            os.remove(f)
        except OSError:
            pass


def main():
    log("Generator starting")
    drone, clips = load_assets()
    log(f"Loaded drone loop ({len(drone)/SR:.1f}s) and {len(clips)} bowl clips")
    state = load_state()

    def run_cycle():
        dendro = fetch_dendro_data()
        canvas, events, activity = render_segment(drone, clips, state, dendro)
        seq = state["seq"]
        path = f"{QUEUE_DIR}/seg_{seq:08d}.wav"
        tmp_path = path + ".tmp"
        sf.write(tmp_path, canvas, SR, subtype="PCM_16", format="WAV")
        os.replace(tmp_path, path)
        state["seq"] = seq + 1
        save_state(state)
        write_status(activity, state["wait_remaining"])
        cleanup_old_segments()
        log(f"Rendered {path}: {len(events)} events at {[f'{e:.1f}s' for e in events]}, "
            f"activity={activity:.2f}")

    # build an initial buffer cushion before settling into real-time pace
    existing = len(glob.glob(f"{QUEUE_DIR}/seg_*.wav"))
    if existing == 0:
        log(f"No existing queue found, pre-rendering {STARTUP_BUFFER_SEGMENTS} "
            f"segments for a startup buffer")
        for _ in range(STARTUP_BUFFER_SEGMENTS):
            try:
                run_cycle()
            except Exception:
                log("ERROR during startup buffering:\n" + traceback.format_exc())

    while True:
        cycle_start = time.time()
        try:
            run_cycle()
        except Exception:
            log("ERROR in generation cycle:\n" + traceback.format_exc())
        elapsed = time.time() - cycle_start
        time.sleep(max(0.0, CYCLE_SLEEP - elapsed))


if __name__ == "__main__":
    main()
