FROM debian:12-slim

RUN apt-get update \
    && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
       ca-certificates git php8.2-cli php8.2-curl php8.2-gd php8.2-imap php8.2-mbstring php8.2-mysql php8.2-xml php8.2-zip unzip \
    && rm -rf /var/lib/apt/lists/*

COPY run.sh /usr/local/bin/mailbox-integration-run
COPY integration.php /opt/mailbox-integration.php
RUN chmod 0755 /usr/local/bin/mailbox-integration-run

ENTRYPOINT ["mailbox-integration-run"]
