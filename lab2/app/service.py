import logging
from datetime import timedelta
from uuid import uuid4

from fastapi import HTTPException
from sqlalchemy import select

from app.config import MAX_ATTEMPTS, RETRY_SECONDS
from app.models import Attempt, Delivery, Notification
from app.time_rules import allowed_time, as_utc, utc_now

logger = logging.getLogger(__name__)


def get_notification(session, notification_id, lock=False):
    query = select(Notification).where(Notification.id == notification_id)
    if lock:
        query = query.with_for_update()
    notification = session.scalar(query)
    if notification is None:
        raise HTTPException(404, "Nie znaleziono powiadomienia.")
    return notification


def create_notification(session, data):
    now = utc_now()
    requested = as_utc(data.scheduled_at)
    if requested <= now:
        raise HTTPException(422, "Termin musi być w przyszłości.")
    notification = Notification(
        id=str(uuid4()), recipient=data.recipient, content=data.content,
        channel=data.channel, timezone=data.timezone, priority=data.priority,
        requested_at=requested, scheduled_at=allowed_time(requested, data.timezone),
        created_at=now, simulate_failures=data.simulate_failures,
    )
    session.add(notification)
    session.commit()
    return notification


def change_notification(session, notification_id, action):
    notification = get_notification(session, notification_id, lock=True)
    if notification.status not in ("pending", "queued"):
        raise HTTPException(409, "Można zmienić tylko oczekujące powiadomienie.")
    # Zadania już obecne w RabbitMQ zawierają poprzednią wersję: zostaną pominięte.
    notification.version += 1
    notification.queued_at = None
    if action == "cancel":
        notification.status = "cancelled"
    else:
        notification.status = "pending"
        notification.scheduled_at = allowed_time(utc_now(), notification.timezone)
    session.commit()
    return notification


def deliver(session, notification_id, version, now=None):
    """Jedna próba. Zapis dostarczenia jest symulacją, więc mieści się w transakcji.

    PostgreSQL blokuje wiersz do końca transakcji. Drugi worker czeka, a następnie
    widzi stan sent albo nową wersję i nie wykonuje ponownie tej samej wiadomości.
    """
    notification = session.scalar(
        select(Notification).where(Notification.id == notification_id).with_for_update()
    )
    # Oczekiwanie na blokadę mogło potrwać aż do rozpoczęcia ciszy nocnej.
    now = now or utc_now()
    if notification is None or notification.version != version:
        return "ignored"
    if notification.status not in ("pending", "queued"):
        return "ignored"
    if as_utc(notification.scheduled_at) > now:
        return "ignored"

    # Sprawdzamy ciszę jeszcze raz: kolejka mogła długo czekać na wolny proces.
    permitted = allowed_time(now, notification.timezone)
    if permitted > now:
        notification.scheduled_at = permitted
        notification.status = "pending"
        notification.queued_at = None
        notification.version += 1
        return "postponed"

    notification.attempts += 1
    failed = notification.attempts <= notification.simulate_failures
    session.add(Attempt(
        notification_id=notification.id, number=notification.attempts,
        created_at=now, result="failed" if failed else "sent",
        error="Symulowany błąd kanału." if failed else None,
    ))
    notification.queued_at = None
    if failed:
        notification.last_error = "Symulowany błąd kanału."
        notification.version += 1
        if notification.attempts >= MAX_ATTEMPTS:
            notification.status = "failed"
        else:
            notification.status = "pending"
            notification.scheduled_at = allowed_time(
                now + timedelta(seconds=RETRY_SECONDS), notification.timezone
            )
    else:
        session.add(Delivery(
            notification_id=notification.id, recipient=notification.recipient,
            channel=notification.channel, content=notification.content, delivered_at=now,
        ))
        notification.status = "sent"
        notification.last_error = None

    logger.info("Powiadomienie=%s kanał=%s próba=%s wynik=%s",
                notification.id, notification.channel, notification.attempts,
                notification.status)
    return notification.status
