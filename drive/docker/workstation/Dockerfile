FROM ubuntu:24.04

ENV DEBIAN_FRONTEND=noninteractive \
    LANG=C.UTF-8 \
    LC_ALL=C.UTF-8 \
    TZ=America/Mexico_City

RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      ca-certificates curl dbus-x11 fonts-dejavu-core fonts-liberation fonts-noto-core \
      git gnupg libreoffice libreoffice-gtk3 novnc python3-websockify tzdata \
      tigervnc-standalone-server tigervnc-tools unzip wget xfce4 xfce4-terminal xterm xdg-utils \
      audacity dia dia-shapes ffmpeg file-roller flameshot handbrake handbrake-cli \
      kdenlive kolourpaint mediainfo p7zip-full socat soundconverter unrar x11-utils xclip zip \
      pipewire pipewire-pulse wireplumber pipewire-audio-client-libraries \
      pipewire-module-xrdp pavucontrol pulseaudio-utils alsa-utils xrdp xorgxrdp \
 && install -d -m 0755 /etc/apt/keyrings \
 && curl -fsSL https://dl.google.com/linux/linux_signing_key.pub \
      | gpg --dearmor -o /etc/apt/keyrings/google-chrome.gpg \
 && echo "deb [arch=amd64 signed-by=/etc/apt/keyrings/google-chrome.gpg] https://dl.google.com/linux/chrome/deb/ stable main" \
      > /etc/apt/sources.list.d/google-chrome.list \
 && apt-get update \
 && apt-get install -y --no-install-recommends google-chrome-stable \
 && curl -fsSL "https://awscli.amazonaws.com/awscli-exe-linux-x86_64.zip" -o /tmp/awscliv2.zip \
 && unzip -q /tmp/awscliv2.zip -d /tmp \
 && /tmp/aws/install \
 && sed -i 's/QUANTUMVAL=2048/QUANTUMVAL=1024/' /usr/libexec/pipewire-module-xrdp/load_pw_modules.sh \
 && rm -rf /tmp/aws /tmp/awscliv2.zip /var/lib/apt/lists/*

RUN groupadd --gid 10001 arcade \
 && useradd --create-home --uid 10001 --gid 10001 --shell /bin/bash arcade \
 && mkdir -p /workspace /home/arcade/.vnc /home/arcade/Projects /home/arcade/Downloads \
 && chown -R arcade:arcade /workspace /home/arcade

COPY entrypoint.sh /usr/local/bin/arcadecloud-workstation-entrypoint
COPY arcadecloud-chrome /usr/local/bin/arcadecloud-chrome
COPY arcadecloud-office-document /usr/local/bin/arcadecloud-office-document
COPY arcadecloud-chrome-helper.desktop /usr/share/xfce4/helpers/arcadecloud-chrome.desktop
COPY arcadecloud-chrome.desktop /usr/share/applications/arcadecloud-chrome.desktop
RUN chmod 0755 /usr/local/bin/arcadecloud-workstation-entrypoint /usr/local/bin/arcadecloud-chrome /usr/local/bin/arcadecloud-office-document \
 && chmod 0644 /usr/share/xfce4/helpers/arcadecloud-chrome.desktop /usr/share/applications/arcadecloud-chrome.desktop

WORKDIR /home/arcade

EXPOSE 6080 3389
ENTRYPOINT ["/usr/local/bin/arcadecloud-workstation-entrypoint"]
