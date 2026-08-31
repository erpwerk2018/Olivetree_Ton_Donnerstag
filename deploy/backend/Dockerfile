FROM debian:trixie-slim

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update && apt-get install -y --no-install-recommends \
        python3 python3-pip \
        ffmpeg icecast2 \
        libsndfile1 ca-certificates \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY requirements.txt /app/
RUN pip3 install --break-system-packages --no-cache-dir -r requirements.txt

COPY generator.py streamer.py icecast.xml entrypoint.sh /app/
COPY assets/ /app/assets/
RUN chmod +x /app/entrypoint.sh

VOLUME /data
EXPOSE 8000

ENTRYPOINT ["/app/entrypoint.sh"]
