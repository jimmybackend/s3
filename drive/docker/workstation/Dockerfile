FROM ubuntu:24.04

ENV DEBIAN_FRONTEND=noninteractive \
    LANG=C.UTF-8 \
    LC_ALL=C.UTF-8

RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      ca-certificates dbus-x11 fonts-dejavu-core fonts-liberation fonts-noto-core \
      libreoffice libreoffice-gtk3 novnc python3-websockify tigervnc-standalone-server \
      tigervnc-tools xfce4 xfce4-terminal xterm \
 && rm -rf /var/lib/apt/lists/*

RUN groupadd --gid 10001 arcade \
 && useradd --create-home --uid 10001 --gid 10001 --shell /bin/bash arcade \
 && mkdir -p /workspace /home/arcade/.vnc \
 && chown -R arcade:arcade /workspace /home/arcade

COPY entrypoint.sh /usr/local/bin/arcadecloud-workstation-entrypoint
RUN chmod 0755 /usr/local/bin/arcadecloud-workstation-entrypoint

USER arcade
WORKDIR /workspace

EXPOSE 6080
ENTRYPOINT ["/usr/local/bin/arcadecloud-workstation-entrypoint"]
