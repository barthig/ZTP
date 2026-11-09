from datetime import UTC, datetime, timedelta
from zoneinfo import ZoneInfo

from app.config import QUIET_END, QUIET_START


def utc_now():
    return datetime.now(UTC)


def as_utc(value):
    # SQLite w testach zwraca daty bez informacji o strefie.
    if value.tzinfo is None:
        return value.replace(tzinfo=UTC)
    return value.astimezone(UTC)


def allowed_time(value, timezone, quiet_start=QUIET_START, quiet_end=QUIET_END):
    """Przesuń nocny termin na pierwszą dozwoloną minutę w UTC.

    Przechodzenie po osi UTC uwzględnia przeskoki i powtórzenia godzin przy DST.
    Równe granice wyłączają ciszę nocną (przydatne podczas prezentacji).
    """
    value = as_utc(value)
    zone = ZoneInfo(timezone)
    if quiet_start == quiet_end:
        return value
    for _ in range(26 * 60):
        hour = value.astimezone(zone).hour
        if quiet_start < quiet_end:
            quiet = quiet_start <= hour < quiet_end
        else:
            quiet = hour >= quiet_start or hour < quiet_end
        if not quiet:
            return value
        value = value.replace(second=0, microsecond=0) + timedelta(minutes=1)
    raise ValueError("Nie udało się wyznaczyć dozwolonego terminu.")
