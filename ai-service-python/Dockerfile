FROM python:3.14.6-slim

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1

WORKDIR /app
RUN apt-get update \
    && apt-get install --no-install-recommends --yes ffmpeg fonts-dejavu-core \
    && rm -rf /var/lib/apt/lists/*
COPY requirements.lock ./requirements.lock
RUN python -m pip install --no-cache-dir -r requirements.lock
COPY app ./app
RUN mkdir -p /app/artifacts

EXPOSE 8001
HEALTHCHECK --interval=10s --timeout=3s --retries=6 \
  CMD python -c "import urllib.request; urllib.request.urlopen('http://127.0.0.1:8001/live', timeout=2)"

CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8001"]
