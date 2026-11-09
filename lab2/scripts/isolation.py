"""Pomocnik do sprawdzania niezależności kanałów i trwałości zleceń."""
import json
import sys
import time
from pathlib import Path

from demo import create, request, wait_for

STATE = Path("/tmp/lab2-isolation.json")


def main():
    mode = sys.argv[1]
    if mode == "create":
        blocked = sys.argv[2] if len(sys.argv) > 2 else "email"
        assert blocked in ("email", "push")
        email = create()
        push = create(channel="push")
        STATE.write_text(json.dumps({"email": email["id"], "push": push["id"], "blocked": blocked}))
        print(f"Utworzono zlecenia przy zatrzymanym workerze {blocked}.")
    elif mode == "check":
        ids = json.loads(STATE.read_text())
        blocked = ids["blocked"]
        active = "push" if blocked == "email" else "email"
        wait_for(ids[active], "sent")
        time.sleep(4)
        waiting = request(f"/notifications/{ids[blocked]}")
        assert waiting["status"] in ("pending", "queued"), waiting
        assert waiting["attempts"] == 0
        print(f"OK: {active} dostarczony, {blocked} czeka bez zużycia prób.")
    elif mode == "recover":
        ids = json.loads(STATE.read_text())
        wait_for(ids[ids["blocked"]], "sent")
        for notification_id in (ids["email"], ids["push"]):
            assert len(request(f"/deliveries?notification_id={notification_id}")) == 1
        print("OK: po restarcie oba kanały mają dokładnie jedno dostarczenie.")
    else:
        raise ValueError("Użyj create, check lub recover.")


if __name__ == "__main__":
    main()
