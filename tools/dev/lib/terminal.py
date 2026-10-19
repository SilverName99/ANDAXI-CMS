"""Rulează o comandă într-un terminal adevărat (pty), ca un om la tastatură, pentru
tools/dev/test-verificare.php: parolele din configureaza.php se ascund doar într-un terminal,
iar ce rămâne din terminal după Ctrl-C se vede tot doar acolo.

  python3 -I tools/dev/lib/terminal.py '<json>'

JSON-ul: {"comanda": [...], "cwd": "...", "env": {...}, "pasi": [...], "asteptare": 4}.
Un pas: {"asteapta": "<text de pe ecran>", "trimite": "<taste>"} sau
{"asteapta": "...", "semnal": 15} (semnalul îl primește procesul, nu terminalul).
Scrie pe stdout un JSON: codul de ieșire (sau semnalul care l-a oprit), câte secunde au
trecut de la ultimul pas până la ieșire, starea terminalului după ieșire (ecou, mod rând,
Ctrl-C ca semnal), tot ce a apărut pe ecran și primul text așteptat care n-a apărut.
Doar biblioteca standard.
"""

import json
import os
import pty
import select
import signal
import sys
import termios
import time


def main():
    cerere = json.loads(sys.argv[1])
    pid, fd = pty.fork()
    if pid == 0:
        try:
            os.chdir(cerere["cwd"])
            os.execve(cerere["comanda"][0], cerere["comanda"], cerere["env"])
        finally:
            os._exit(127)

    ecran = b""

    def citeste(pana_la):
        nonlocal ecran
        while time.time() < pana_la:
            gata, _, _ = select.select([fd], [], [], 0.05)
            if not gata:
                continue
            try:
                bucata = os.read(fd, 4096)
            except OSError:
                return False
            if not bucata:
                return False
            ecran += bucata
        return True

    def asteapta_text(text, secunde):
        nonlocal ecran
        cautat = text.encode("utf-8")
        pana_la = time.time() + secunde
        while cautat not in ecran and time.time() < pana_la:
            if not citeste(min(pana_la, time.time() + 0.1)):
                break
        return cautat in ecran

    nevazut = None
    for pas in cerere["pasi"]:
        if not asteapta_text(pas["asteapta"], 10):
            nevazut = pas["asteapta"]
            break
        # Omul citește întrebarea, apoi scrie.
        citeste(time.time() + 0.2)
        if "semnal" in pas:
            os.kill(pid, int(pas["semnal"]))
        else:
            os.write(fd, pas["trimite"].encode("utf-8"))

    start = time.time()
    stare = None
    while time.time() - start < float(cerere.get("asteptare", 4)):
        gata, st = os.waitpid(pid, os.WNOHANG)
        if gata:
            stare = st
            break
        citeste(time.time() + 0.05)
    secunde = round(time.time() - start, 2)
    citeste(time.time() + 0.1)
    # Pe Linux, starea citită pe partea „master” e cea a terminalului programului.
    lflag = termios.tcgetattr(fd)[3]
    if stare is None:
        os.kill(pid, signal.SIGKILL)
        os.waitpid(pid, 0)
    print(json.dumps({
        "iesit": stare is not None,
        "cod": os.WEXITSTATUS(stare) if stare is not None and os.WIFEXITED(stare) else None,
        "semnal": os.WTERMSIG(stare) if stare is not None and os.WIFSIGNALED(stare) else None,
        "secunde": secunde,
        "ecou": bool(lflag & termios.ECHO),
        "mod_rand": bool(lflag & termios.ICANON),
        "ctrl_c_semnal": bool(lflag & termios.ISIG),
        "ecran": ecran.decode("utf-8", "replace"),
        "nevazut": nevazut,
    }, ensure_ascii=False))


main()
