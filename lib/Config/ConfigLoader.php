<?php

declare(strict_types=1);

namespace Digitalist\OffsiteBackup\Config;

use Digitalist\OffsiteBackup\Environment;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/** Resolves settings from package defaults, then offsite-backup.yml, then the environment. */
final class ConfigLoader
{
    public const FILE_NAME = 'offsite-backup.yml';

    public function __construct(
        private readonly string $projectRoot,
        private readonly Environment $env,
        private readonly ?string $configPath = null,
    ) {}

    public function load(): Config
    {
        $resolved = $this->resolve();
        if ($resolved->missing !== []) {
            $settings = Settings::all();
            $lines = array_map(static fn (string $key): string => sprintf('%s (%s)', $key, $settings[$key]['env']), $resolved->missing);
            throw new ConfigException('Missing required configuration: ' . implode(', ', $lines) . '. Set them in ' . self::FILE_NAME . ' or as environment variables; secrets are environment only.');
        }
        return Config::fromResolved($resolved);
    }

    public function resolve(): Resolved
    {
        $settings = Settings::all();
        [$file, $fileValues] = $this->readFile();

        $values = [];
        $sources = [];
        $missing = [];
        foreach ($settings as $key => $def) {
            $value = $def['default'];
            $source = 'default';
            if (array_key_exists($key, $fileValues)) {
                $value = self::coerce($key, $fileValues[$key], $def['type'], 'file');
                $source = 'file';
            }
            $envValue = $this->env->get($def['env']);
            if ($envValue !== null) {
                $value = self::coerce($key, $envValue, $def['type'], 'env');
                $source = 'env';
            }
            $values[$key] = $value;
            $sources[$key] = $source;
        }

        // Platform-provided fallbacks. PLATFORM_ENVIRONMENT is an id such as
        // "main-bvxea6i" on Upsun; the branch name is what people expect.
        foreach (['project' => ['PLATFORM_PROJECT'], 'environment' => ['PLATFORM_BRANCH', 'PLATFORM_ENVIRONMENT']] as $key => $platformVars) {
            foreach ($platformVars as $platformVar) {
                if ($values[$key] === null && $this->env->get($platformVar) !== null) {
                    $values[$key] = $this->env->get($platformVar);
                    $sources[$key] = 'env';
                }
            }
        }

        if (is_string($values['s3.host'])) {
            $values['s3.host'] = self::normaliseHost($values['s3.host']);
        }
        $values['local_dir'] = self::absolute($this->projectRoot, (string) $values['local_dir']);
        $values['restic.cache_dir'] = self::absolute($this->projectRoot, strtr((string) $values['restic.cache_dir'], ['{local_dir}' => $values['local_dir']]));
        $values['restic.host'] = strtr((string) $values['restic.host'], ['{project}' => (string) $values['project'], '{environment}' => (string) $values['environment']]);

        foreach ($settings as $key => $def) {
            if ($def['required'] && ($values[$key] === null || $values[$key] === '')) {
                $missing[] = $key;
            }
        }

        return new Resolved($values, $sources, $missing, $file, $this->projectRoot);
    }

    /** @return array{0: ?string, 1: array<string,mixed>} */
    private function readFile(): array
    {
        $explicit = $this->configPath ?? $this->env->get('OFFSITE_BACKUP_CONFIG');
        $path = $explicit ?? $this->projectRoot . '/' . self::FILE_NAME;
        if (!is_file($path)) {
            if ($explicit !== null) {
                throw new ConfigException("Configuration file not found: $path");
            }
            return [null, []];
        }
        try {
            $parsed = Yaml::parseFile($path);
        } catch (ParseException $e) {
            throw new ConfigException("Cannot parse $path: " . $e->getMessage(), 0, $e);
        }
        if ($parsed === null) {
            return [$path, []];
        }
        if (!is_array($parsed)) {
            throw new ConfigException("$path must contain a YAML mapping");
        }
        $flat = self::flatten($parsed);
        $known = Settings::all();
        foreach (array_keys($flat) as $key) {
            if (in_array($key, Settings::SECRET_KEYS, true)) {
                throw new ConfigException("$path contains the secret '$key'. Secrets must be environment variables (" . $known[$key]['env'] . '), never in the repository.');
            }
            if (!isset($known[$key])) {
                throw new ConfigException("$path contains unknown setting '$key'");
            }
        }
        return [$path, $flat];
    }

    /**
     * Flatten nested mappings to dot keys; lists stay as values.
     * @param array<mixed> $data
     * @return array<string,mixed>
     */
    private static function flatten(array $data, string $prefix = ''): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            $key = $prefix === '' ? (string) $k : $prefix . '.' . $k;
            if (is_array($v) && $v !== [] && !array_is_list($v)) {
                $out += self::flatten($v, $key);
            } else {
                $out[$key] = $v;
            }
        }
        return $out;
    }

    private static function coerce(string $key, mixed $raw, string $type, string $source): mixed
    {
        switch ($type) {
            case 'list':
                if (is_string($raw)) {
                    return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $s): bool => $s !== ''));
                }
                if (is_array($raw)) {
                    return array_values(array_map(static fn ($v): string => (string) $v, $raw));
                }
                break;
            case 'int':
                if (is_int($raw) || (is_string($raw) && preg_match('/^\d+$/', $raw) === 1)) {
                    return (int) $raw;
                }
                break;
            case 'bool':
                if (is_bool($raw)) {
                    return $raw;
                }
                if (is_string($raw) && in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true)) {
                    return true;
                }
                if (is_string($raw) && in_array(strtolower($raw), ['0', 'false', 'no', 'off'], true)) {
                    return false;
                }
                break;
            default:
                if (is_scalar($raw)) {
                    return (string) $raw;
                }
        }
        throw new ConfigException(sprintf("Setting '%s' from %s must be of type %s", $key, $source, $type));
    }

    public static function normaliseHost(string $host): string
    {
        $host = rtrim(trim($host), '/');
        if (!preg_match('#^https?://#i', $host)) {
            $host = 'https://' . $host;
        }
        $parts = parse_url($host);
        if ($parts === false || !isset($parts['host'])) {
            throw new ConfigException("s3.host is not a valid URL: $host");
        }
        $scheme = strtolower($parts['scheme'] ?? 'https');
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        return $scheme . '://' . strtolower($parts['host']) . $port;
    }

    private static function absolute(string $root, string $path): string
    {
        return str_starts_with($path, '/') ? $path : $root . '/' . $path;
    }
}
