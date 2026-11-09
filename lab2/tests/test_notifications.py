from concurrent.futures import ThreadPoolExecutor
from datetime import UTC, datetime, timedelta

import pytest
from sqlalchemy import func, select

from app import service
from app.models import Attempt, Delivery, Notification
from app.scheduler import dispatch_due
from app.schemas import NotificationCreate
from app.service import change_notification, create_notification, deliver
from app.time_rules import allowed_time, as_utc

NOW = datetime(2026, 7, 1, 10, tzinfo=UTC)


@pytest.fixture(autouse=True)
def fixed_clock(monkeypatch):
    monkeypatch.setattr(service, "utc_now", lambda: NOW)


def create(sessions, **overrides):
    data = dict(recipient="student@example.com", content="Laboratorium 2",
                channel="email", timezone="Europe/Warsaw", priority="low",
                scheduled_at=NOW + timedelta(seconds=2))
    data.update(overrides)
    with sessions() as session:
        return create_notification(session, NotificationCreate(**data))


def run(sessions, notification, now=NOW + timedelta(seconds=3), version=None):
    with sessions.begin() as session:
        return deliver(session, notification.id,
                       version if version is not None else notification.version, now)


def count(sessions, model):
    with sessions() as session:
        return session.scalar(select(func.count()).select_from(model))


@pytest.mark.parametrize("channel", ["email", "push"])
def test_delivery_once_even_with_duplicate_task(sessions, channel):
    notification = create(sessions, channel=channel)
    assert run(sessions, notification) == "sent"
    assert run(sessions, notification) == "ignored"
    assert count(sessions, Delivery) == 1
    assert count(sessions, Attempt) == 1


def test_not_before_requested_time(sessions):
    notification = create(sessions)
    assert run(sessions, notification, now=NOW) == "ignored"
    assert count(sessions, Attempt) == 0


@pytest.mark.parametrize("failures, expected, deliveries", [(2, "sent", 1), (3, "failed", 0)])
def test_three_attempts_and_no_fourth(sessions, failures, expected, deliveries):
    notification = create(sessions, simulate_failures=failures)
    for number in range(1, 4):
        with sessions() as session:
            current = session.get(Notification, notification.id)
            when = as_utc(current.scheduled_at)
            version = current.version
        result = run(sessions, notification, now=when, version=version)
        if number < 3:
            assert result == "pending"
            # Wcześniejsze ponowienie nie może zużyć kolejnej próby.
            with sessions() as session:
                current = session.get(Notification, notification.id)
                assert as_utc(current.scheduled_at) > when
                assert run(sessions, notification, now=when, version=current.version) == "ignored"
    assert result == expected
    assert run(sessions, notification, now=NOW + timedelta(minutes=1), version=version) == "ignored"
    assert count(sessions, Attempt) == 3
    assert count(sessions, Delivery) == deliveries


def test_cancel_invalidates_already_queued_task(sessions):
    notification = create(sessions)
    dispatch_due(sessions, lambda n: None, NOW + timedelta(seconds=3))
    with sessions() as session:
        changed = change_notification(session, notification.id, "cancel")
        assert changed.status == "cancelled"
    assert run(sessions, notification) == "ignored"
    assert count(sessions, Delivery) == 0


def test_send_now_invalidates_old_schedule(sessions):
    notification = create(sessions, scheduled_at=NOW + timedelta(hours=2))
    with sessions() as session:
        changed = change_notification(session, notification.id, "send-now")
    assert run(sessions, notification, now=NOW, version=changed.version) == "sent"
    assert run(sessions, notification, now=NOW + timedelta(hours=3)) == "ignored"
    assert count(sessions, Delivery) == 1


def test_send_now_respects_quiet_hours(sessions, monkeypatch):
    notification = create(sessions)
    night = datetime(2026, 7, 1, 23, tzinfo=UTC)
    monkeypatch.setattr(service, "utc_now", lambda: night)
    with sessions() as session:
        changed = change_notification(session, notification.id, "send-now")
        assert as_utc(changed.scheduled_at) == datetime(2026, 7, 2, 5, tzinfo=UTC)


def test_worker_checks_quiet_hours_after_queue_delay(sessions):
    notification = create(sessions)
    assert run(sessions, notification, now=datetime(2026, 7, 1, 23, tzinfo=UTC)) == "postponed"
    assert count(sessions, Attempt) == 0


def test_worker_reads_clock_after_waiting_for_database_lock(sessions, monkeypatch):
    notification = create(sessions)
    with sessions.begin() as session:
        original_scalar = session.scalar

        def acquire_lock(query):
            result = original_scalar(query)
            # Worker rozpoczął w dzień, ale blokadę uzyskał dopiero w nocy.
            monkeypatch.setattr(service, "utc_now", lambda: datetime(2026, 7, 1, 23, tzinfo=UTC))
            return result

        monkeypatch.setattr(session, "scalar", acquire_lock)
        assert deliver(session, notification.id, notification.version) == "postponed"
    assert count(sessions, Attempt) == 0


