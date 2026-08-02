FROM debian:12-slim

RUN apt-get update \
    && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends dovecot-imapd openssl ca-certificates \
    && rm -rf /var/lib/apt/lists/* \
    && groupadd --gid 5000 vmail \
    && useradd --uid 5000 --gid vmail --home-dir /var/mail/test@example.test --create-home vmail

COPY dovecot.conf /etc/dovecot/dovecot.conf
COPY users /etc/dovecot/users
COPY seed.eml /var/mail/test@example.test/Maildir/new/1700000000.mailbox-test

RUN mkdir -p /var/mail/test@example.test/Maildir/cur /var/mail/test@example.test/Maildir/tmp \
    && chown -R vmail:vmail /var/mail/test@example.test \
    && chmod 0600 /etc/dovecot/users

EXPOSE 993
CMD ["dovecot", "-F"]
