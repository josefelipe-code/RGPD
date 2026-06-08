<?php

namespace App\Services;

use App\Models\MailAccount;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Exceptions\AuthFailedException;
use Webklex\PHPIMAP\Exceptions\ConnectionFailedException;

/**
 * Manages runtime mail configuration from MailAccount instances.
 *
 * Registers dynamic SMTP mailers so Laravel's Mail facade can send
 * through user-configured accounts without hardcoding providers.
 */
class MailAccountConfigService
{
    /**
     * Register a dynamic SMTP mailer at runtime from a MailAccount.
     *
     * Returns a stable mailer name derived from the account ID so
     * repeated calls for the same account reuse the same config.
     */
    public function registerSmtpMailer(MailAccount $account): string
    {
        if (! $account->exists) {
            throw new InvalidArgumentException(
                'Cannot register SMTP mailer for an unpersisted MailAccount. Save the model first.'
            );
        }

        $mailerName = "mail_account_{$account->id}";

        // Only register once per request lifecycle
        if (Config::has("mail.mailers.{$mailerName}")) {
            return $mailerName;
        }

        Config::set("mail.mailers.{$mailerName}", $account->smtpConfig());

        return $mailerName;
    }

    /**
     * Get IMAP configuration array for a MailAccount.
     *
     * This is a convenience wrapper — the actual config lives on the model.
     *
     * @return array<string, mixed>
     */
    public function imapConfig(MailAccount $account): array
    {
        return $account->imapConfig();
    }

    /**
     * Verify SMTP connectivity by performing a real connection + auth handshake.
     *
     * Uses Symfony's EsmtpTransport start()/stop() to establish a socket
     * connection, perform EHLO, and authenticate — without sending any mail.
     *
     * @param  array<string, mixed>  $smtpConfig  From MailAccount::smtpConfig()
     *
     * @throws \RuntimeException with a user-friendly message on failure
     */
    public function verifySmtpConnection(array $smtpConfig): void
    {
        $encryption = $smtpConfig['encryption'] ?? null;
        $tls = $encryption === 'ssl';

        /** @var EsmtpTransport $transport */
        $transport = new EsmtpTransport(
            $smtpConfig['host'],
            (int) $smtpConfig['port'],
            $tls,
        );

        $transport->setUsername($smtpConfig['username'] ?? '');
        $transport->setPassword($smtpConfig['password'] ?? '');

        try {
            $transport->start();
            $transport->stop();
        } catch (TransportException $e) {
            $message = $this->humanizeSmtpError($e);

            throw new \RuntimeException($message, 0, $e);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                __('SMTP connection failed: :error', ['error' => $e->getMessage()]),
                0,
                $e,
            );
        }
    }

    /**
     * Verify IMAP connectivity by performing a real connection + login.
     *
     * Uses webklex/php-imap ClientManager to create a temporary client,
     * connect (which includes authentication), then disconnect.
     *
     * @param  array<string, mixed>  $imapConfig  From MailAccount::imapConfig()
     *
     * @throws \RuntimeException with a user-friendly message on failure
     */
    public function verifyImapConnection(array $imapConfig): void
    {
        $manager = new ClientManager([]);

        $client = $manager->make([
            'host' => $imapConfig['host'],
            'port' => $imapConfig['port'],
            'protocol' => $imapConfig['protocol'] ?? 'imap',
            'encryption' => $imapConfig['encryption'] ?? 'ssl',
            'validate_cert' => $imapConfig['validate_cert'] ?? true,
            'username' => $imapConfig['username'] ?? '',
            'password' => $imapConfig['password'] ?? '',
            'authentication' => $imapConfig['authentication'] ?? null,
            'timeout' => $imapConfig['timeout'] ?? 30,
        ]);

        try {
            $client->connect();
            $client->disconnect();
        } catch (ConnectionFailedException|AuthFailedException $e) {
            $message = $this->humanizeImapError($e);

            throw new \RuntimeException($message, 0, $e);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                __('IMAP connection failed: :error', ['error' => $e->getMessage()]),
                0,
                $e,
            );
        }
    }

    /**
     * Convert a Symfony SMTP TransportException into a user-friendly message.
     */
    private function humanizeSmtpError(TransportException $e): string
    {
        $message = $e->getMessage();

        if (str_contains(strtolower($message), 'connection') || str_contains(strtolower($message), 'connect')) {
            return __('No se pudo conectar al servidor SMTP. Verificá host y puerto.');
        }

        if (str_contains(strtolower($message), 'auth') || str_contains(strtolower($message), 'login') || str_contains(strtolower($message), 'credentials')) {
            return __('Autenticación SMTP fallida. Verificá usuario y contraseña.');
        }

        if (str_contains(strtolower($message), 'tls') || str_contains(strtolower($message), 'ssl') || str_contains(strtolower($message), 'certificate')) {
            return __('Error de encriptación SMTP. Verificá el tipo de encriptación (SSL/TLS).');
        }

        return __('Error de conexión SMTP: :error', ['error' => $e->getMessage()]);
    }

    /**
     * Convert a webklex IMAP exception into a user-friendly message.
     */
    private function humanizeImapError(\Throwable $e): string
    {
        $message = $e->getMessage();

        if (str_contains(strtolower($message), 'auth') || str_contains(strtolower($message), 'login') || str_contains(strtolower($message), 'credentials')) {
            return __('Autenticación IMAP fallida. Verificá usuario y contraseña.');
        }

        if (str_contains(strtolower($message), 'connection') || str_contains(strtolower($message), 'connect') || str_contains(strtolower($message), 'refused')) {
            return __('No se pudo conectar al servidor IMAP. Verificá host y puerto.');
        }

        if (str_contains(strtolower($message), 'tls') || str_contains(strtolower($message), 'ssl') || str_contains(strtolower($message), 'certificate')) {
            return __('Error de encriptación IMAP. Verificá el tipo de encriptación (SSL/TLS).');
        }

        return __('Error de conexión IMAP: :error', ['error' => $e->getMessage()]);
    }
}
