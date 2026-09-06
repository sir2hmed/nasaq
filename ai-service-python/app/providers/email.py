"""Deterministic demo email and real SMTP delivery adapters."""

import hashlib
import smtplib
import ssl
from email.message import EmailMessage

from app.providers.contracts import EmailDeliveryResult, EmailMessageInput
from app.providers.errors import (
    InvalidProviderResponse,
    ProviderAuthenticationError,
    TransientProviderError,
)
from app.services.integrations import IntegrationCredentialClient


class DemoEmailProvider:
    provider_name = "demo-smtp"

    def send(self, message: EmailMessageInput, user_id: str | None) -> EmailDeliveryResult:
        token = hashlib.sha256(message.idempotency_key.encode()).hexdigest()[:20]
        return EmailDeliveryResult(
            provider=self.provider_name,
            message_id=f"demo-{token}",
            recipients=message.recipients,
            simulated=True,
        )


class SmtpEmailProvider:
    provider_name = "smtp"

    def __init__(
        self,
        credentials: IntegrationCredentialClient,
        timeout_seconds: float = 30.0,
        smtp_factory=None,
        smtp_ssl_factory=None,
    ) -> None:
        self.credentials = credentials
        self.timeout_seconds = timeout_seconds
        self.smtp_factory = smtp_factory or smtplib.SMTP
        self.smtp_ssl_factory = smtp_ssl_factory or smtplib.SMTP_SSL

    def send(self, message: EmailMessageInput, user_id: str | None) -> EmailDeliveryResult:
        credentials = self.credentials.get(user_id, self.provider_name)
        try:
            host = str(credentials["host"])
            port = int(credentials["port"])
            from_email = str(credentials["from_email"])
            encryption = str(credentials.get("encryption", "tls"))
        except (KeyError, TypeError, ValueError) as exc:
            raise InvalidProviderResponse(self.provider_name) from exc

        email = EmailMessage()
        email["From"] = f"{credentials.get('from_name', 'Nasaq AI')} <{from_email}>"
        email["To"] = ", ".join(message.recipients)
        email["Subject"] = message.subject
        email["X-Nasaq-Idempotency-Key"] = message.idempotency_key
        email.set_content(message.text_body)
        username = credentials.get("username")
        password = credentials.get("password")
        try:
            if encryption == "ssl":
                server = self.smtp_ssl_factory(
                    host,
                    port,
                    timeout=self.timeout_seconds,
                    context=ssl.create_default_context(),
                )
            else:
                server = self.smtp_factory(host, port, timeout=self.timeout_seconds)
            with server:
                if encryption == "tls":
                    server.starttls(context=ssl.create_default_context())
                if username:
                    server.login(str(username), str(password or ""))
                refused = server.send_message(email)
                if refused:
                    raise InvalidProviderResponse(self.provider_name)
        except smtplib.SMTPAuthenticationError as exc:
            raise ProviderAuthenticationError(self.provider_name) from exc
        except (smtplib.SMTPException, OSError, TimeoutError) as exc:
            raise TransientProviderError(self.provider_name) from exc

        message_id = hashlib.sha256(f"{message.idempotency_key}:{from_email}".encode()).hexdigest()[
            :32
        ]
        return EmailDeliveryResult(
            provider=self.provider_name,
            message_id=message_id,
            recipients=message.recipients,
        )
