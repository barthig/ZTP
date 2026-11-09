"""Tabele symulatora powiadomień."""
from alembic import op
import sqlalchemy as sa

revision = "001"
down_revision = None
branch_labels = None
depends_on = None


def upgrade():
    op.create_table(
        "notifications",
        sa.Column("id", sa.String(36), primary_key=True),
        sa.Column("recipient", sa.String(200), nullable=False),
        sa.Column("content", sa.Text(), nullable=False),
        sa.Column("channel", sa.String(10), nullable=False),
        sa.Column("timezone", sa.String(100), nullable=False),
        sa.Column("priority", sa.String(10), nullable=False),
        sa.Column("requested_at", sa.DateTime(timezone=True), nullable=False),
        sa.Column("scheduled_at", sa.DateTime(timezone=True), nullable=False),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False),
        sa.Column("status", sa.String(20), nullable=False),
        sa.Column("attempts", sa.Integer(), nullable=False),
        sa.Column("version", sa.Integer(), nullable=False),
        sa.Column("simulate_failures", sa.Integer(), nullable=False),
        sa.Column("queued_at", sa.DateTime(timezone=True)),
        sa.Column("last_error", sa.Text()),
    )
    op.create_index("ix_notifications_due", "notifications", ["status", "scheduled_at"])
    op.create_table(
        "attempts",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column("notification_id", sa.String(36), sa.ForeignKey("notifications.id"), nullable=False),
        sa.Column("number", sa.Integer(), nullable=False),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False),
        sa.Column("result", sa.String(20), nullable=False),
        sa.Column("error", sa.Text()),
        sa.UniqueConstraint("notification_id", "number"),
    )
    op.create_table(
        "deliveries",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column("notification_id", sa.String(36), sa.ForeignKey("notifications.id"), nullable=False, unique=True),
        sa.Column("recipient", sa.String(200), nullable=False),
        sa.Column("channel", sa.String(10), nullable=False),
        sa.Column("content", sa.Text(), nullable=False),
        sa.Column("delivered_at", sa.DateTime(timezone=True), nullable=False),
        sa.UniqueConstraint("notification_id", "recipient"),
    )


def downgrade():
    op.drop_table("deliveries")
    op.drop_table("attempts")
    op.drop_index("ix_notifications_due", table_name="notifications")
    op.drop_table("notifications")
