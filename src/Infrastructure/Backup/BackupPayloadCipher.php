<?php

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Backup;

/** Chunked authenticated backup encryption using libsodium secretstream and Argon2id. */
final class BackupPayloadCipher
{
    private const string MAGIC   = "TDENC01\0";
    private const int CHUNK_SIZE = 65536;

    public static function encrypt(string $source, string $target, string $passphrase): void
    {
        self::assertPassphrase($passphrase);
        $salt             = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $key              = sodium_crypto_pwhash(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES, $passphrase, $salt, SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE, SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
        $input            = @fopen($source, 'rb');
        $output           = @fopen($target, 'xb');
        if (!is_resource($input) || !is_resource($output)) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            sodium_memzero($key);
            throw new BackupException('Unable to open private files for encrypted backup creation.');
        }
        try {
            self::writeAll($output, self::MAGIC . $salt . $header);
            $plain = fread($input, self::CHUNK_SIZE);
            if (!is_string($plain) || $plain === '') {
                throw new BackupException('Database snapshot is empty or could not be read for encryption.');
            }
            while (true) {
                $next = fread($input, self::CHUNK_SIZE);
                if ($next === false) {
                    throw new BackupException('Unable to read the database snapshot for encryption.');
                }
                $final  = $next === '' && feof($input);
                $cipher = sodium_crypto_secretstream_xchacha20poly1305_push($state, $plain, '', $final ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE);
                self::writeAll($output, pack('N', strlen($cipher)) . $cipher);
                if ($final) {
                    break;
                }
                if ($next === '') {
                    throw new BackupException('Database snapshot read stalled during encryption.');
                }
                $plain = $next;
            }
            if (!fflush($output) || !chmod($target, 0o600)) {
                throw new BackupException('Unable to finalize encrypted backup payload permissions.');
            }
        } catch (\Throwable $error) {
            if (is_file($target)) {
                @unlink($target);
            }
            if ($error instanceof BackupException) {
                throw $error;
            }
            throw new BackupException('Encrypted backup creation failed safely.', is_int($error->getCode()) ? $error->getCode() : 0, $error);
        } finally {
            fclose($input);
            fclose($output);
            sodium_memzero($key);
        }
    }

    public static function decrypt(string $source, string $target, string $passphrase): void
    {
        self::assertPassphrase($passphrase);
        $input = @fopen($source, 'rb');
        if (!is_resource($input)) {
            throw new BackupException('Unable to open encrypted backup payload.');
        }
        $output = @fopen($target, 'xb');
        if (!is_resource($output)) {
            fclose($input);
            throw new BackupException('Unable to create private decrypted staging payload.');
        }
        $key      = '';
        $complete = false;
        try {
            $prefix = self::readExact($input, strlen(self::MAGIC) + SODIUM_CRYPTO_PWHASH_SALTBYTES + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
            if (!str_starts_with($prefix, self::MAGIC)) {
                throw new BackupException('Encrypted backup payload has an unsupported format.');
            }
            $offset = strlen(self::MAGIC);
            $salt   = substr($prefix, $offset, SODIUM_CRYPTO_PWHASH_SALTBYTES);
            $offset += SODIUM_CRYPTO_PWHASH_SALTBYTES;
            $header   = substr($prefix, $offset, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
            $key      = sodium_crypto_pwhash(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES, $passphrase, $salt, SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE, SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
            $state    = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
            $finished = false;
            while (!$finished) {
                $lengthData = fread($input, 4);
                if ($lengthData === false || strlen($lengthData) !== 4) {
                    throw new BackupException('Encrypted backup is truncated before its final authentication tag.');
                }
                $lengthData = unpack('Nlength', $lengthData);
                $length     = is_array($lengthData) ? ($lengthData['length'] ?? null) : null;
                if (!is_int($length) || $length < SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES || $length > 65536 + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES) {
                    throw new BackupException('Encrypted backup contains an invalid chunk length.');
                }
                $cipher = self::readExact($input, $length);
                $pulled = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $cipher);
                if ($pulled === false) {
                    throw new BackupException('Encrypted backup authentication failed (wrong passphrase or modified payload).');
                }
                [$plain, $tag] = $pulled;
                self::writeAll($output, $plain);
                $finished = $tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
                if (!$finished && $tag !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE) {
                    throw new BackupException('Encrypted backup contains an unsupported stream tag.');
                }
            }
            if (fread($input, 1) !== '' || !fflush($output) || !chmod($target, 0o600)) {
                throw new BackupException('Encrypted backup has trailing data or could not be finalized safely.');
            }
            $complete = true;
        } catch (\Throwable $error) {
            if ($error instanceof BackupException) {
                throw $error;
            }
            throw new BackupException('Encrypted backup authentication or decryption failed.', is_int($error->getCode()) ? $error->getCode() : 0, $error);
        } finally {
            fclose($input);
            fclose($output);
            if ($key !== '') {
                sodium_memzero($key);
            }
            if (is_file($target)) {
                chmod($target, 0o600);
                if (!$complete) {
                    @unlink($target);
                }
            }
        }
    }

    private static function assertPassphrase(string $passphrase): void
    {
        if (strlen($passphrase) < 16 || strlen($passphrase) > 4096) {
            throw new BackupException('Backup passphrase must contain between 16 and 4096 bytes.');
        }
    }

    /** @param resource $stream */
    private static function writeAll($stream, string $data): void
    {
        $offset = 0;
        while ($offset < strlen($data)) {
            $written = fwrite($stream, substr($data, $offset));
            if ($written === false || $written === 0) {
                throw new BackupException('Unable to write backup payload completely.');
            }
            $offset += $written;
        }
    }

    /** @param resource $stream */
    private static function readExact($stream, int $length): string
    {
        $data = '';
        while (strlen($data) < $length && !feof($stream)) {
            $part = fread($stream, max(1, $length - strlen($data)));
            if ($part === false || $part === '') {
                break;
            }
            $data .= $part;
        }
        if (strlen($data) !== $length) {
            throw new BackupException('Encrypted backup is truncated.');
        }
        return $data;
    }
}