def test_priority_and_future_tasks(sessions):
    low = create(sessions)
    high = create(sessions, priority="high")
    create(sessions, priority="high", scheduled_at=NOW + timedelta(hours=1))
    published = []
    assert dispatch_due(sessions, lambda n: published.append(n.id), NOW + timedelta(seconds=3)) == 2
    assert published == [high.id, low.id]


def test_broker_failure_preserves_pending_notification(sessions):
    notification = create(sessions)

    def unavailable(notification):
        raise ConnectionError("Broker niedostępny")

    with pytest.raises(ConnectionError):
        dispatch_due(sessions, unavailable, NOW + timedelta(seconds=3))
    with sessions() as session:
        assert session.get(Notification, notification.id).status == "pending"
    assert dispatch_due(sessions, lambda n: None, NOW + timedelta(seconds=4)) == 1


def test_crash_after_publish_before_commit(sessions):
    notification = create(sessions)
    published = []

    def publish_then_crash(n):
        published.append((n.id, n.version))
        raise RuntimeError("Awaria przed zatwierdzeniem queued")

    with pytest.raises(RuntimeError):
        dispatch_due(sessions, publish_then_crash, NOW + timedelta(seconds=3))
    dispatch_due(sessions, lambda n: published.append((n.id, n.version)), NOW + timedelta(seconds=4))
    assert len(published) == 2
    for _, version in published:
        run(sessions, notification, version=version)
    assert count(sessions, Delivery) == 1


def test_unfinished_task_republished_after_timeout(sessions):
    create(sessions)
    assert dispatch_due(sessions, lambda n: None, NOW + timedelta(seconds=3)) == 1
    assert dispatch_due(sessions, lambda n: None, NOW + timedelta(seconds=10)) == 0
    assert dispatch_due(sessions, lambda n: None, NOW + timedelta(seconds=34)) == 1


def test_backlog_larger_than_scheduler_batch(sessions):
    for number in range(250):
        create(sessions, priority="high" if number % 2 else "low")
    published = []
    for expected in (100, 100, 50, 0):
        assert dispatch_due(sessions, lambda n: published.append((n.id, n.version)),
                            NOW + timedelta(seconds=3)) == expected
    assert len({notification_id for notification_id, _ in published}) == 250
    for notification_id, version in published:
        with sessions.begin() as session:
            assert deliver(session, notification_id, version, NOW + timedelta(seconds=4)) == "sent"
    assert count(sessions, Delivery) == 250


def test_crash_before_commit_rolls_back_delivery_and_attempt(sessions):
    notification = create(sessions)
    with pytest.raises(RuntimeError):
        with sessions.begin() as session:
            deliver(session, notification.id, notification.version, NOW + timedelta(seconds=3))
            session.flush()
            raise RuntimeError("Symulowana awaria przed COMMIT")
    assert count(sessions, Delivery) == 0
    assert count(sessions, Attempt) == 0
    assert run(sessions, notification) == "sent"
    # COMMIT się udał, ale potwierdzenie brokera zaginęło: duplikat nic nie zmienia.
    assert run(sessions, notification) == "ignored"
    assert count(sessions, Delivery) == 1


def test_parallel_workers_on_postgresql(sessions):
    if sessions.kw["bind"].dialect.name != "postgresql":
        pytest.skip("Blokady wierszy wymagają PostgreSQL")
    notification = create(sessions)
    with ThreadPoolExecutor(max_workers=6) as pool:
        results = list(pool.map(lambda _: run(sessions, notification), range(6)))
    assert results.count("sent") == 1
    assert count(sessions, Delivery) == 1
    assert count(sessions, Attempt) == 1


@pytest.mark.parametrize("action", ["cancel", "send-now"])
def test_concurrent_change_and_delivery(sessions, action):
    if sessions.kw["bind"].dialect.name != "postgresql":
        pytest.skip("Blokady wierszy wymagają PostgreSQL")
    notification = create(sessions)

    def change():
        from fastapi import HTTPException
        try:
            with sessions() as session:
                return change_notification(session, notification.id, action).status
        except HTTPException as error:
            assert error.status_code == 409
            return "already-sent"

    with ThreadPoolExecutor(max_workers=2) as pool:
        delivery = pool.submit(run, sessions, notification)
        update = pool.submit(change)
        delivery.result()
        update.result()
    with sessions() as session:
        current = session.get(Notification, notification.id)
        if current.status == "pending":
            run(sessions, notification, now=NOW + timedelta(seconds=4), version=current.version)
    assert count(sessions, Delivery) <= 1
    with sessions() as session:
        current = session.get(Notification, notification.id)
        assert current.status in ("sent", "cancelled")


