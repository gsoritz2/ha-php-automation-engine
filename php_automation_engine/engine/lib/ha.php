<?php
declare(strict_types=1);

/**
 * Duenne Huelle um die Home-Assistant-REST-API.
 *
 * Im Add-on-Betrieb liefert der Supervisor die Basis-URL und das Token:
 *   HA_API_BASE = http://supervisor/core/api
 *   SUPERVISOR_TOKEN = <wird automatisch gesetzt, wenn homeassistant_api: true>
 *
 * Fuer Tests/andere Installationen koennen beide per Umgebungsvariable gesetzt werden.
 */
final class HaApi
{
    private string $base;
    private string $token;
    private int $timeout;

    /** @var array<string,array|null> */
    private array $stateCache = [];
    private float $stateCacheAt = 0.0;
    private float $stateCacheTtl = 1.0;

    public function __construct(?string $base = null, ?string $token = null, int $timeout = 10)
    {
        $this->base = rtrim($base ?? (getenv('HA_API_BASE') ?: 'http://supervisor/core/api'), '/');
        $this->token = $token ?? (getenv('SUPERVISOR_TOKEN') ?: '');
        $this->timeout = $timeout;

        if ($this->token === '') {
            Log::add('warning', 'Kein SUPERVISOR_TOKEN gesetzt - API-Aufrufe werden mit 401 fehlschlagen.');
        }
    }

    public function base(): string
    {
        return $this->base;
    }

    /**
     * @param array<string,mixed>|null $body
     * @return mixed
     */
    public function request(string $method, string $path, ?array $body = null, bool $throwOnError = true)
    {
        $ch = curl_init($this->base . $path);
        if ($ch === false) {
            throw new RuntimeException('curl_init fehlgeschlagen');
        }
        $headers = [
            'Authorization: Bearer ' . $this->token,
            'Content-Type: application/json',
            'Accept: application/json',
        ];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException("HA-API $method $path: $err");
        }
        if ($code === 404 && !$throwOnError) {
            return null;
        }
        if ($code >= 400) {
            $msg = "HA-API $method $path -> HTTP $code: " . substr((string) $raw, 0, 300);
            if ($throwOnError) {
                throw new RuntimeException($msg);
            }
            Log::add('debug', $msg);
            return null;
        }
        if ($raw === '') {
            return null;
        }
        $decoded = json_decode((string) $raw, true);
        return $decoded ?? $raw;
    }

    /** @return array<int,array<string,mixed>> */
    public function getStates(): array
    {
        $r = $this->request('GET', '/states');
        return is_array($r) ? $r : [];
    }

    /** @return array<string,mixed>|null */
    public function getState(string $entityId, bool $useCache = true): ?array
    {
        if ($useCache && (microtime(true) - $this->stateCacheAt) < $this->stateCacheTtl
            && array_key_exists($entityId, $this->stateCache)) {
            return $this->stateCache[$entityId];
        }
        $r = $this->request('GET', '/states/' . rawurlencode($entityId), null, false);
        $state = is_array($r) ? $r : null;
        if (!$useCache || (microtime(true) - $this->stateCacheAt) >= $this->stateCacheTtl) {
            $this->stateCache = [];
            $this->stateCacheAt = microtime(true);
        }
        $this->stateCache[$entityId] = $state;
        return $state;
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $target
     */
    public function callService(string $domain, string $service, array $data = [], array $target = []): mixed
    {
        $payload = $data;
        foreach ($target as $k => $v) {
            $payload[$k] = $v;
        }
        return $this->request('POST', "/services/$domain/$service", $payload);
    }

    /** @param array<string,mixed> $attributes */
    public function setState(string $entityId, mixed $state, array $attributes = []): mixed
    {
        $payload = ['state' => is_bool($state) ? ($state ? 'on' : 'off') : $state];
        if ($attributes !== []) {
            $payload['attributes'] = $attributes;
        }
        $this->stateCache[$entityId] = null;
        return $this->request('POST', '/states/' . rawurlencode($entityId), $payload);
    }

    /** @param array<string,mixed> $data */
    public function fireEvent(string $eventType, array $data = []): mixed
    {
        return $this->request('POST', '/events/' . rawurlencode($eventType), $data);
    }

    public function renderTemplate(string $template): ?string
    {
        $r = $this->request('POST', '/template', ['template' => $template]);
        return is_string($r) ? $r : null;
    }

    /** @return array<string,mixed> */
    public function config(): array
    {
        $r = $this->request('GET', '/config', null, false);
        return is_array($r) ? $r : [];
    }

    public function invalidateStateCache(): void
    {
        $this->stateCache = [];
        $this->stateCacheAt = 0.0;
    }
}
