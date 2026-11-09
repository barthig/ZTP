from datetime import datetime
from typing import Literal
from zoneinfo import ZoneInfo, ZoneInfoNotFoundError

from pydantic import AwareDatetime, BaseModel, ConfigDict, Field, field_validator


class NotificationCreate(BaseModel):
    recipient: str = Field(min_length=1, max_length=200)
    content: str = Field(min_length=1, max_length=5000)
    channel: Literal["email", "push"]
    timezone: str = Field(default="Europe/Warsaw", min_length=1, max_length=100)
    priority: Literal["low", "high"] = "low"
    scheduled_at: AwareDatetime
    simulate_failures: int = Field(default=0, ge=0, le=3)

    @field_validator("recipient", "content")
    @classmethod
    def non_blank(cls, value):
        if not value.strip():
            raise ValueError("Wartość nie może być pusta.")
        return value.strip()

    @field_validator("timezone")
    @classmethod
    def valid_timezone(cls, value):
        try:
            ZoneInfo(value)
        except (ZoneInfoNotFoundError, ValueError):
            raise ValueError("Podaj strefę IANA, np. Europe/Warsaw.")
        return value


class NotificationRead(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: str
    recipient: str
    content: str
    channel: str
    timezone: str
    priority: str
    requested_at: datetime
    scheduled_at: datetime
    created_at: datetime
    status: str
    attempts: int
    version: int
    simulate_failures: int
    queued_at: datetime | None
    last_error: str | None


class AttemptRead(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    number: int
    created_at: datetime
    result: str
    error: str | None


class DeliveryRead(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    notification_id: str
    recipient: str
    channel: str
    content: str
    delivered_at: datetime
