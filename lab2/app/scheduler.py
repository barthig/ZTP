import logging
import time
from datetime import timedelta

from sqlalchemy import and_, case, or_, select

from app.config import RECOVERY_SECONDS
from app.database import SessionLocal
from app.models import Notification
from app.time_rules import utc_now

logger = logging.getLogger(__name__)


def publish(notification):
    from app.tasks import send_notification

    send_notification.apply_async(
        args=[notification.id, notification.version],
        queue=notification.channel,
        priority=9 if notification.priority == "high" else 1,
    )


def dispatch_due(session_factory=SessionLocal, publisher=publish, now=None, limit=100):
    """Wiersz powiadomienia pełni również rolę trwałego zlecenia do publikacji.

    Publikujemy przed zatwierdzeniem queued. Awaria pomiędzy tymi krokami może
    dać duplikat zadania, ale worker wykona efekt tylko raz. Nie tracimy zlecenia.
    Brak wyniku przez 30 s powoduje ponowną publikację (np. po awarii workera).
    """
    now = now or utc_now()
    count = 0
    for _ in range(limit):
        with session_factory.begin() as session:
            notification = session.scalar(
                select(Notification)
                .where(
                    Notification.scheduled_at <= now,
                    or_(
                        Notification.status == "pending",
                        and_(Notification.status == "queued",
                             Notification.queued_at <= now - timedelta(seconds=RECOVERY_SECONDS)),
                    ),
                )
                .order_by(case((Notification.priority == "high", 0), else_=1),
                          Notification.scheduled_at, Notification.created_at, Notification.id)
                .with_for_update(skip_locked=True)
                .limit(1)
            )
            if notification is None:
                break
            publisher(notification)
            notification.status = "queued"
            notification.queued_at = now
            count += 1
    return count


def main():
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
    while True:
        try:
            count = dispatch_due()
            if count:
                logger.info("Przekazano do kolejek: %s", count)
        except Exception:
            # Błąd brokera lub bazy nie usuwa zleceń. Kolejny obieg spróbuje ponownie.
            logger.exception("Nie udało się przekazać zleceń; ponowienie za sekundę.")
        time.sleep(1)


if __name__ == "__main__":
    main()
