<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Backup;

/**
 * Validates a gzipped SQL dump in one streaming pass: gzip integrity, minimum
 * size, at least one CREATE TABLE, and the "Dump completed on" trailer that
 * mysqldump writes only after a complete run.
 */
final class DumpChecker
{
    private const TRAILER = '-- Dump completed on';
    private const NEEDLE = 'CREATE TABLE';
    private const CHUNK = 1 << 20;

    public function check(string $gzPath, int $minBytes): DumpCheckResult
    {
        $compressed = @filesize($gzPath);
        if ($compressed === false) {
            throw new DumpCheckException("Dump file not found: $gzPath");
        }
        if ($compressed < $minBytes) {
            throw new DumpCheckException(sprintf('Dump %s is %d bytes, smaller than the required %d bytes', $gzPath, $compressed, $minBytes));
        }

        $handle = @fopen($gzPath, 'rb');
        if ($handle === false) {
            throw new DumpCheckException("Cannot open $gzPath");
        }
        $inflate = inflate_init(ZLIB_ENCODING_GZIP);
        if ($inflate === false) {
            throw new DumpCheckException('Cannot initialise gzip decoding');
        }
        $uncompressed = 0;
        $createTables = 0;
        // Bytes carried from the previous chunk so a needle split across chunks is seen once.
        $carry = '';
        $tail = '';
        try {
            while (!feof($handle)) {
                $raw = fread($handle, self::CHUNK);
                if ($raw === false) {
                    throw new DumpCheckException("Read error in $gzPath");
                }
                if ($raw === '') {
                    continue;
                }
                if (inflate_get_status($inflate) === ZLIB_STREAM_END) {
                    throw new DumpCheckException("gzip stream in $gzPath is followed by trailing data (corrupt archive?)");
                }
                $chunk = @inflate_add($inflate, $raw);
                if ($chunk === false) {
                    throw new DumpCheckException("gzip data error in $gzPath (corrupt archive)");
                }
                if ($chunk === '') {
                    continue;
                }
                $uncompressed += strlen($chunk);
                $createTables += substr_count($carry . $chunk, self::NEEDLE);
                $carry = substr($chunk, -(strlen(self::NEEDLE) - 1));
                $tail = substr($tail . $chunk, -4096);
            }
            if (inflate_get_status($inflate) !== ZLIB_STREAM_END) {
                throw new DumpCheckException("gzip stream in $gzPath ended early (truncated archive)");
            }
        } finally {
            fclose($handle);
        }
        if ($createTables === 0) {
            throw new DumpCheckException("Dump $gzPath contains no CREATE TABLE statement");
        }
        if (!str_contains($tail, self::TRAILER)) {
            throw new DumpCheckException("Dump $gzPath does not end with the '" . self::TRAILER . "' trailer; the dump was interrupted");
        }
        return new DumpCheckResult($compressed, $uncompressed, $createTables);
    }
}
