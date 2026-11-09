from celery import Celery
from kombu import Queue

from app.config import BROKER_URL
from app.database import SessionLocal
from app.service import deliver

celery = Celery("notifications", broker=BROKER_URL)
celery.conf.update(
    task_queues=[Queue(name, durable=True, queue_arguments={"x-max-priority": 10})
                 for name in ("email", "push")],
    task_default_queue="email",
    task_create_missing_queues=False,
    task_serializer="json",
    accept_content=["json"],
    task_default_delivery_mode="persistent",
    task_acks_late=True,
    task_reject_on_worker_lost=True,
    task_ignore_result=True,
    worker_prefetch_multiplier=1,
    broker_connection_retry_on_startup=True,
    broker_transport_options={"confirm_publish": True},
    broker_connection_timeout=3,
    task_publish_retry=False,
    timezone="UTC",
    enable_utc=True,
)


@celery.task(name="notifications.deliver")
def send_notification(notification_id, version):
    with SessionLocal.begin() as session:
        return deliver(session, notification_id, version)
