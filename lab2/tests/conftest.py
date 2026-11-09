import os
from uuid import uuid4

# Testy jednostkowe nie wymagają działającego PostgreSQL ani RabbitMQ.
os.environ.setdefault("DATABASE_URL", "sqlite://")

import pytest
from fastapi.testclient import TestClient
from sqlalchemy import create_engine, text
from sqlalchemy.orm import sessionmaker

from app.database import Base, get_session
from app.main import app


@pytest.fixture
def sessions(tmp_path):
    url = os.getenv("TEST_DATABASE_URL")
    if url:
        # Osobny schemat: testy nie zmieniają danych aplikacji.
        schema = "test_" + uuid4().hex
        root_engine = create_engine(url)
        with root_engine.begin() as connection:
            connection.execute(text(f'CREATE SCHEMA "{schema}"'))
        engine = root_engine.execution_options(schema_translate_map={None: schema})
    else:
        engine = create_engine(f"sqlite:///{tmp_path / 'test.db'}",
                               connect_args={"check_same_thread": False})
    Base.metadata.create_all(engine)
    yield sessionmaker(engine, expire_on_commit=False)
    if url:
        with root_engine.begin() as connection:
            connection.execute(text(f'DROP SCHEMA "{schema}" CASCADE'))
        root_engine.dispose()
    else:
        engine.dispose()


@pytest.fixture
def client(sessions):
    def override():
        with sessions() as session:
            yield session

    app.dependency_overrides[get_session] = override
    with TestClient(app) as client:
        yield client
    app.dependency_overrides.clear()
