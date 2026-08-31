"""
Feeds queued WAV segments (produced by generator.py) into Icecast as a
continuous live stream via a persistent ffmpeg source process.

Resilience: if the queue runs dry (generator stalled/slow), the last
successfully played segment is repeated so the stream never goes silent.
Once a fresh segment appears, playback switches back to the real queue.
"""
import glob, os, subprocess, time, wave, sys

QUEUE_DIR = "/data/queue"
SR = 44100
CHANNELS = 2
SOURCE_PASSWORD = os.environ.get("ICECAST_SOURCE_PASSWORD", "changeme")
MOUNT = "/stream.mp3"
CHUNK_FRAMES = 4096

os.makedirs(QUEUE_DIR, exist_ok=True)


def log(msg):
    print(f"[{time.strftime('%Y-%m-%d %H:%M:%S')}] streamer: {msg}", flush=True)


def start_ffmpeg():
    cmd = [
        "ffmpeg", "-loglevel", "warning", "-re",
        "-f", "s16le", "-ar", str(SR), "-ac", str(CHANNELS), "-i", "pipe:0",
        "-c:a", "libmp3lame", "-b:a", "192k",
        "-content_type", "audio/mpeg",
        "-ice_name", "Olive Tree",
        "-ice_description", "Olivenbaum Klangmeditation",
        "-ice_genre", "Ambient",
        "-f", "mp3",
        f"icecast://source:{SOURCE_PASSWORD}@127.0.0.1:8000{MOUNT}",
    ]
    return subprocess.Popen(cmd, stdin=subprocess.PIPE)


def wait_for_icecast():
    import socket
    for _ in range(60):
        try:
            with socket.create_connection(("127.0.0.1", 8000), timeout=1):
                return
        except OSError:
            time.sleep(1)
    log("WARNING: icecast never came up on 127.0.0.1:8000, starting ffmpeg anyway")


def read_frames(path):
    with wave.open(path, "rb") as wf:
        while True:
            data = wf.readframes(CHUNK_FRAMES)
            if not data:
                break
            yield data


def main():
    wait_for_icecast()
    proc = start_ffmpeg()
    last_segment_frames = None
    played = set()

    while True:
        files = sorted(glob.glob(f"{QUEUE_DIR}/seg_*.wav"))
        next_files = [f for f in files if f not in played]

        if next_files:
            path = next_files[0]
            try:
                frames = list(read_frames(path))
                for chunk in frames:
                    proc.stdin.write(chunk)
                proc.stdin.flush()
                last_segment_frames = frames
                played.add(path)
                log(f"played {path}")
            except (BrokenPipeError, OSError):
                log("ffmpeg pipe broke, restarting ffmpeg")
                proc = start_ffmpeg()
            except Exception as e:
                log(f"failed to play {path}: {e}")
                played.add(path)  # skip a corrupt segment rather than looping on it
        elif last_segment_frames is not None:
            log("queue empty, repeating last known-good segment to avoid dead air")
            try:
                for chunk in last_segment_frames:
                    proc.stdin.write(chunk)
                proc.stdin.flush()
            except (BrokenPipeError, OSError):
                log("ffmpeg pipe broke, restarting ffmpeg")
                proc = start_ffmpeg()
        else:
            log("no segments available yet, waiting")
            time.sleep(2)

        # keep 'played' bounded
        if len(played) > 100:
            oldest = sorted(played)[:50]
            for o in oldest:
                played.discard(o)


if __name__ == "__main__":
    main()
