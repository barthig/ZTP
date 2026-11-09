from pathlib import Path
from typing import Annotated, Literal

from fastapi import Depends, FastAPI, HTTPException, Query
from fastapi.responses import FileResponse
from fastapi.staticfiles import StaticFiles
from sqlalchemy import select, text
from sqlalchemy.exc import SQLAlchemyError
from sqlalchemy.orm import Session

from app.database import get_session
from app.config import MAX_ATTEMPTS, QUIET_END, QUIET_START, RETRY_SECONDS
from app.models import Attempt, Delivery, Notification
from app.schemas import AttemptRead, DeliveryRead, NotificationCreate, NotificationRead
from app.service import change_notification, create_notification, get_notification

app = FastAPI(
    title="Laboratorium 2 — symulator powiadomień",
    description="E-mail i push są symulowane przez trwały zapis w tabeli deliveries.",
    version="1.0.0",
)
Database = Annotated[Session, Depends(get_session)]
STATIC_DIR = Path(__file__).parent / "static"
app.mount("/static", StaticFiles(directory=STATIC_DIR), name="static")


@app.get("/", include_in_schema=False)
def home():
    return FileResponse(STATIC_DIR / "index.html")


@app.get("/settings", tags=["Stan"])
def settings():
    return {"quiet_start": QUIET_START, "quiet_end": QUIET_END,
            "max_attempts": MAX_ATTEMPTS, "retry_seconds": RETRY_SECONDS}


@app.get("/health", tags=["Stan"])
def health(session: Database):
    try:
        session.execute(text("SELECT 1"))
    except SQLAlchemyError:
        raise HTTPException(503, "Baza danych jest chwilowo niedostępna.")
    return {"api": "ok", "database": "ok"}


@app.post("/notifications", response_model=NotificationRead, status_code=201, tags=["Powiadomienia"])
def create(data: NotificationCreate, session: Database):
    return create_notification(session, data)


@app.get("/notifications", response_model=list[NotificationRead], tags=["Powiadomienia"])
def list_notifications(
    session: Database,
    status: Literal["pending", "queued", "sent", "failed", "cancelled"] | None = None,
    limit: int = Query(default=100, ge=1, le=500),
    offset: int = Query(default=0, ge=0),
):
    query = select(Notification).order_by(Notification.created_at.desc(), Notification.id)
    if status:
        query = query.where(Notification.status == status)
    return session.scalars(query.limit(limit).offset(offset)).all()


@app.get("/notifications/{notification_id}", response_model=NotificationRead, tags=["Powiadomienia"])
def detail(notification_id: str, session: Database):
    return get_notification(session, notification_id)


@app.post("/notifications/{notification_id}/cancel", response_model=NotificationRead, tags=["Powiadomienia"])
def cancel(notification_id: str, session: Database):
    return change_notification(session, notification_id, "cancel")


@app.post("/notifications/{notification_id}/send-now", response_model=NotificationRead, tags=["Powiadomienia"])
def send_now(notification_id: str, session: Database):
    return change_notification(session, notification_id, "send-now")


@app.get("/notifications/{notification_id}/attempts", response_model=list[AttemptRead], tags=["Wyniki"])
def attempts(notification_id: str, session: Database):
    get_notification(session, notification_id)
    return session.scalars(select(Attempt).where(
        Attempt.notification_id == notification_id).order_by(Attempt.number)).all()


@app.get("/deliveries", response_model=list[DeliveryRead], tags=["Wyniki"])
def deliveries(session: Database, limit: int = Query(default=100, ge=1, le=500),
               offset: int = Query(default=0, ge=0), notification_id: str | None = None,
               recipient: str | None = None, channel: Literal["email", "push"] | None = None):
    query = select(Delivery).order_by(Delivery.delivered_at.desc(), Delivery.id)
    if notification_id is not None:
        query = query.where(Delivery.notification_id == notification_id)
    if recipient is not None:
        query = query.where(Delivery.recipient == recipient)
    if channel is not None:
        query = query.where(Delivery.channel == channel)
    return session.scalars(query.limit(limit).offset(offset)).all()
