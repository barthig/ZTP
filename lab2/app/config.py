import os

DATABASE_URL = os.getenv("DATABASE_URL", "postgresql+psycopg://lab2:lab2@localhost:5432/lab2")
BROKER_URL = os.getenv("BROKER_URL", "amqp://lab2:lab2@localhost:5672//")
QUIET_START = int(os.getenv("QUIET_START", "22"))
QUIET_END = int(os.getenv("QUIET_END", "7"))
RETRY_SECONDS = int(os.getenv("RETRY_SECONDS", "5"))
RECOVERY_SECONDS = 30
MAX_ATTEMPTS = 3

if not (0 <= QUIET_START <= 23 and 0 <= QUIET_END <= 23):
    raise ValueError("Godziny ciszy muszą być z zakresu 0–23.")