def test_api_create_read_cancel_and_validation(client):
    data = dict(recipient="student@example.com", content="Test", channel="push",
                scheduled_at=(NOW + timedelta(hours=1)).isoformat())
    response = client.post("/notifications", json=data)
    assert response.status_code == 201
    notification_id = response.json()["id"]
    assert client.get(f"/notifications/{notification_id}").status_code == 200
    assert client.get("/notifications").json()[0]["id"] == notification_id
    assert client.post(f"/notifications/{notification_id}/cancel").json()["status"] == "cancelled"
    assert client.post(f"/notifications/{notification_id}/send-now").status_code == 409
    assert client.get(f"/notifications/{notification_id}/attempts").json() == []
    assert client.get("/deliveries").json() == []
    assert client.get("/notifications/missing").status_code == 404
    for invalid in [dict(timezone="Bad/Zone"), dict(content=" "), dict(channel="sms"),
                    dict(simulate_failures=4), dict(scheduled_at="2026-07-01T12:00:00"),
                    dict(scheduled_at=NOW.isoformat())]:
        assert client.post("/notifications", json=data | invalid).status_code == 422


def test_deliveries_filter_is_independent_of_other_messages(client, sessions):
    first = create(sessions)
    second = create(sessions, channel="push")
    run(sessions, first)
    run(sessions, second)
    response = client.get(f"/deliveries?notification_id={first.id}")
    assert response.status_code == 200
    assert [item["notification_id"] for item in response.json()] == [first.id]
    assert client.get("/deliveries?notification_id=missing").json() == []


def test_inbox_filters_by_recipient_and_channel(client, sessions):
    email = create(sessions, recipient="anna@example.com")
    push = create(sessions, recipient="anna@example.com", channel="push")
    other = create(sessions, recipient="jan@example.com")
    waiting = create(sessions, recipient="anna@example.com")
    for notification in (email, push, other):
        run(sessions, notification)
    inbox = client.get("/deliveries?recipient=anna%40example.com").json()
    assert {item["notification_id"] for item in inbox} == {email.id, push.id}
    assert waiting.id not in {item["notification_id"] for item in inbox}
    only_push = client.get("/deliveries?recipient=anna%40example.com&channel=push").json()
    assert [item["notification_id"] for item in only_push] == [push.id]
    assert client.get("/deliveries?recipient=missing").json() == []
    assert client.get("/deliveries?channel=sms").status_code == 422


def test_health_reports_database_unavailability(client):
    from sqlalchemy.exc import OperationalError
    from app.database import get_session
    from app.main import app

    assert client.get("/health").status_code == 200

    class UnavailableDatabase:
        def execute(self, query):
            raise OperationalError("SELECT 1", {}, Exception("Baza wyłączona"))

    app.dependency_overrides[get_session] = lambda: UnavailableDatabase()
    response = client.get("/health")
    assert response.status_code == 503
    assert "niedostępna" in response.json()["detail"]


@pytest.mark.parametrize("moment,zone,expected", [
    ("2026-07-01T21:00:00+00:00", "Europe/Warsaw", "2026-07-02T05:00:00+00:00"),
    ("2026-01-01T21:00:00+00:00", "Europe/Warsaw", "2026-01-02T06:00:00+00:00"),
    ("2026-07-01T12:00:00+00:00", "Europe/Warsaw", "2026-07-01T12:00:00+00:00"),
    ("2026-07-01T05:00:00+00:00", "America/New_York", "2026-07-01T11:00:00+00:00"),
    ("2026-07-01T20:00:00+00:00", "Europe/Warsaw", "2026-07-02T05:00:00+00:00"),
    ("2026-07-01T05:00:00+00:00", "Europe/Warsaw", "2026-07-01T05:00:00+00:00"),
])
def test_time_zones_and_quiet_boundaries(moment, zone, expected):
    assert allowed_time(datetime.fromisoformat(moment), zone) == datetime.fromisoformat(expected)


def test_dst_spring_gap_and_autumn_overlap():
    assert allowed_time(datetime(2026, 3, 29, 0, 30, tzinfo=UTC), "Europe/Warsaw",
                        quiet_start=22, quiet_end=2) == datetime(2026, 3, 29, 1, tzinfo=UTC)
    assert allowed_time(datetime(2026, 10, 25, 0, 30, tzinfo=UTC), "Europe/Warsaw",
                        quiet_start=0, quiet_end=3) == datetime(2026, 10, 25, 2, tzinfo=UTC)


def test_explicit_offsets_distinguish_repeated_hour():
    first = datetime.fromisoformat("2026-10-25T02:30:00+02:00")
    second = datetime.fromisoformat("2026-10-25T02:30:00+01:00")
    assert allowed_time(second, "Europe/Warsaw", 0, 0) - allowed_time(first, "Europe/Warsaw", 0, 0) == timedelta(hours=1)
