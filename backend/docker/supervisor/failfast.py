"""Stop the container if a supervised worker exhausts its startup retries."""
import os
import signal
import sys

while True:
    sys.stdout.write("READY\n")
    sys.stdout.flush()
    line = sys.stdin.readline()
    if not line:
        break
    headers = dict(field.split(":", 1) for field in line.split())
    payload = sys.stdin.read(int(headers["len"]))
    sys.stderr.write(f"A worker could not start; shutting down the service: {payload}\n")
    sys.stderr.flush()
    sys.stdout.write("RESULT 2\nOK")
    sys.stdout.flush()
    os.kill(os.getppid(), signal.SIGTERM)
