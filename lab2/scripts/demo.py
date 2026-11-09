"""Pokaz działania przez prawdziwe API i workery. Uruchomienie: patrz README."""
import json
import os
import time
from datetime import UTC, datetime, timedelta
from urllib.request import Request, urlopen
from zoneinfo import ZoneInfo

BASE_URL = os.getenv("API_URL", "http://localhost:8000")


def request(path, data=None):
    body = json.dumps(data).encode() if data is not None else None
    req = Request(BASE_URL + path, data=body, headers={"Content-Type": "application/json"})
    with urlopen(req, timeout=10) as response:
        return json.load(response)


def daytime_zone():
    # Pokaz zadziała również wieczorem: wybieramy odbiorcę, u którego jest południe.
    now = datetime.now(UTC)
    for offset in range(-14, 13):
        zone = "Etc/GMT" + (f"{offset:+d}" if offset else "")
        if now.astimezone(ZoneInfo(zone)).hour == 12:
            return zone
    raise RuntimeError("Nie znaleziono strefy do prezentacji.")


def create(channel="email", seconds=3, **overrides):
    data = dict(recipient="student@example.com", content="Wiadomość pokazowa",
                channel=channel, timezone=daytime_zone(), priority="low",
                scheduled_at=(datetime.now(UTC) + timedelta(seconds=seconds)).isoformat())
    return request("/notifications", data | overrides)


def wait_for(notification_id, expected, timeout=70):
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        current = request(f"/notifications/{notification_id}")
        if current["status"] == expected:
            return current
        if current["status"] in ("sent", "failed", "cancelled"):
            raise AssertionError(f"Oczekiwano {expected}, otrzymano {current}")
        time.sleep(0.5)
    raise AssertionError(f"Przekroczono czas oczekiwania na {notification_id}: {current}")


def main():
    print("Strefa odbiorców demonstracyjnych:", daytime_zone())
    print("Tworzenie sześciu powiadomień...")
    email = create()
    push = create(channel="push", priority="high")
    retry = create(simulate_failures=2)
    failure = create(simulate_failures=3)
    cancel = create(seconds=10)
    immediate = create(seconds=3600)
    request(f"/notifications/{cancel['id']}/cancel", {})
    request(f"/notifications/{immediate['id']}/send-now", {})

    for label, notification, expected, attempts in [
        ("E-mail", email, "sent", 1), ("Push", push, "sent", 1),
        ("Dwa błędy, potem sukces", retry, "sent", 3),
        ("Trzy błędy, koniec prób", failure, "failed", 3),
        ("Anulowanie", cancel, "cancelled", 0),
        ("Wyślij teraz", immediate, "sent", 1),
    ]:
        result = wait_for(notification["id"], expected)
        assert result["attempts"] == attempts, result
        history = request(f"/notifications/{notification['id']}/attempts")
        assert len(history) == attempts
        print(f"OK: {label}: status={expected}, próby={attempts}, id={notification['id']}")

    for notification in [email, push, retry, immediate]:
        deliveries = request(f"/deliveries?notification_id={notification['id']}")
        assert len(deliveries) == 1, "Brak dostarczenia lub duplikat"
    for notification in [failure, cancel]:
        assert request(f"/deliveries?notification_id={notification['id']}") == []
    print("OK: dokładnie cztery dostarczenia dla sześciu zleceń, bez duplikatów.")


if __name__ == "__main__":
    main()
